<?php

namespace Modules\Files\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Files\Models\File;
use Modules\Files\Services\UploadedFileContentInspector;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use RuntimeException;
use Throwable;

class UploadProjectFileAction
{
    private const STORAGE_DISK = 'private';

    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
        private readonly UploadedFileContentInspector $contentInspector,
    ) {}

    public function execute(Project $project, UploadedFile $upload, User $uploader): File
    {
        Gate::forUser($uploader)->authorize('uploadFiles', $project);

        $realPath = $upload->getRealPath();

        if (! is_string($realPath) || ! is_file($realPath)) {
            throw new RuntimeException('The uploaded file is not accessible.');
        }

        // Never trust the client filename or MIME header alone. Validate the
        // actual document structure before writing anything to private storage.
        $mimeType = $this->contentInspector->detectDocumentMimeType($upload, $realPath);

        if ($mimeType === null) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file must be a valid supported document. Images are not allowed.'],
            ]);
        }

        $checksum = hash_file('sha256', $realPath);

        if (! is_string($checksum)) {
            throw new RuntimeException('Unable to calculate the uploaded file checksum.');
        }

        $diskConfig = config('filesystems.disks.'.self::STORAGE_DISK);
        if (! is_array($diskConfig) || ($diskConfig['visibility'] ?? 'private') === 'public') {
            throw new RuntimeException('The configured private file disk is missing or publicly visible.');
        }

        // The original extension is retained only after its content has been
        // independently validated by the inspector (not trusted by itself).
        $extension = strtolower($upload->getClientOriginalExtension());
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $directory = 'projects/'.$project->getKey();
        $fileName = Str::uuid()->toString().'.'.$extension;
        $storagePath = $upload->storeAs($directory, $fileName, self::STORAGE_DISK);

        if (! is_string($storagePath) || $storagePath === '') {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        try {
            return DB::transaction(function () use ($project, $upload, $uploader, $storagePath, $checksum, $mimeType): File {
                $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($uploader)->authorize('uploadFiles', $lockedProject);

                $file = new File();
                $file->forceFill([
                    'uploaded_by_user_id' => $uploader->getKey(),
                    'original_name' => $upload->getClientOriginalName(),
                    'storage_disk' => self::STORAGE_DISK,
                    'storage_path' => $storagePath,
                    'mime_type' => $mimeType,
                    'size_bytes' => (int) $upload->getSize(),
                    'checksum_sha256' => $checksum,
                ]);
                $file->save();

                $lockedProject->files()->attach($file->getKey(), [
                    'attached_by_user_id' => $uploader->getKey(),
                    'attached_at' => now(),
                ]);

                $this->auditLog->execute(
                    $uploader,
                    'file.upload.project',
                    $file,
                    newValues: [
                        'project_id' => $lockedProject->getKey(),
                        'original_name' => $file->original_name,
                        'size_bytes' => $file->size_bytes,
                    ],
                );

                return $lockedProject->files()
                    ->with('uploader:id,full_name,job_title,avatar_path')
                    ->whereKey($file->getKey())
                    ->firstOrFail();
            });
        } catch (Throwable $exception) {
            Storage::disk(self::STORAGE_DISK)->delete($storagePath);
            throw $exception;
        }
    }
}
