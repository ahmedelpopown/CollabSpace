<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AccessControl\Seeders\AccessControlSeeder;
use Modules\Identity\Models\User;
use Modules\Projects\Actions\AssignProjectTeamAction;
use Modules\Projects\Models\Project;
use Modules\Teams\Actions\AddTeamMemberAction;
use Modules\Teams\Models\Team;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

it('creates a team and makes its owner the initial lead', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $owner = User::factory()->create(['full_name' => 'Team Owner']);

    $response = $this->actingAs($administrator, 'sanctum')
        ->postJson('/api/v1/teams', [
            'name' => 'Platform Engineering',
            'description' => 'Owns platform services and integrations.',
            'owner_user_id' => $owner->getKey(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Platform Engineering')
        ->assertJsonPath('data.owner.id', $owner->getKey())
        ->assertJsonPath('data.members.0.id', $owner->getKey())
        ->assertJsonPath('data.members.0.team_role', 'lead')
        ->assertJsonPath('data.members_count', 1);

    $team = Team::query()->where('name', 'Platform Engineering')->firstOrFail();

    expect($team->owner_user_id)->toBe($owner->getKey())
        ->and($team->memberships()->where('user_id', $owner->getKey())->value('membership_status'))->toBe('active');
});

it('uses the administrator as owner when no owner is supplied', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');

    $this->actingAs($administrator, 'sanctum')
        ->postJson('/api/v1/teams', ['name' => 'Default Owner Team'])
        ->assertCreated()
        ->assertJsonPath('data.owner.id', $administrator->getKey())
        ->assertJsonPath('data.members.0.team_role', 'lead');
});

it('allows only users with teams.create to create teams', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/teams', ['name' => 'Unauthorized Team'])
        ->assertForbidden();

    expect(Team::query()->where('name', 'Unauthorized Team')->exists())->toBeFalse();
});

it('rejects inactive owners and duplicate active team names', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $inactiveOwner = User::factory()->create(['is_active' => false]);

    $this->actingAs($administrator, 'sanctum')
        ->postJson('/api/v1/teams', [
            'name' => 'Invalid Owner Team',
            'owner_user_id' => $inactiveOwner->getKey(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('owner_user_id');

    Team::factory()->create(['name' => 'Existing Active Team', 'is_active' => true]);

    $this->postJson('/api/v1/teams', ['name' => 'Existing Active Team'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('lists teams with pagination and restricts non administrators to visible teams', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $member = User::factory()->create();
    $member->assignRole('Team Member');

    $visibleTeam = Team::factory()->create(['name' => 'Visible Team', 'owner_user_id' => $manager->getKey()]);
    app(AddTeamMemberAction::class)->execute($visibleTeam, $member, $administrator, 'member');
    Team::factory()->create(['name' => 'Private Team']);

    $this->actingAs($administrator, 'sanctum')
        ->getJson('/api/v1/teams?per_page=1&sort=name_asc')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/v1/teams')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Visible Team');
});

it('updates team metadata without allowing mass assignment of ownership or active state', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $newOwner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Old Team', 'is_active' => true]);
    $originalOwnerId = $team->owner_user_id;

    $this->actingAs($administrator, 'sanctum')
        ->patchJson('/api/v1/teams/'.$team->getKey(), [
            'name' => 'Updated Team',
            'description' => 'Updated description.',
            'owner_user_id' => $newOwner->getKey(),
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Team')
        ->assertJsonPath('data.description', 'Updated description.');

    expect($team->fresh()->owner_user_id)->toBe($originalOwnerId)
        ->and($team->fresh()->is_active)->toBeTrue();
});

it('does not allow non administrators to update team metadata', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $team = Team::factory()->create(['name' => 'Protected Team']);

    $this->actingAs($manager, 'sanctum')
        ->patchJson('/api/v1/teams/'.$team->getKey(), ['name' => 'Changed Team'])
        ->assertForbidden();

    expect($team->fresh()->name)->toBe('Protected Team');
});

it('prevents deleting a team while it is assigned to a project', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);
    $team = Team::factory()->create();

    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);

    $this->actingAs($administrator, 'sanctum')
        ->deleteJson('/api/v1/teams/'.$team->getKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('team');

    expect($team->fresh())->not->toBeNull()
        ->and($project->teams()->whereKey($team->getKey())->exists())->toBeTrue();
});

it('soft deletes an unassigned team and ends its active memberships', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');
    $team = Team::factory()->create(['is_active' => true]);
    $member = User::factory()->create();
    app(AddTeamMemberAction::class)->execute($team, $member, $administrator);

    $this->actingAs($administrator, 'sanctum')
        ->deleteJson('/api/v1/teams/'.$team->getKey())
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    $deletedTeam = Team::withTrashed()->findOrFail($team->getKey());

    expect($deletedTeam->trashed())->toBeTrue()
        ->and($deletedTeam->is_active)->toBeFalse()
        ->and($team->memberships()->where('user_id', $member->getKey())->value('membership_status'))->toBe('inactive');

    $this->getJson('/api/v1/teams/'.$team->getKey())->assertNotFound();
});
