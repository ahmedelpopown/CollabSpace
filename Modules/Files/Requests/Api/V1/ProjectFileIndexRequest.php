<?php

namespace Modules\Files\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Projects\Models\Project;

class ProjectFileIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && ($this->user()?->can('viewFiles', $project) ?? false);
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
