<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AccessControl\Seeders\AccessControlSeeder;
use Modules\Identity\Models\User;
use Modules\Projects\Actions\AddProjectMemberAction;
use Modules\Projects\Actions\AssignProjectTeamAction;
use Modules\Projects\Models\Project;
use Modules\Tasks\Actions\AssignTaskAssigneesAction;
use Modules\Tasks\Models\Task;
use Modules\Teams\Actions\AddTeamMemberAction;
use Modules\Teams\Models\Team;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

it('returns project teams with active members and calculated task progress', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');

    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    $team = Team::factory()->create(['name' => 'UI/UX']);
    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);

    $member = User::factory()->create(['full_name' => 'Mohanad Wahib', 'job_title' => 'UI Designer']);
    $member->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $member, $manager);
    app(AddTeamMemberAction::class)->execute($team, $member, $administrator, 'lead');

    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'assigned_team_id' => $team->getKey(),
        'status' => 'completed',
        'progress_percentage' => 100,
    ]);
    app(AssignTaskAssigneesAction::class)->execute($task, [$member->getKey()], $manager);

    $response = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/teams');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.name', 'UI/UX')
        ->assertJsonPath('data.0.tasks_count', 1)
        ->assertJsonPath('data.0.completed_tasks_count', 1)
        ->assertJsonPath('data.0.progress_percentage', 100)
        ->assertJsonPath('data.0.members.0.full_name', 'Mohanad Wahib')
        ->assertJsonPath('data.0.members.0.team_role', 'lead')
        ->assertJsonPath('data.0.members.0.tasks_count', 1)
        ->assertJsonPath('data.0.members.0.completed_tasks_count', 1);
});

it('allows only users with team member management permission to add members', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $team = Team::factory()->create();
    $member = User::factory()->create();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/teams/'.$team->getKey().'/members', [
            'user_id' => $member->getKey(),
            'team_role' => 'lead',
        ])
        ->assertForbidden();

    $this->actingAs($administrator, 'sanctum')
        ->postJson('/api/v1/teams/'.$team->getKey().'/members', [
            'user_id' => $member->getKey(),
            'team_role' => 'lead',
        ])
        ->assertCreated()
        ->assertJsonPath('data.user_id', $member->getKey())
        ->assertJsonPath('data.team_role', 'lead');
});


it('removes a team member through scoped nested route binding', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $team = Team::factory()->create();
    $member = User::factory()->create();
    app(AddTeamMemberAction::class)->execute($team, $member, $administrator);

    $this->actingAs($administrator, 'sanctum')
        ->deleteJson('/api/v1/teams/'.$team->getKey().'/members/'.$member->getKey())
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($team->memberships()->where('user_id', $member->getKey())->value('membership_status'))
        ->toBe('inactive');
});

it('does not return teams from other projects', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    // The fixture must use an actor who is allowed to assign teams. The purpose
    // of this test is to verify project scoping, not to bypass the assignment policy.
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $otherProject = Project::factory()->create(['created_by_user_id' => $administrator->getKey()]);
    $team = Team::factory()->create(['name' => 'Private team']);
    app(AssignProjectTeamAction::class)->execute($otherProject, $team, $administrator);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/teams')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});


it('revokes team-scoped task assignments when a team membership ends', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');

    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    $team = Team::factory()->create();
    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);

    $member = User::factory()->create();
    $member->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $member, $manager);
    app(AddTeamMemberAction::class)->execute($team, $member, $administrator);

    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'assigned_team_id' => $team->getKey(),
        'title' => 'Team-only task',
    ]);
    app(AssignTaskAssigneesAction::class)->execute($task, [$member->getKey()], $manager);

    $this->actingAs($administrator, 'sanctum')
        ->deleteJson('/api/v1/teams/'.$team->getKey().'/members/'.$member->getKey())
        ->assertOk();

    expect($task->assignees()->whereKey($member->getKey())->exists())->toBeFalse();

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/tasks')
        ->assertOk()
        ->assertJsonMissing(['title' => 'Team-only task']);
});


it('assigns an existing active team to a project and treats duplicate assignment as idempotent', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);
    $team = Team::factory()->create(['name' => 'Frontend']);

    $firstResponse = $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/projects/'.$project->getKey().'/teams', [
            'team_id' => $team->getKey(),
        ]);

    $firstResponse->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.project_id', $project->getKey())
        ->assertJsonPath('data.team.id', $team->getKey())
        ->assertJsonPath('data.assigned', true);

    expect($project->teams()->whereKey($team->getKey())->exists())->toBeTrue();

    $this->postJson('/api/v1/projects/'.$project->getKey().'/teams', [
        'team_id' => $team->getKey(),
    ])
        ->assertOk()
        ->assertJsonPath('data.assigned', false);

    expect($project->teams()->whereKey($team->getKey())->count())->toBe(1);
});

