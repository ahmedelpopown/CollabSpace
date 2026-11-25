<?php

namespace Modules\Tasks\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('project') instanceof Project && $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', 'required', Rule::in(Task::PRIORITIES)],
            'status' => ['sometimes', 'required', Rule::in(Task::STATUSES)],
            'progress_percentage' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'assigned_team_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_ids' => ['sometimes', 'array', 'max:50'],
            'assignee_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $status = $this->input('status', 'pending');
            $progress = (int) $this->input('progress_percentage', $status === 'completed' ? 100 : 0);

            if (($status === 'completed') !== ($progress === 100)) {
                $validator->errors()->add(
                    'progress_percentage',
                    'Completed tasks must have 100% progress, and a task at 100% must be completed.',
                );
            }
        }];
    }
}
