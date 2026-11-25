<?php

namespace Modules\Projects\Requests\Api\V1\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Projects\Models\Project;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', 'required', Rule::in(Project::PRIORITIES)],
            'member_ids' => ['sometimes', 'array', 'max:100'],
            'member_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $startsOn = $this->input('starts_on');
            $deadline = $this->input('deadline');

            if (! is_string($startsOn) || ! is_string($deadline) || $startsOn === '' || $deadline === '') {
                return;
            }

            $startTimestamp = strtotime($startsOn);
            $deadlineTimestamp = strtotime($deadline);

            if ($startTimestamp !== false && $deadlineTimestamp !== false && $deadlineTimestamp < $startTimestamp) {
                $validator->errors()->add('deadline', 'The deadline must be on or after the start date.');
            }
        }];
    }
}
