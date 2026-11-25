<?php

namespace Modules\Tasks\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Tasks\Models\Task;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The update Action performs the authoritative Policy check while holding the task lock.
        return $this->route('task') instanceof Task && $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:180'],
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
            $allowedFields = [
                'title', 'description', 'priority', 'status', 'progress_percentage',
                'due_at', 'assigned_team_id', 'assignee_ids',
            ];
            $unknownFields = array_diff(array_keys($this->all()), $allowedFields);

            if ($unknownFields !== []) {
                $validator->errors()->add('task', 'The request contains unsupported task fields.');
            }

            if ($this->all() === []) {
                $validator->errors()->add('task', 'At least one task field must be provided.');
            }

            if ($this->input('status') === 'completed'
                && $this->has('progress_percentage')
                && (int) $this->input('progress_percentage') !== 100) {
                $validator->errors()->add('progress_percentage', 'Completed tasks must have 100% progress.');
            }

            if ($this->has('status')
                && $this->input('status') !== 'completed'
                && $this->has('progress_percentage')
                && (int) $this->input('progress_percentage') === 100) {
                $validator->errors()->add('progress_percentage', 'A task at 100% progress must have completed status.');
            }
        }];
    }
}
