<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Guard as SanctumGuard;
use Modules\AI\Authorization\AIResourceAccess;
use Modules\AI\Models\AIAccessPolicy;
use Modules\AI\Models\AIActivityLog;
use Modules\AI\Models\AISetting;
use Modules\AI\Models\AIConversation;
use Modules\AI\Seeders\AIAccessPolicySeeder;
use Modules\Audit\Models\AuditLog;
use Modules\Chat\Models\Message;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Meetings\Models\Meeting;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Actions\AddProjectMemberAction;
use Modules\Projects\Actions\AssignProjectTeamAction;
use Modules\Projects\Actions\CreateProjectAction;
use Modules\Projects\Actions\DetachProjectTeamAction;
use Modules\Projects\Actions\RemoveProjectMemberAction;
use Modules\Projects\Queries\EligibleProjectTeamMembersQuery;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Actions\AssignTaskAssigneesAction;
use Modules\Tasks\Actions\UpdateAssignedTaskStatusAction;
use Modules\Teams\Models\Team;
use Modules\Teams\Actions\AddTeamMemberAction;
use Modules\Teams\Actions\RemoveTeamMemberAction;
use Modules\Teams\Models\TeamMember;
use Modules\AccessControl\Seeders\AccessControlSeeder;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AccessControlSeeder::class);
    $this->administrator = User::factory()->create();
    $this->administrator->assignRole('Administrator');
});

it('keeps team assignment separate from explicit project membership', function () {
    $project = Project::factory()->create();
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $team = Team::factory()->create();
    $teamUser = User::factory()->create();
    app(AddTeamMemberAction::class)->execute($team, $teamUser, $this->administrator);

    app(AssignProjectTeamAction::class)->execute($project, $team, $projectManager);

    expect($project->teams()->whereKey($team->getKey())->exists())->toBeTrue()
        ->and($project->members()->whereKey($teamUser->getKey())->exists())->toBeFalse();
});

it('enforces unique active team names and allows a soft-deleted name to be reused', function () {
    $team = Team::factory()->create(['name' => 'Research']);

    expect(fn () => Team::factory()->create(['name' => 'Research']))
        ->toThrow(\Illuminate\Database\QueryException::class);

    $team->delete();

    expect(Team::factory()->create(['name' => 'Research'])->exists)->toBeTrue();

    Team::factory()->create(['name' => 'Dormant', 'is_active' => false]);
    expect(Team::factory()->create(['name' => 'Dormant', 'is_active' => true])->exists)->toBeTrue();
});

it('creates a project and its creator membership atomically', function () {
    $creator = User::factory()->create();
    $creator->assignRole('Project Manager');

    $project = app(CreateProjectAction::class)->execute($creator, ['name' => 'Created project']);

    expect($creator->hasActiveProjectMembership((int) $project->getKey()))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'project.create')
            ->where('subject_id', $project->getKey())->exists())->toBeTrue();
});

it('denies project, task, and chat access to team members without explicit project membership', function () {
    $project = Project::factory()->create();
    $outsider = User::factory()->create();
    $outsider->assignRole('Team Member');
    $task = Task::factory()->create(['project_id' => $project->getKey()]);
    $message = Message::factory()->create(['project_id' => $project->getKey()]);

    expect(Gate::forUser($outsider)->allows('view', $project))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('view', $task))->toBeFalse()
        ->and($message->canBeReadBy($outsider))->toBeFalse();

    expect(fn () => Message::sendForProjectMember($outsider, $project, 'Not allowed'))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('allows an active project member to access project chat and their assigned tasks', function () {
    $project = Project::factory()->create();
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    app(AddProjectMemberAction::class)->execute($project, $member, $projectManager);

    $task = Task::factory()->create(['project_id' => $project->getKey()]);
    app(AssignTaskAssigneesAction::class)->execute($task, [$member->getKey()], $projectManager);
    $message = Message::sendForProjectMember($member, $project, 'Project update');

    expect(Gate::forUser($member)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $task))->toBeTrue()
        ->and($message->canBeReadBy($member))->toBeTrue();
});

