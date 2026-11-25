<?php

namespace Modules\Files\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Files\Models\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadFileController extends Controller
{
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'zip' => 'application/zip',
    ];

    public function __invoke(File $file): StreamedResponse
    {
        Gate::authorize('view', $file);

        abort_unless(
            $file->storage_disk === 'private'
            && $this->isSafeStoragePath($file->storage_path),
            404,
        );

        $disk = Storage::disk('private');

        abort_unless($disk->exists($file->storage_path), 404);

        $extension = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
        $expectedMime = self::MIME_TYPES[$extension] ?? 'application/octet-stream';

        // Older uploads may have stored OOXML files as application/zip because
        // fileinfo reports the ZIP container MIME. Correct only that known legacy
        // mismatch; never trust an unrelated stored MIME or the filename alone.
        $isLegacyOpenXmlZip = in_array($extension, ['docx', 'xlsx', 'pptx'], true)
            && $file->mime_type === 'application/zip';

        $mimeType = ($file->mime_type === $expectedMime || $isLegacyOpenXmlZip)
            ? $expectedMime
            : 'application/octet-stream';

        $downloadName = basename(str_replace('\\', '/', $file->original_name));
        $downloadName = preg_replace('/[\x00-\x1F\x7F]/', '', $downloadName) ?: 'download';

        return $disk->download($file->storage_path, $downloadName, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function isSafeStoragePath(?string $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        // Uploads are stored using a server-generated UUID below a project folder.
        return preg_match(
            '#\A(?:projects/[0-9]+/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|demo/projects/[0-9]+/[a-z0-9][a-z0-9._-]{0,254})\.(?:pdf|doc|docx|xls|xlsx|ppt|pptx|txt|csv|zip)\z#i',
            $path,
        ) === 1;
    }
}
