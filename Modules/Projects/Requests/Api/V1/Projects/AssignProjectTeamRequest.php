<?php

namespace Modules\Projects\Requests\Api\V1\Projects;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Projects\Models\Project;

class AssignProjectTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('project') instanceof Project && $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'team_id' => ['required', 'integer'],
        ];
    }
}