it('limits project file metadata and uploads to users with active project access', function () {
    $project = Project::factory()->create();
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $member, $projectManager);
    $outsider = User::factory()->create();
    $outsider->assignRole('Team Member');
    $file = File::factory()->create();
    $file->projects()->attach($project->getKey(), ['attached_at' => now()]);

    expect(Gate::forUser($member)->allows('view', $file))->toBeTrue()
        ->and(Gate::forUser($member)->allows('uploadToProject', [File::class, $project]))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('view', $file))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('uploadToProject', [File::class, $project]))->toBeFalse()
        ->and($file->toArray())->not->toHaveKey('storage_path')
        ->and($file->toArray())->not->toHaveKey('storage_disk');
});


it('does not let an uploader attach a file after losing access to its project', function () {
    $projectA = Project::factory()->create();
    $managerA = $projectA->creator;
    $managerA->assignRole('Project Manager');
    $uploader = User::factory()->create();
    $uploader->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($projectA, $uploader, $managerA);

    $file = File::factory()->create();
    $file->forceFill(['uploaded_by_user_id' => $uploader->getKey()])->save();
    $file->projects()->attach($projectA->getKey(), ['attached_at' => now()]);
    $projectA->memberships()->where('user_id', $uploader->getKey())->update([
        'membership_status' => 'removed',
        'removed_at' => now(),
    ]);

    $projectB = Project::factory()->create();
    $managerB = $projectB->creator;
    $managerB->assignRole('Project Manager');
    app(AddProjectMemberAction::class)->execute($projectB, $uploader, $managerB);
    $message = Message::sendForProjectMember($uploader, $projectB, 'Share attempt');

    expect(fn () => $message->attachFile($file, $uploader))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('invites only active members of assigned teams without granting project access', function () {
    $project = Project::factory()->create();
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $invitee = User::factory()->create();
    $invitee->assignRole('Team Member');
    $team = Team::factory()->create();
    app(AssignProjectTeamAction::class)->execute($project, $team, $projectManager);
    $meeting = Meeting::factory()->create(['project_id' => $project->getKey()]);

    expect(fn () => $meeting->inviteEligibleMember($invitee, $projectManager))
        ->toThrow(DomainException::class);

    app(AddTeamMemberAction::class)->execute($team, $invitee, $this->administrator);
    $participant = $meeting->inviteEligibleMember($invitee, $projectManager);

    expect($participant->eligible_team_id)->toBe($team->getKey())
        ->and($invitee->hasActiveProjectMembership((int) $project->getKey()))->toBeFalse()
        ->and($meeting->canBeAccessedBy($invitee))->toBeTrue()
        ->and(Gate::forUser($invitee)->allows('view', $project))->toBeFalse()
        ->and(fn () => Message::sendForProjectMember($invitee, $project, 'No chat access'))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('revokes future meeting access when qualifying team membership ends and preserves past attendance', function () {
    $project = Project::factory()->create();
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $member = User::factory()->create();
    $team = Team::factory()->create();
    app(AddTeamMemberAction::class)->execute($team, $member, $this->administrator);
    app(AssignProjectTeamAction::class)->execute($project, $team, $projectManager);

    $future = Meeting::factory()->create(['project_id' => $project->getKey()]);
    $past = Meeting::factory()->create([
        'project_id' => $project->getKey(),
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addHour(),
    ]);
    $future->inviteEligibleMember($member, $projectManager);
    $pastParticipant = $past->inviteEligibleMember($member, $projectManager);
    $past->participants()->updateExistingPivot($member->getKey(), ['response_status' => 'attended']);

    app(RemoveTeamMemberAction::class)->execute($team, $member, $this->administrator);

    $futureParticipant = $future->participantRecords()->firstOrFail();
    $pastParticipant = $past->participantRecords()->where('user_id', $member->getKey())->firstOrFail();

    expect($futureParticipant->access_revoked_at)->not->toBeNull()
        ->and($pastParticipant->response_status)->toBe('attended')
        ->and($pastParticipant->access_revoked_at)->toBeNull();
});

it('revokes future meeting access when a qualifying team is detached', function () {
    $project = Project::factory()->create();
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $member = User::factory()->create();
    $team = Team::factory()->create();
    app(AddTeamMemberAction::class)->execute($team, $member, $this->administrator);
    app(AssignProjectTeamAction::class)->execute($project, $team, $projectManager);
    $meeting = Meeting::factory()->create(['project_id' => $project->getKey()]);
    $participant = $meeting->inviteEligibleMember($member, $projectManager);

    app(DetachProjectTeamAction::class)->execute($project, $team, $projectManager);

    $participant = $meeting->participantRecords()->where('user_id', $member->getKey())->firstOrFail();

    expect($participant->access_revoked_at)->not->toBeNull()
        ->and($participant->response_status)->toBe('pending')
        ->and($member->hasActiveProjectMembership((int) $project->getKey()))->toBeFalse();
});

it('limits project managers to projects with active project membership', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $assignedProject = Project::factory()->create();
    $unassignedProject = Project::factory()->create();
    app(AddProjectMemberAction::class)->execute($assignedProject, $manager, $this->administrator);

    expect(Gate::forUser($manager)->allows('update', $assignedProject))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $unassignedProject))->toBeFalse()
        ->and(fn () => app(AddProjectMemberAction::class)->execute($unassignedProject, User::factory()->create(), $manager))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('limits team members to their own assigned task status updates', function () {
    $project = Project::factory()->create();
    $assignee = User::factory()->create();
    $otherMember = User::factory()->create();
    $assignee->assignRole('Team Member');
    $otherMember->assignRole('Team Member');
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    app(AddProjectMemberAction::class)->execute($project, $assignee, $projectManager);
    app(AddProjectMemberAction::class)->execute($project, $otherMember, $projectManager);
    $task = Task::factory()->create(['project_id' => $project->getKey()]);
    app(AssignTaskAssigneesAction::class)->execute($task, [$assignee->getKey()], $projectManager);

    app(UpdateAssignedTaskStatusAction::class)->execute($task, $assignee, ['status' => 'in_progress']);

    expect(fn () => app(UpdateAssignedTaskStatusAction::class)->execute($task, $otherMember, ['status' => 'completed']))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class)
        ->and($task->fresh()->status)->toBe('in_progress')
        ->and($otherMember->can('projects.create'))->toBeFalse()
        ->and($otherMember->can('teams.create'))->toBeFalse();
});

it('keeps project authorization checks side-effect free while requiring the view permission', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $project = Project::factory()->create();
    $initialAuditCount = AuditLog::query()->count();

    expect(Gate::forUser($administrator)->allows('view', $project))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe($initialAuditCount);

    $administrator->revokePermissionTo('projects.view');

    expect(Gate::forUser($administrator)->allows('view', $project))->toBeFalse();
});

it('restricts AI settings and policy configuration to administrators and audits changes', function () {
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    $policyRole = Role::query()->where('name', 'Team Member')->firstOrFail();

    $setting = AISetting::configureBy($this->administrator, ['temperature' => 0.50]);
    $policy = AIAccessPolicy::configureBy($this->administrator, [
        'role_id' => $policyRole->getKey(),
        'resource_type' => 'tasks',
        'action' => 'read',
        'access_scope' => 'own',
        'is_enabled' => true,
    ]);

    expect($setting->updated_by_user_id)->toBe($this->administrator->getKey())
        ->and($policy->configured_by_user_id)->toBe($this->administrator->getKey())
        ->and(AuditLog::query()->where('action', 'ai.settings.configure')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'ai.access_policy.configure')->exists())->toBeTrue()
        ->and(fn () => AISetting::configureBy($member, ['temperature' => 0.25]))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('derives project completion from non-deleted tasks and delayed state from deadline', function () {
    $project = Project::factory()->create(['deadline' => today()->subDay()]);
    $first = Task::factory()->create(['project_id' => $project->getKey()]);
    Task::factory()->create(['project_id' => $project->getKey()]);
    $first->forceFill(['status' => 'completed'])->save();

    $projectWithCounts = Project::query()
        ->withCount([
            'tasks',
            'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
        ])
        ->findOrFail($project->getKey());

    $completion = $projectWithCounts->tasks_count > 0
        ? round(($projectWithCounts->completed_tasks_count / $projectWithCounts->tasks_count) * 100, 2)
        : 0.0;

    expect($completion)->toBe(50.0)
        ->and($project->isDelayed())->toBeTrue();
});

it('defaults AI to deny and constrains allowed AI queries to the users active projects', function () {
    $this->seed(AIAccessPolicySeeder::class);
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    $accessible = Project::factory()->create();
    app(AddProjectMemberAction::class)->execute($accessible, $member, $this->administrator);
    $private = Project::factory()->create();
    $access = app(AIResourceAccess::class);

    AISetting::query()->updateOrCreate(['id' => 1], ['is_enabled' => false, 'temperature' => 0.70]);
    expect(fn () => $access->queryFor($member, 'projects', 'read'))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    AISetting::query()->whereKey(1)->update(['is_enabled' => true]);
    $results = $access->queryFor($member, 'projects', 'search')->get();

    expect($results->modelKeys())->toBe([(int) $accessible->getKey()])
        ->and($results->modelKeys())->not->toContain((int) $private->getKey())
        ->and(AIActivityLog::query()->where('user_id', $member->getKey())->where('action', 'access.denied')->exists())
        ->toBeTrue();
});

it('does not expose project-bound reports through the personal AI report scope', function () {
    $this->seed(AIAccessPolicySeeder::class);
    AISetting::query()->updateOrCreate(['id' => 1], ['is_enabled' => true, 'temperature' => 0.70]);

    $member = User::factory()->create();
    $member->assignRole('Team Member');
    $project = Project::factory()->create();
    $personalReport = \Modules\Reports\Models\ReportExport::factory()->create([
        'created_by_user_id' => $member->getKey(),
        'project_id' => null,
        'report_type' => 'user',
    ]);
    $projectReport = \Modules\Reports\Models\ReportExport::factory()->create([
        'created_by_user_id' => $member->getKey(),
        'project_id' => $project->getKey(),
        'report_type' => 'user',
    ]);

    $results = app(AIResourceAccess::class)->queryFor($member, 'reports', 'read')->get();

    expect($results->modelKeys())->toBe([(int) $personalReport->getKey()])
        ->and($results->modelKeys())->not->toContain((int) $projectReport->getKey());
});

it('limits AI tasks to the active member’s own assignments and excludes chat and file content', function () {
    $this->seed(AIAccessPolicySeeder::class);
    AISetting::query()->updateOrCreate(['id' => 1], ['is_enabled' => true, 'temperature' => 0.70]);

    $member = User::factory()->create();
    $member->assignRole('Team Member');
    $project = Project::factory()->create();
    app(AddProjectMemberAction::class)->execute($project, $member, $this->administrator);
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $ownTask = Task::factory()->create(['project_id' => $project->getKey()]);
    app(AssignTaskAssigneesAction::class)->execute($ownTask, [$member->getKey()], $projectManager);
    $otherTask = Task::factory()->create(['project_id' => $project->getKey()]);
    $otherTask->assignees()->detach();
    app(AssignTaskAssigneesAction::class)->execute($otherTask, [$project->creator->getKey()], $projectManager);

    $access = app(AIResourceAccess::class);
    $tasks = $access->queryFor($member, 'tasks', 'read')->get();
    $files = $access->queryFor($member, 'file_metadata', 'read');

    expect($tasks->modelKeys())->toBe([(int) $ownTask->getKey()])
        ->and($tasks->modelKeys())->not->toContain((int) $otherTask->getKey())
        ->and(fn () => $access->queryFor($member, 'chat', 'read'))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class)
        ->and($files->getQuery()->columns)->not->toContain('files.storage_path');
});

it('uses opaque Sanctum tokens and rejects revoked or expired tokens', function () {
    $user = User::factory()->create();

    $issued = $user->createToken('test-token');
    $guard = new SanctumGuard(app(AuthFactory::class));
    $request = fn (string $token) => Request::create('/', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);

    expect($guard($request($issued->plainTextToken)))->toBeInstanceOf(User::class);

    $issued->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();
    expect($guard($request($issued->plainTextToken)))->toBeNull();

    $revoked = $user->createToken('revoked-token');
    $revoked->accessToken->delete();
    expect($guard($request($revoked->plainTextToken)))->toBeNull();
});


it('requires an authorized administrator to end a team membership', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    app(AddTeamMemberAction::class)->execute($team, $member, $this->administrator);
    $membership = $team->memberships()->where('user_id', $member->getKey())->firstOrFail();

    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');

    expect(fn () => app(RemoveTeamMemberAction::class)->execute($team, $member, $manager))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class)
        ->and($team->memberships()->where('user_id', $member->getKey())->firstOrFail()->membership_status)->toBe('active');

    app(RemoveTeamMemberAction::class)->execute($team, $member, $this->administrator);

    expect($team->memberships()->where('user_id', $member->getKey())->firstOrFail()->membership_status)->toBe('inactive')
        ->and(AuditLog::query()->where('action', 'team.member.remove')->exists())->toBeTrue();
});

