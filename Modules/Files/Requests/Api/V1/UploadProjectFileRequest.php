<?php

namespace Modules\Files\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Projects\Models\Project;

class UploadProjectFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The upload Action checks access before storage and again against the locked Project.
        return $this->route('project') instanceof Project && $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:20480',
                // Validate the client extension here; the Action independently
                // verifies the real file signature and Office container structure.
                'extensions:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
            ],
        ];
    }
}
