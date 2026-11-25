<?php

namespace Modules\Reports\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Modules\Files\Models\File;
use Modules\Projects\Models\Project;
use Modules\Reports\Models\ReportExport;

class DemoReportsSeeder extends Seeder
{
    private const REPORT_TYPES = ['summary', 'tasks', 'team', 'files'];

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $disk = Storage::disk('private');
        $projects = Project::query()->with('activeMembers')->get();

        DB::transaction(function () use ($projects, $disk): void {
            foreach ($projects as $project) {
                $creator = $project->activeMembers->first();
                if (! $creator) {
                    continue;
                }

                foreach (self::REPORT_TYPES as $index => $type) {
                    $originalName = sprintf('%s-%s-report.txt', str($project->name)->slug(), $type);
                    $path = sprintf('demo/reports/%d/%s', $project->getKey(), $originalName);
                    $contents = implode("\n", [
                        'CollabSpace Demo Report',
                        'Project: '.$project->name,
                        'Report type: '.$type,
                        'Generated at: '.now()->toDateTimeString(),
                        'This is a local-only report artifact for API and UI testing.',
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
                        'uploaded_by_user_id' => $creator->getKey(),
                        'original_name' => $originalName,
                        'storage_disk' => 'private',
                        'storage_path' => $path,
                        'mime_type' => 'text/plain',
                        'size_bytes' => strlen($contents),
                        'checksum_sha256' => hash('sha256', $contents),
                    ])->save();

                    $project->files()->syncWithoutDetaching([
                        $file->getKey() => [
                            'attached_by_user_id' => $creator->getKey(),
                            'attached_at' => now()->subDays($index),
                        ],
                    ]);

                    ReportExport::query()->firstOrCreate(
                        [
                            'created_by_user_id' => $creator->getKey(),
                            'project_id' => $project->getKey(),
                            'report_type' => $type,
                        ],
                        [
                            'file_id' => $file->getKey(),
                            'parameters' => [
                                'source' => 'demo-seeder',
                                'project_id' => $project->getKey(),
                                'range' => 'last_30_days',
                            ],
                            'status' => 'completed',
                            'generated_at' => now()->subDays($index),
                            'created_at' => now()->subDays($index),
                        ],
                    );
                }
            }
        });
    }

    private function ensureDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo reports may only be seeded in local or testing environments.');
        }

        $disk = config('filesystems.disks.private');
        if (! is_array($disk) || ($disk['visibility'] ?? 'private') === 'public') {
            throw new LogicException('The private filesystem disk must be configured before seeding demo reports.');
        }
    }
}
