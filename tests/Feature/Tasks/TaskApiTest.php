<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\AccessControl\Seeders\AccessControlSeeder;
use Modules\Identity\Models\User;
use Modules\Projects\Actions\AddProjectMemberAction;
use Modules\Projects\Actions\DetachProjectTeamAction;
use Modules\Projects\Actions\AssignProjectTeamAction;
use Modules\Projects\Actions\RemoveProjectMemberAction;
use Modules\Projects\Models\Project;
use Modules\Tasks\Actions\AssignTaskAssigneesAction;
use Modules\Tasks\Models\Task;
use Modules\Teams\Models\Team;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

function taskApiProjectManager(): array
{
    $manager = User::factory()->create();
    $manager->assignRole('Project Manager');
    $project = Project::factory()->create(['created_by_user_id' => $manager->getKey()]);

    return [$manager, $project];
}

it('returns a paginated Kanban board with status columns and task card fields', function () {
    [$manager, $project] = taskApiProjectManager();

    Task::factory()->create([
        'project_id' => $project->getKey(),
        'title' => 'Create Wireframes',
        'status' => 'pending',
        'priority' => 'high',
    ]);
    Task::factory()->create([
        'project_id' => $project->getKey(),
        'title' => 'Build Prototype',
        'status' => 'in_progress',
        'priority' => 'medium',
        'progress_percentage' => 32,
    ]);

    $response = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/tasks?per_column=5');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.project.id', $project->getKey())
        ->assertJsonPath('data.summary.total', 2)
        ->assertJsonPath('data.summary.pending', 1)
        ->assertJsonPath('data.summary.in_progress', 1)
        ->assertJsonPath('data.columns.0.status', 'pending')
        ->assertJsonPath('data.columns.0.label', 'To-do')
        ->assertJsonPath('data.columns.0.tasks.0.title', 'Create Wireframes')
        ->assertJsonPath('data.columns.0.tasks.0.priority', 'high')
        ->assertJsonPath('data.columns.1.tasks.0.progress_percentage', 32)
        ->assertJsonPath('data.columns.3.status', 'completed');
});


it('paginates each Kanban column independently while using the grouped column totals', function () {
    [$manager, $project] = taskApiProjectManager();

    foreach (range(1, 3) as $number) {
        Task::factory()->create([
            'project_id' => $project->getKey(),
            'title' => 'Pending task '.$number,
            'status' => 'pending',
        ]);
    }

    foreach (range(1, 2) as $number) {
        Task::factory()->create([
            'project_id' => $project->getKey(),
            'title' => 'Review task '.$number,
            'status' => 'in_review',
        ]);
    }

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/tasks?per_column=1&page_pending=2&page_in_review=1')
        ->assertOk()
        ->assertJsonPath('data.summary.total', 5)
        ->assertJsonPath('data.summary.pending', 3)
        ->assertJsonPath('data.summary.in_review', 2)
        ->assertJsonPath('data.columns.0.pagination.current_page', 2)
        ->assertJsonPath('data.columns.0.pagination.total', 3)
        ->assertJsonPath('data.columns.0.tasks.0.title', 'Pending task 2')
        ->assertJsonPath('data.columns.2.pagination.current_page', 1)
        ->assertJsonPath('data.columns.2.pagination.total', 2);
});

it('returns a task only through its owning project and supports authorized deletion', function () {
    [$manager, $project] = taskApiProjectManager();
    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'title' => 'Task detail',
    ]);
    $otherProject = Project::factory()->create();

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/tasks/'.$task->getKey())
        ->assertOk()
        ->assertJsonPath('data.title', 'Task detail');

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/projects/'.$otherProject->getKey().'/tasks/'.$task->getKey())
        ->assertNotFound();

    $this->actingAs($manager, 'sanctum')
        ->deleteJson('/api/v1/projects/'.$project->getKey().'/tasks/'.$task->getKey())
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($task->fresh())->toBeNull();
});

