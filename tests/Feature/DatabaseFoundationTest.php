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
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;
use Modules\Teams\Models\Team;
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
    $team->addMember($teamUser, $this->administrator);

    $project->assignTeam($team, $projectManager);

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

    $project = Project::createWithCreatorMembership($creator, ['name' => 'Created project']);

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

    expect($outsider->canAccessProject($project))->toBeFalse()
        ->and($task->canBeAccessedBy($outsider))->toBeFalse()
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
    $project->addMember($member, $projectManager);

    $task = Task::factory()->create(['project_id' => $project->getKey()]);
    $task->assignAssignee($member, $projectManager);
    $message = Message::sendForProjectMember($member, $project, 'Project update');

    expect($member->canAccessProject($project))->toBeTrue()
        ->and($task->canBeAccessedBy($member))->toBeTrue()
        ->and($message->canBeReadBy($member))->toBeTrue();
});

it('limits project file metadata and uploads to users with active project access', function () {
    $project = Project::factory()->create();
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    $project->addMember($member, $projectManager);
    $outsider = User::factory()->create();
    $outsider->assignRole('Team Member');
    $file = File::factory()->create();
    $file->projects()->attach($project->getKey(), ['attached_at' => now()]);

    expect($file->canBeAccessedBy($member))->toBeTrue()
        ->and($file->canBeUploadedToBy($member, $project))->toBeTrue()
        ->and($file->canBeAccessedBy($outsider))->toBeFalse()
        ->and($file->canBeUploadedToBy($outsider, $project))->toBeFalse()
        ->and($file->toArray())->not->toHaveKey('storage_path')
        ->and($file->toArray())->not->toHaveKey('storage_disk');
});

it('invites only active members of assigned teams without granting project access', function () {
    $project = Project::factory()->create();
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $invitee = User::factory()->create();
    $invitee->assignRole('Team Member');
    $team = Team::factory()->create();
    $project->assignTeam($team, $projectManager);
    $meeting = Meeting::factory()->create(['project_id' => $project->getKey()]);

    expect(fn () => $meeting->inviteEligibleMember($invitee, $projectManager))
        ->toThrow(DomainException::class);

    $team->addMember($invitee, $this->administrator);
    $participant = $meeting->inviteEligibleMember($invitee, $projectManager);

    expect($participant->eligible_team_id)->toBe($team->getKey())
        ->and($invitee->hasActiveProjectMembership((int) $project->getKey()))->toBeFalse()
        ->and($meeting->canBeAccessedBy($invitee))->toBeTrue()
        ->and($invitee->canAccessProject($project))->toBeFalse()
        ->and(fn () => Message::sendForProjectMember($invitee, $project, 'No chat access'))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('revokes future meeting access when qualifying team membership ends and preserves past attendance', function () {
    $project = Project::factory()->create();
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->addMember($member, $this->administrator);
    $project->assignTeam($team, $projectManager);

    $future = Meeting::factory()->create(['project_id' => $project->getKey()]);
    $past = Meeting::factory()->create([
        'project_id' => $project->getKey(),
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addHour(),
    ]);
    $future->inviteEligibleMember($member, $projectManager);
    $pastParticipant = $past->inviteEligibleMember($member, $projectManager);
    $past->participants()->updateExistingPivot($member->getKey(), ['response_status' => 'attended']);

    $team->memberships()->where('user_id', $member->getKey())->firstOrFail()->endMembership($this->administrator);

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
    $team->addMember($member, $this->administrator);
    $project->assignTeam($team, $projectManager);
    $meeting = Meeting::factory()->create(['project_id' => $project->getKey()]);
    $participant = $meeting->inviteEligibleMember($member, $projectManager);

    $project->detachTeam($team, $projectManager);

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
    $assignedProject->addMember($manager, $this->administrator);

    expect($assignedProject->canBeManagedBy($manager))->toBeTrue()
        ->and($unassignedProject->canBeManagedBy($manager))->toBeFalse()
        ->and(fn () => $unassignedProject->addMember(User::factory()->create(), $manager))
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
    $project->addMember($assignee, $projectManager);
    $project->addMember($otherMember, $projectManager);
    $task = Task::factory()->create(['project_id' => $project->getKey()]);
    $task->assignAssignee($assignee, $projectManager);

    expect($task->updateStatusBy($assignee, 'in_progress'))->toBeTrue()
        ->and($task->updateStatusBy($otherMember, 'completed'))->toBeFalse()
        ->and($task->fresh()->status)->toBe('in_progress')
        ->and($otherMember->can('projects.create'))->toBeFalse()
        ->and($otherMember->can('teams.create'))->toBeFalse();
});

it('keeps project authorization checks side-effect free while requiring the view permission', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $project = Project::factory()->create();
    $initialAuditCount = AuditLog::query()->count();

    expect($administrator->canAccessProject($project))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe($initialAuditCount);

    $administrator->revokePermissionTo('projects.view');

    expect($administrator->canAccessProject($project))->toBeFalse();
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

    expect($project->completionPercentage())->toBe(50.0)
        ->and($project->isDelayed())->toBeTrue()
        ->and(Project::factory()->create()->completionPercentage())->toBe(0.0);
});

it('defaults AI to deny and constrains allowed AI queries to the users active projects', function () {
    $this->seed(AIAccessPolicySeeder::class);
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    $accessible = Project::factory()->create();
    $accessible->addMember($member, $this->administrator);
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
    $project->addMember($member, $this->administrator);
    $projectManager = $project->creator;
    $projectManager->assignRole('Project Manager');
    $ownTask = Task::factory()->create(['project_id' => $project->getKey()]);
    $ownTask->assignAssignee($member, $projectManager);
    $otherTask = Task::factory()->create(['project_id' => $project->getKey()]);
    $otherTask->assignees()->detach();
    $otherTask->assignAssignee($project->creator, $projectManager);

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
    $team->addMember($member, $this->administrator);
    $membership = $team->memberships()->where('user_id', $member->getKey())->firstOrFail();

    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');

    expect(fn () => $membership->endMembership($manager))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class)
        ->and($team->memberships()->where('user_id', $member->getKey())->firstOrFail()->membership_status)->toBe('active');

    $membership->endMembership($this->administrator);

    expect($team->memberships()->where('user_id', $member->getKey())->firstOrFail()->membership_status)->toBe('inactive')
        ->and(AuditLog::query()->where('action', 'team.member.remove')->exists())->toBeTrue();
});

