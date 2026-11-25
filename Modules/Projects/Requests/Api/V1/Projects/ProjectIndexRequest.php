<?php

namespace Modules\Projects\Requests\Api\V1\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\Project;

class ProjectIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Project::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(Project::STATUSES)],
            'priority' => ['sometimes', 'nullable', Rule::in(Project::PRIORITIES)],
            'sort' => ['sometimes', 'nullable', Rule::in([
                'newest',
                'oldest',
                'deadline_soon',
                'deadline_latest',
                'name_asc',
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
