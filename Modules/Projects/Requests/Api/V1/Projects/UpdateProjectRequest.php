<?php

namespace Modules\Projects\Requests\Api\V1\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Projects\Models\Project;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('project') instanceof Project && $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', 'required', Rule::in(Project::PRIORITIES)],
            'status' => ['sometimes', 'required', Rule::in(Project::STATUSES)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->route('project');

            if (! $project instanceof Project) {
                return;
            }

            $startsOn = $this->input('starts_on', $project->starts_on?->toDateString());
            $deadline = $this->input('deadline', $project->deadline?->toDateString());

            if (is_string($startsOn) && is_string($deadline) && $startsOn !== '' && $deadline !== '') {
                $startTimestamp = strtotime($startsOn);
                $deadlineTimestamp = strtotime($deadline);

                if ($startTimestamp !== false && $deadlineTimestamp !== false && $deadlineTimestamp < $startTimestamp) {
                    $validator->errors()->add('deadline', 'The deadline must be on or after the start date.');
                }
            }

            if (array_intersect(
                ['name', 'description', 'starts_on', 'deadline', 'priority', 'status'],
                array_keys($this->all()),
            ) === []) {
                $validator->errors()->add('project', 'At least one project field must be provided.');
            }
        }];
    }
}