it('creates a task and explicitly assigns only active project members', function () {
    [$manager, $project] = taskApiProjectManager();

    $assignee = User::factory()->create();
    $assignee->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $assignee, $manager);

    $response = $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/projects/'.$project->getKey().'/tasks', [
            'title' => 'Create Wireframes',
            'description' => 'Prepare the first design draft.',
            'priority' => 'high',
            'status' => 'in_review',
            'progress_percentage' => 70,
            'due_at' => now()->addDays(5)->toISOString(),
            'assignee_ids' => [$assignee->getKey()],
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Create Wireframes')
        ->assertJsonPath('data.status', 'in_review')
        ->assertJsonPath('data.progress_percentage', 70)
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.assignees.0.id', $assignee->getKey());

    expect(Task::query()->where('title', 'Create Wireframes')->exists())->toBeTrue();
});

it('rejects task assignments to users without active project membership', function () {
    [$manager, $project] = taskApiProjectManager();
    $outsider = User::factory()->create();
    $outsider->assignRole('Team Member');

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/projects/'.$project->getKey().'/tasks', [
            'title' => 'Private assignment',
            'assignee_ids' => [$outsider->getKey()],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assignee_ids');

    expect(Task::query()->where('title', 'Private assignment')->exists())->toBeFalse();
});


it('rejects inactive teams even when a task has no assignees', function () {
    [$manager, $project] = taskApiProjectManager();
    $team = Team::factory()->create();
    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);
    $team->forceFill(['is_active' => false])->save();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/projects/'.$project->getKey().'/tasks', [
            'title' => 'Task with inactive team',
            'assigned_team_id' => $team->getKey(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assigned_team_id');

    expect(Task::query()->where('title', 'Task with inactive team')->exists())->toBeFalse();
});

it('allows an assignee to update task status but not task details', function () {
    [$manager, $project] = taskApiProjectManager();
    $assignee = User::factory()->create();
    $assignee->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $assignee, $manager);

    $task = Task::factory()->create(['project_id' => $project->getKey()]);
    app(AssignTaskAssigneesAction::class)->execute($task, [$assignee->getKey()], $manager);

    $this->actingAs($assignee, 'sanctum')
        ->patchJson('/api/v1/projects/'.$project->getKey().'/tasks/'.$task->getKey(), [
            'status' => 'in_progress',
            'progress_percentage' => 35,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.progress_percentage', 35);

    $this->actingAs($assignee, 'sanctum')
        ->patchJson('/api/v1/projects/'.$project->getKey().'/tasks/'.$task->getKey(), [
            'title' => 'Unauthorized rename',
        ])
        ->assertForbidden();

    expect($task->fresh()->title)->not->toBe('Unauthorized rename');
});


it('does not reset a completed task progress or timestamp when only its title changes', function () {
    [$manager, $project] = taskApiProjectManager();
    $completedAt = now()->subDay()->startOfSecond();
    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'status' => 'completed',
        'progress_percentage' => 100,
        'completed_at' => $completedAt,
    ]);

    $this->actingAs($manager, 'sanctum')
        ->patchJson('/api/v1/projects/'.$project->getKey().'/tasks/'.$task->getKey(), [
            'title' => 'Completed task title updated',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.progress_percentage', 100);

    expect($task->fresh()->completed_at->toISOString())->toBe($completedAt->toISOString());
});



it('uses an explicit permission to expose all project tasks to managers', function () {
    [$manager, $project] = taskApiProjectManager();
    $unassignedTask = Task::factory()->create([
        'project_id' => $project->getKey(),
        'title' => 'Unassigned project task',
    ]);
    $unassignedTask->assignees()->detach();

    expect($manager->can('tasks.view.all'))->toBeTrue();

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/tasks')
        ->assertOk()
        ->assertJsonFragment(['title' => 'Unassigned project task']);

    $member = User::factory()->create();
    $member->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $member, $manager);

    expect($member->can('tasks.view.all'))->toBeFalse();

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/tasks')
        ->assertOk()
        ->assertJsonMissing(['title' => 'Unassigned project task']);
});

