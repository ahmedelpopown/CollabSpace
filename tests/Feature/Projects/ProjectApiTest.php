<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AccessControl\Seeders\AccessControlSeeder;
use Modules\Audit\Models\AuditLog;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

it('returns only projects visible to the authenticated member and paginates the result', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');

    for ($index = 1; $index <= 7; $index++) {
        Project::factory()->create([
            'name' => 'Visible project '.$index,
            'created_by_user_id' => $manager->getKey(),
        ]);
    }

    Project::factory()->create(['name' => 'Private project']);

    $response = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/projects?per_page=5&search=Visible&sort=newest');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.total', 7)
        ->assertJsonCount(5, 'data');
});

it('creates a project and explicitly grants membership to selected active users', function () {
    $creator = User::factory()->create();
    $creator->assignRole('Project Manager');

    $member = User::factory()->create();
    $member->assignRole('Team Member');

    $response = $this->actingAs($creator, 'sanctum')->postJson('/api/v1/projects', [
        'name' => 'Collaboration Workspace',
        'description' => 'A project created through the API.',
        'starts_on' => today()->toDateString(),
        'deadline' => today()->addDays(14)->toDateString(),
        'priority' => 'high',
        'created_by_user_id' => User::factory()->create()->getKey(),
        'member_ids' => [$member->getKey()],
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Collaboration Workspace')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.counts.members', 2);

    $projectId = $response->json('data.id');
    $project = Project::query()->findOrFail($projectId);

    expect($project->created_by_user_id)->toBe($creator->getKey())
        ->and($creator->fresh()->hasActiveProjectMembership((int) $project->getKey()))->toBeTrue()
        ->and($member->fresh()->hasActiveProjectMembership((int) $project->getKey()))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'project.create')->where('subject_id', $projectId)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'project.member.assign')->where('subject_id', $projectId)->exists())->toBeTrue();
});

it('rejects inactive users when they are selected during project creation', function () {
    $creator = User::factory()->create();
    $creator->assignRole('Project Manager');
    $inactiveUser = User::factory()->create(['is_active' => false]);

    $this->actingAs($creator, 'sanctum')
        ->postJson('/api/v1/projects', [
            'name' => 'Invalid membership project',
            'member_ids' => [$inactiveUser->getKey()],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors('member_ids');

    expect(Project::query()->where('name', 'Invalid membership project')->exists())->toBeFalse();
});

it('prevents users without project membership from viewing or updating a project', function () {
    $project = Project::factory()->create();
    $outsider = User::factory()->create();
    $outsider->assignRole('Team Member');

    $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey())
        ->assertForbidden()
        ->assertJsonPath('success', false);

    $this->actingAs($outsider, 'sanctum')
        ->patchJson('/api/v1/projects/'.$project->getKey(), ['name' => 'Unauthorized change'])
        ->assertForbidden()
        ->assertJsonPath('success', false);

    expect($project->fresh()->name)->not->toBe('Unauthorized change');
});

it('updates only validated project attributes and records an audit entry', function () {
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    $this->actingAs($manager, 'sanctum')
        ->patchJson('/api/v1/projects/'.$project->getKey(), [
            'name' => 'Updated project name',
            'created_by_user_id' => User::factory()->create()->getKey(),
            'storage_path' => 'private/forged-path',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Updated project name');

    expect($project->fresh()->created_by_user_id)->toBe($manager->getKey())
        ->and(AuditLog::query()->where('action', 'project.update')->where('subject_id', $project->getKey())->exists())->toBeTrue();
});