it('requires an authorized project manager to detach a team', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $team = Team::factory()->create();
    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);

    $outsider = User::factory()->create();
    $outsider->assignRole('Project Manager');

    expect(fn () => app(DetachProjectTeamAction::class)->execute($project, $team, $outsider))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class)
        ->and($project->teamAssignments()->where('team_id', $team->getKey())->exists())->toBeTrue();

    app(DetachProjectTeamAction::class)->execute($project, $team, $manager);

    expect($project->teamAssignments()->where('team_id', $team->getKey())->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'project.team.detach')->exists())->toBeTrue();
});


it('revokes project access when a member is explicitly removed and records the action', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $member, $manager);

    app(RemoveProjectMemberAction::class)->execute($project, $member, $manager);

    expect(Gate::forUser($member->fresh())->allows('view', $project->fresh()))->toBeFalse()
        ->and($project->memberships()->where('user_id', $member->getKey())->value('membership_status'))->toBe('removed')
        ->and(AuditLog::query()->where('action', 'project.member.remove')->exists())->toBeTrue();
});

it('does not include inactive or deleted users in team eligibility queries', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $team = Team::factory()->create();
    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);

    $active = User::factory()->create();
    $inactive = User::factory()->create(['is_active' => false]);
    $deleted = User::factory()->create();
    app(AddTeamMemberAction::class)->execute($team, $active, $this->administrator);

    DB::table('team_members')->insert([
        ['team_id' => $team->getKey(), 'user_id' => $inactive->getKey(), 'team_role' => 'member', 'membership_status' => 'active', 'added_by_user_id' => $this->administrator->getKey(), 'joined_at' => now()],
        ['team_id' => $team->getKey(), 'user_id' => $deleted->getKey(), 'team_role' => 'member', 'membership_status' => 'active', 'added_by_user_id' => $this->administrator->getKey(), 'joined_at' => now()],
    ]);
    $deleted->delete();

    expect(app(EligibleProjectTeamMembersQuery::class)->forProject($project, (int) $team->getKey())->pluck('id')->all())
        ->toBe([(int) $active->getKey()]);
});

