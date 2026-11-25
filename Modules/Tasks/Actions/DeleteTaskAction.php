<?php

namespace Modules\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Tasks\Models\Task;

class DeleteTaskAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLog) {}

    public function execute(Task $task, User $deletedBy): void
    {
        DB::transaction(function () use ($task, $deletedBy): void {
            $lockedTask = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($deletedBy)->authorize('delete', $lockedTask);

            $lockedTask->delete();

            $this->auditLog->execute(
                $deletedBy,
                'task.delete',
                $lockedTask,
                oldValues: ['status' => $lockedTask->status, 'project_id' => $lockedTask->project_id],
            );
        });
    }
}
