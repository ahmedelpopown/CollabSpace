<?php

namespace Modules\Teams\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Teams\Models\Team;

class AddTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('team') instanceof Team && $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'team_role' => ['sometimes', 'required', Rule::in(['lead', 'member'])],
        ];
    }
}
