<?php

namespace Modules\Files\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Modules\Files\Models\File;
use Modules\Projects\Models\Project;

class DemoFilesSeeder extends Seeder
{
    private const FILES_PER_PROJECT = 6;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $disk = Storage::disk('private');
        $projects = Project::query()->with(['activeMembers', 'tasks'])->get();

        DB::transaction(function () use ($projects, $disk): void {
            foreach ($projects as $project) {
                $uploader = $project->activeMembers->first();

                if (! $uploader) {
                    throw new LogicException("Project {$project->getKey()} has no active uploader.");
                }

                for ($number = 1; $number <= self::FILES_PER_PROJECT; $number++) {
                    $originalName = sprintf('%s-%02d-project-document.txt', str($project->name)->slug(), $number);
                    $path = sprintf('demo/projects/%d/%s', $project->getKey(), $originalName);
                    $contents = implode("\n", [
                        'CollabSpace Demo Document',
                        'Project: '.$project->name,
                        'Document: '.$originalName,
                        'Generated for local development and API testing.',
                        'This file is private and must be accessed through the authenticated download endpoint.',
                    ]);

                    $disk->put($path, $contents);

                    $file = File::withTrashed()->firstOrNew([
                        'storage_disk' => 'private',
                        'storage_path' => $path,
                    ]);

                    if ($file->trashed()) {
                        $file->restore();
                    }

                    $file->forceFill([
                        'uploaded_by_user_id' => $uploader->getKey(),
                        'original_name' => $originalName,
                        'storage_disk' => 'private',
                        'storage_path' => $path,
                        'mime_type' => 'text/plain',
                        'size_bytes' => strlen($contents),
                        'checksum_sha256' => hash('sha256', $contents),
                    ])->save();

                    $project->files()->syncWithoutDetaching([
                        $file->getKey() => [
                            'attached_by_user_id' => $uploader->getKey(),
                            'attached_at' => now()->subDays($number),
                        ],
                    ]);

                    $task = $project->tasks->get(($number - 1) % max(1, $project->tasks->count()));
                    if ($task) {
                        $task->files()->syncWithoutDetaching([
                            $file->getKey() => [
                                'attached_by_user_id' => $uploader->getKey(),
                                'attached_at' => now()->subDays($number),
                            ],
                        ]);
                    }
                }
            }
        });
    }

    private function ensureDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo files may only be seeded in local or testing environments.');
        }

        $disk = config('filesystems.disks.private');
        if (! is_array($disk) || ($disk['visibility'] ?? 'private') === 'public') {
            throw new LogicException('The private filesystem disk must be configured before seeding demo files.');
        }
    }
}