it('allows project members to access files shared through chat attachments only', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $sender = User::factory()->create();
    $sender->assignRole('Team Member');
    $reader = User::factory()->create();
    $reader->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $sender, $manager);
    app(AddProjectMemberAction::class)->execute($project, $reader, $manager);

    $file = File::factory()->create();
    $file->forceFill(['uploaded_by_user_id' => $sender->getKey()])->save();
    $message = Message::sendForProjectMember($sender, $project, 'Shared attachment');

    expect(Gate::forUser($sender)->allows('view', $file))->toBeFalse();

    $message->attachFile($file, $sender);

    expect(Gate::forUser($sender)->allows('view', $file->fresh()))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('view', $file->fresh()))->toBeTrue();
});

it('protects file storage location and trusted metadata from mass assignment', function () {
    $file = File::factory()->create();
    $originalDisk = $file->storage_disk;
    $originalPath = $file->storage_path;
    $originalUploader = $file->uploaded_by_user_id;

    $file->update([
        'storage_disk' => 'public',
        'storage_path' => '../../.env',
        'uploaded_by_user_id' => $this->administrator->getKey(),
        'mime_type' => 'text/plain',
        'size_bytes' => 1,
        'checksum_sha256' => str_repeat('0', 64),
    ]);

    $file->refresh();

    expect($file->storage_disk)->toBe($originalDisk)
        ->and($file->storage_path)->toBe($originalPath)
        ->and($file->uploaded_by_user_id)->toBe($originalUploader)
        ->and($file->mime_type)->toBe('application/pdf');
});