it('requires an authorized project manager to detach a team', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $team = Team::factory()->create();
    $project->assignTeam($team, $manager);

    $outsider = User::factory()->create();
    $outsider->assignRole('Project Manager');

    expect(fn () => $project->detachTeam($team, $outsider))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class)
        ->and($project->teamIsAssigned((int) $team->getKey()))->toBeTrue();

    $project->detachTeam($team, $manager);

    expect($project->teamIsAssigned((int) $team->getKey()))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'project.team.detach')->exists())->toBeTrue();
});


it('revokes project access when a member is explicitly removed and records the action', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    $project->addMember($member, $manager);

    $project->removeMember($member, $manager);

    expect($member->fresh()->canAccessProject($project->fresh()))->toBeFalse()
        ->and($project->memberships()->where('user_id', $member->getKey())->value('membership_status'))->toBe('removed')
        ->and(AuditLog::query()->where('action', 'project.member.remove')->exists())->toBeTrue();
});

it('does not include inactive or deleted users in team eligibility queries', function () {
    $project = Project::factory()->create();
    $manager = $project->creator;
    $manager->assignRole('Project Manager');
    $team = Team::factory()->create();
    $project->assignTeam($team, $manager);

    $active = User::factory()->create();
    $inactive = User::factory()->create(['is_active' => false]);
    $deleted = User::factory()->create();
    $team->addMember($active, $this->administrator);

    DB::table('team_members')->insert([
        ['team_id' => $team->getKey(), 'user_id' => $inactive->getKey(), 'team_role' => 'member', 'membership_status' => 'active', 'added_by_user_id' => $this->administrator->getKey(), 'joined_at' => now()],
        ['team_id' => $team->getKey(), 'user_id' => $deleted->getKey(), 'team_role' => 'member', 'membership_status' => 'active', 'added_by_user_id' => $this->administrator->getKey(), 'joined_at' => now()],
    ]);
    $deleted->delete();

    expect($project->eligibleUsersFromTeam((int) $team->getKey())->pluck('users.id')->all())
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
    $project->addMember($sender, $manager);
    $project->addMember($reader, $manager);

    $file = File::factory()->create();
    $file->forceFill(['uploaded_by_user_id' => $sender->getKey()])->save();
    $message = Message::sendForProjectMember($sender, $project, 'Shared attachment');

    expect($file->canBeAccessedBy($sender))->toBeFalse();

    $message->attachFile($file, $sender);

    expect($file->fresh()->canBeAccessedBy($sender))->toBeTrue()
        ->and($file->fresh()->canBeAccessedBy($reader))->toBeTrue();
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
    $project->addMember($sender, $manager);
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
    $team->addMember($invitee, $this->administrator);
    $project->assignTeam($team, $manager);
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
    $team->addMember($invitee, $this->administrator);
    $project->assignTeam($team, $manager);
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
    $projectOne->addMember($member, $managerOne);
    $projectTwo->addMember($member, $managerTwo);

    $taskOne = Task::factory()->create(['project_id' => $projectOne->getKey()]);
    $taskTwo = Task::factory()->create(['project_id' => $projectTwo->getKey()]);
    $taskOne->assignAssignee($member, $managerOne);
    $taskTwo->assignAssignee($member, $managerTwo);

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