it('reopens a completed task when its assignee reports progress below one hundred percent', function () {
    [$manager, $project] = taskApiProjectManager();
    $assignee = User::factory()->create();
    $assignee->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $assignee, $manager);
    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'status' => 'completed',
        'progress_percentage' => 100,
    ]);
    app(AssignTaskAssigneesAction::class)->execute($task, [$assignee->getKey()], $manager);

    $this->actingAs($assignee, 'sanctum')
        ->patchJson('/api/v1/projects/'.$project->getKey().'/tasks/'.$task->getKey(), [
            'progress_percentage' => 45,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.progress_percentage', 45);
});

it('does not expose unassigned project tasks to team members', function () {
    [$manager, $project] = taskApiProjectManager();
    $member = User::factory()->create();
    $member->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $member, $manager);

    $assignedTask = Task::factory()->create(['project_id' => $project->getKey(), 'title' => 'Assigned']);
    $unassignedTask = Task::factory()->create(['project_id' => $project->getKey(), 'title' => 'Not assigned']);
    app(AssignTaskAssigneesAction::class)->execute($assignedTask, [$member->getKey()], $manager);

    $response = $this->actingAs($member, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/tasks');

    $response->assertOk()
        ->assertJsonFragment(['title' => 'Assigned'])
        ->assertJsonMissing(['title' => 'Not assigned']);

    expect(Gate::forUser($member)->allows('view', $unassignedTask))->toBeFalse();
});


it('revokes task assignments when a user is removed from the project', function () {
    [$manager, $project] = taskApiProjectManager();

    $member = User::factory()->create();
    $member->assignRole('Team Member');
    app(AddProjectMemberAction::class)->execute($project, $member, $manager);

    $task = Task::factory()->create(['project_id' => $project->getKey()]);
    app(AssignTaskAssigneesAction::class)->execute($task, [$member->getKey()], $manager);

    app(RemoveProjectMemberAction::class)->execute($project, $member, $manager);

    expect($task->assignees()->whereKey($member->getKey())->exists())->toBeFalse();

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/v1/projects/'.$project->getKey().'/tasks')
        ->assertForbidden();
});


it('rejects unsupported task update fields', function () {
    [$manager, $project] = taskApiProjectManager();
    $task = Task::factory()->create(['project_id' => $project->getKey()]);

    $this->actingAs($manager, 'sanctum')
        ->patchJson('/api/v1/projects/'.$project->getKey().'/tasks/'.$task->getKey(), [
            'created_by_user_id' => 9999,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('task');

    expect((int) $task->fresh()->created_by_user_id)->not->toBe(9999);
});


it('clears team assignments before detaching a project team', function () {
    [$manager, $project] = taskApiProjectManager();
    $team = Team::factory()->create();
    app(AssignProjectTeamAction::class)->execute($project, $team, $manager);

    $task = Task::factory()->create([
        'project_id' => $project->getKey(),
        'assigned_team_id' => $team->getKey(),
    ]);
    $deletedTask = Task::factory()->create([
        'project_id' => $project->getKey(),
        'assigned_team_id' => $team->getKey(),
    ]);
    $deletedTask->delete();

    app(DetachProjectTeamAction::class)->execute($project, $team, $manager);

    expect($project->teamAssignments()->where('team_id', $team->getKey())->exists())->toBeFalse()
        ->and($task->fresh()->assigned_team_id)->toBeNull()
        ->and(Task::withTrashed()->findOrFail($deletedTask->getKey())->assigned_team_id)->toBeNull();
});


it('keeps task ownership and assignment columns guarded from mass assignment', function () {
    $task = new Task([
        'title' => 'Safe task',
        'project_id' => 999,
        'assigned_team_id' => 999,
        'created_by_user_id' => 999,
        'completed_at' => now(),
    ]);

    expect($task->title)->toBe('Safe task')
        ->and($task->project_id)->toBeNull()
        ->and($task->assigned_team_id)->toBeNull()
        ->and($task->created_by_user_id)->toBeNull()
        ->and($task->completed_at)->toBeNull();
});