it('does not allow a chat sender to attach a file they cannot access or own', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $sender = User::factory()->create();
    $sender->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $sender, $manager);
    $message = Message::sendForProjectMember($sender, $project, 'Attachment test');
    $outsider = User::factory()->create();
    $file = File::factory()->create();
    $file->forceFill(['uploaded_by_user_id' => $outsider->getKey()])->save();

    expect(fn () => $message->attachFile($file, $sender))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class)
        ->and($message->files()->exists())->toBeFalse();
});

it('denies meeting access after an invitee declines the invitation', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $team = Team::factory()->create();
    $invitee = User::factory()->create();
    $invitee->assignRole('Team Member');
    app(AddTeamMemberAction::class)->execute($team, $invitee, $this->administrator);
    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);
    $meeting = Meeting::factory()->create(['project_id' => $project->getKey()]);
    $meeting->inviteEligibleMember($invitee, $manager);
    $meeting->participants()->updateExistingPivot($invitee->getKey(), ['response_status' => 'declined']);

    expect($meeting->canBeAccessedBy($invitee))->toBeFalse();
});

it('rejects access to cancelled meetings for both managers and invitees', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $team = Team::factory()->create();
    $invitee = User::factory()->create();
    $invitee->assignRole('Team Member');
    app(AddTeamMemberAction::class)->execute($team, $invitee, $this->administrator);
    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);
    $meeting = Meeting::factory()->create(['project_id' => $project->getKey()]);
    $meeting->inviteEligibleMember($invitee, $manager);

    $meeting->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

    expect($meeting->canBeAccessedBy($manager))->toBeFalse()
        ->and($meeting->canBeAccessedBy($invitee))->toBeFalse();
});