it('does not allow a team member to assign teams to a project', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    $member = User::factory()->create();
    $member->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $member, $manager);

    $team = Team::factory()->create();

    $this->actingAs($member, 'sanctum')
        ->postJson('/api/v1/projects/'.$project->getKey().'/teams', [
            'team_id' => $team->getKey(),
        ])
        ->assertForbidden();

    expect($project->teams()->whereKey($team->getKey())->exists())->toBeFalse();
});

it('rejects inactive teams when assigning them to a project', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);
    $inactiveTeam = Team::factory()->create(['is_active' => false]);

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/projects/'.$project->getKey().'/teams', [
            'team_id' => $inactiveTeam->getKey(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('team_id');

    expect($project->teams()->whereKey($inactiveTeam->getKey())->exists())->toBeFalse();
});

it('detaches an assigned team from a project through a scoped nested endpoint', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);
    $team = Team::factory()->create();

    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);

    $this->actingAs($manager, 'sanctum')
        ->deleteJson('/api/v1/projects/'.$project->getKey().'/teams/'.$team->getKey())
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.project_id', $project->getKey())
        ->assertJsonPath('data.team_id', $team->getKey());

    expect($project->teams()->whereKey($team->getKey())->exists())->toBeFalse();
});

it('does not detach a team through a project that does not own the assignment', function () {
    $firstManager = User::factory()->create();
    $firstManager->assignRole('Project Manager');
    $firstProject = Project::factory()->create(['created_by_user_id' => $firstManager->getKey()]);

    $secondManager = User::factory()->create();
    $secondManager->assignRole('Project Manager');
    $secondProject = Project::factory()->create(['created_by_user_id' => $secondManager->getKey()]);

    $team = Team::factory()->create();
    app(AssignProjectTeamAction::class)->execute($secondProject, $team, $secondManager);

    $this->actingAs($firstManager, 'sanctum')
        ->deleteJson('/api/v1/projects/'.$firstProject->getKey().'/teams/'.$team->getKey())
        ->assertNotFound();

    expect($secondProject->teams()->whereKey($team->getKey())->exists())->toBeTrue();
});


it('prevents removing the current team owner before ownership is transferred', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_user_id' => $owner->getKey()]);
    app(AddTeamMemberAction::class)->execute($team, $owner, $administrator, 'lead');

    $this->actingAs($administrator, 'sanctum')
        ->deleteJson('/api/v1/teams/'.$team->getKey().'/members/'.$owner->getKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');

    expect($team->memberships()->where('user_id', $owner->getKey())->value('membership_status'))->toBe('active');
});

it('prevents removing the last active team lead', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $team = Team::factory()->create(['owner_user_id' => null]);
    $lastLead = User::factory()->create();
    app(AddTeamMemberAction::class)->execute($team, $lastLead, $administrator, 'lead');

    $this->actingAs($administrator, 'sanctum')
        ->deleteJson('/api/v1/teams/'.$team->getKey().'/members/'.$lastLead->getKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');

    expect($team->memberships()->where('user_id', $lastLead->getKey())->value('membership_status'))->toBe('active');
});


it('does not allow the owner to be demoted from team lead', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_user_id' => $owner->getKey()]);
    app(AddTeamMemberAction::class)->execute($team, $owner, $administrator, 'lead');

    $this->actingAs($administrator, 'sanctum')
        ->postJson('/api/v1/teams/'.$team->getKey().'/members', [
            'user_id' => $owner->getKey(),
            'team_role' => 'member',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('team_role');

    expect($team->memberships()->where('user_id', $owner->getKey())->value('team_role'))->toBe('lead');
});

it('does not allow demoting the last active team lead', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $team = Team::factory()->create(['owner_user_id' => null]);
    $lead = User::factory()->create();
    app(AddTeamMemberAction::class)->execute($team, $lead, $administrator, 'lead');

    $this->actingAs($administrator, 'sanctum')
        ->postJson('/api/v1/teams/'.$team->getKey().'/members', [
            'user_id' => $lead->getKey(),
            'team_role' => 'member',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('team_role');

    expect($team->memberships()->where('user_id', $lead->getKey())->value('team_role'))->toBe('lead');
});
