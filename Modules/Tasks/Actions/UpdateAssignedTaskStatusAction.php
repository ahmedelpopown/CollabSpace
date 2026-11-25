<?php

namespace Modules\Tasks\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Tasks\Models\Task;

class UpdateAssignedTaskStatusAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLog) {}

    public function execute(Task $task, User $assignee, array $data): Task
    {
        return DB::transaction(function () use ($task, $assignee, $data): Task {
            $lockedTask = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($assignee)->authorize('updateAssignedStatus', $lockedTask);

            $oldValues = [
                'status' => $lockedTask->status,
                'progress_percentage' => $lockedTask->progress_percentage,
            ];

            $hasStatus = array_key_exists('status', $data);
            $hasProgress = array_key_exists('progress_percentage', $data);
            $requestedStatus = $data['status'] ?? $lockedTask->status;
            $requestedProgress = (int) ($data['progress_percentage'] ?? $lockedTask->progress_percentage);

            if ($hasStatus) {
                $lockedTask->status = $data['status'];
            }

            if ($hasProgress) {
                $lockedTask->progress_percentage = (int) $data['progress_percentage'];
            }

            if (($hasStatus && $requestedStatus === 'completed') || ($hasProgress && $requestedProgress === 100)) {
                $lockedTask->status = 'completed';
                $lockedTask->progress_percentage = 100;
            } elseif ($oldValues['status'] === 'completed' && (($hasStatus && $requestedStatus !== 'completed') || ($hasProgress && $requestedProgress < 100))) {
                if (! $hasStatus) {
                    $lockedTask->status = 'in_progress';
                }

                if (! $hasProgress) {
                    $lockedTask->progress_percentage = 0;
                }
            }

            if ($lockedTask->status === 'completed') {
                $lockedTask->completed_at ??= now();
            } else {
                $lockedTask->completed_at = null;
            }
            $lockedTask->save();

            $newValues = [
                'status' => $lockedTask->status,
                'progress_percentage' => $lockedTask->progress_percentage,
            ];

            if ($oldValues !== $newValues) {
                $this->auditLog->execute(
                    $assignee,
                    'task.status.update.assigned',
                    $lockedTask,
                    oldValues: $oldValues,
                    newValues: $newValues,
                );
            }

            return $lockedTask;
        });
    }
}
