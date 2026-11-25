<?php

namespace Modules\Tasks\Actions;

use Modules\Identity\Models\User;
use Modules\Tasks\Models\Task;

/** Route the update to the use case allowed for this actor. */
class UpdateTaskForUserAction
{
    public function __construct(
        private readonly UpdateTaskAction $updateTask,
        private readonly UpdateAssignedTaskStatusAction $updateAssignedStatus,
    ) {}

    public function execute(Task $task, User $actor, array $data): Task
    {
        // Select the appropriate use case from the actor capabilities. The selected
        // Action remains responsible for the authoritative Policy check after locking the Task.
        if ($actor->can('tasks.update') && $actor->can('projects.update')) {
            return $this->updateTask->execute($task, $actor, $data);
        }

        return $this->updateAssignedStatus->execute($task, $actor, $data);
    }
}
