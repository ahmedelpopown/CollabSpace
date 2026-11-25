<?php

namespace Modules\Tasks\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\Project;
use Modules\Tasks\Models\Task;

class TaskIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('viewAny', [Task::class, $project]) ?? false);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'priority' => ['sometimes', 'nullable', Rule::in(Task::PRIORITIES)],
            'assigned_team_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
            'due_from' => ['sometimes', 'nullable', 'date'],
            'due_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:due_from'],
            'per_column' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'page_pending' => ['sometimes', 'integer', 'min:1'],
            'page_in_progress' => ['sometimes', 'integer', 'min:1'],
            'page_in_review' => ['sometimes', 'integer', 'min:1'],
            'page_completed' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
