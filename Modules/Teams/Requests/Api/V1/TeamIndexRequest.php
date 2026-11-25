<?php

namespace Modules\Teams\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Models\Project;
use Modules\Teams\Models\Team;

class TeamIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        $user = $this->user();

        return $project instanceof Project
            && $user !== null
            && Gate::forUser($user)->allows('viewAnyInProject', [Team::class, $project]);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
