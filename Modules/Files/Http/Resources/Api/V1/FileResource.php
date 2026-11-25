<?php

namespace Modules\Files\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Identity\Http\Resources\Api\V1\UserSummaryResource;

/** @mixin \Modules\Files\Models\File */
class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'extension' => strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION)),
            'size_bytes' => (int) $this->size_bytes,
            'size_human' => $this->humanFileSize((int) $this->size_bytes),
            'uploader' => $this->whenLoaded('uploader', fn () => $this->uploader ? UserSummaryResource::make($this->uploader) : null),
            'uploaded_at' => $this->created_at?->toISOString(),
            'attached_at' => $this->pivot?->attached_at?->toISOString(),
            'download_url' => route('api.v1.files.download', ['file' => $this->id], false),
        ];
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;
        foreach ($units as $unit) {
            if ($size < 1024 || $unit === 'TB') {
                return number_format($size, 1).' '.$unit;
            }
            $size /= 1024;
        }

        return number_format($size, 1).' TB';
    }
}
