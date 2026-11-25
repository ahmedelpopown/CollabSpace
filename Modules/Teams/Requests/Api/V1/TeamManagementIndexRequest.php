<?php

namespace Modules\Teams\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Teams\Models\Team;

class TeamManagementIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Team::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'all'])],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest', 'name_asc', 'name_desc', 'members_desc', 'projects_desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
