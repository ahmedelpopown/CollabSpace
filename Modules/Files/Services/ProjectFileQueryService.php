<?php

namespace Modules\Files\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Projects\Models\Project;

class ProjectFileQueryService
{
    /**
     * @return array{recent: \Illuminate\Support\Collection, files: LengthAwarePaginator}
     */
    public function getForProject(Project $project, int $perPage = 12): array
    {
        $query = $this->projectFilesQuery($project);
        $files = (clone $query)->paginate(min(max($perPage, 1), 50));

        // Reuse page one when it contains at least three rows. For smaller page
        // sizes, fetch the three recent files separately so the strip remains
        // independent from pagination size.
        $recent = $files->currentPage() === 1 && $files->perPage() >= 3
            ? $files->getCollection()->take(3)->values()
            : (clone $query)->limit(3)->get();

        return [
            'recent' => $recent,
            'files' => $files,
        ];
    }

    private function projectFilesQuery(Project $project): BelongsToMany
    {
        return $project->files()
            ->with('uploader:id,full_name,job_title,avatar_path')
            ->orderByPivot('attached_at', 'desc')
            ->orderByDesc('files.id');
    }
}
