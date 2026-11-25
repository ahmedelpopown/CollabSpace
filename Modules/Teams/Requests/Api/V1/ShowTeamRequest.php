<?php

namespace Modules\Teams\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Teams\Models\Team;

class ShowTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team
            && ($this->user()?->can('view', $team) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
