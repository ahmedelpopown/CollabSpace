<?php

namespace Modules\Teams\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('teams', 'active_name')],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'owner_user_id' => ['sometimes', 'required', 'integer'],
        ];
    }
}
