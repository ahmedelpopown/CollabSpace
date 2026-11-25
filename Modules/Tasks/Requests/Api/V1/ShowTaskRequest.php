<?php

namespace Modules\Tasks\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Tasks\Models\Task;

class ShowTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task
            && ($this->user()?->can('view', $task) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