it('constrains project-bound AI conversations to that project and denies inaccessible conversations', function () {
    $this->seed(AIAccessPolicySeeder::class);
    AISetting::query()->updateOrCreate(['id' => 1], ['is_enabled' => true, 'temperature' => 0.70]);

    $member = User::factory()->create();
    $member->assignRole('Team Member');
    $managerOne = User::factory()->create();
    $managerOne->assignRole('Project Manager');
    $managerTwo = User::factory()->create();
    $managerTwo->assignRole('Project Manager');
    $projectOne = Project::factory()->create(['created_by_user_id' => $managerOne->getKey()]);
    $projectTwo = Project::factory()->create(['created_by_user_id' => $managerTwo->getKey()]);
    app(AddProjectMemberAction::class)->execute($projectOne, $member, $managerOne);
    app(AddProjectMemberAction::class)->execute($projectTwo, $member, $managerTwo);

    $taskOne = Task::factory()->create(['project_id' => $projectOne->getKey()]);
    $taskTwo = Task::factory()->create(['project_id' => $projectTwo->getKey()]);
    app(AssignTaskAssigneesAction::class)->execute($taskOne, [$member->getKey()], $managerOne);
    app(AssignTaskAssigneesAction::class)->execute($taskTwo, [$member->getKey()], $managerTwo);

    $conversation = AIConversation::query()->create([
        'created_by_user_id' => $member->getKey(),
        'project_id' => $projectOne->getKey(),
        'title' => 'Project one context',
    ]);

    $results = app(AIResourceAccess::class)
        ->queryFor($member, 'tasks', 'read', $conversation)
        ->get();

    expect($results->modelKeys())->toBe([(int) $taskOne->getKey()])
        ->and($results->modelKeys())->not->toContain((int) $taskTwo->getKey());

    $unassigned = Project::factory()->create();
    $inaccessibleConversation = AIConversation::query()->create([
        'created_by_user_id' => $member->getKey(),
        'project_id' => $unassigned->getKey(),
        'title' => 'Inaccessible project context',
    ]);

    expect(fn () => app(AIResourceAccess::class)->queryFor($member, 'tasks', 'read', $inaccessibleConversation))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('rejects incompatible AI policy scopes and organization scope for non-administrators', function () {
    $memberRole = Role::query()->where('name', 'Team Member')->firstOrFail();

    expect(fn () => AIAccessPolicy::configureBy($this->administrator, [
        'role_id' => $memberRole->getKey(),
        'resource_type' => 'projects',
        'action' => 'read',
        'access_scope' => 'own',
        'is_enabled' => true,
    ]))->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(fn () => AIAccessPolicy::configureBy($this->administrator, [
            'role_id' => $memberRole->getKey(),
            'resource_type' => 'projects',
            'action' => 'read',
            'access_scope' => 'organization',
            'is_enabled' => true,
        ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});
