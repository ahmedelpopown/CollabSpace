<?php

namespace Modules\Teams\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Teams\Models\Team;

class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team
            && ($this->user()?->can('update', $team) ?? false);
    }

    public function rules(): array
    {
        $team = $this->route('team');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:120',
                Rule::unique('teams', 'active_name')->ignore($team instanceof Team ? $team->getKey() : null),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(['name', 'description'], array_keys($this->all())) === []) {
                $validator->errors()->add('team', 'At least one team field must be provided.');
            }
        }];
    }
}
