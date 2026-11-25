<?php

namespace Modules\Projects\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Tasks\Actions\RevokeTaskAssignmentsAction;

class RemoveProjectMemberAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
        private readonly RevokeTaskAssignmentsAction $revokeTaskAssignments,
    ) {}

    public function execute(Project $project, User $member, User $removedBy): void
    {
        DB::transaction(function () use ($project, $member, $removedBy): void {
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($removedBy)->authorize('assignMembers', $lockedProject);

            $membership = DB::table('project_members')
                ->where('project_id', $lockedProject->getKey())
                ->where('user_id', $member->getKey())
                ->lockForUpdate()
                ->first();

            if (! $membership || $membership->membership_status !== 'active') {
                throw new \DomainException('The user is not an active member of this project.');
            }

            DB::table('project_members')
                ->where('project_id', $lockedProject->getKey())
                ->where('user_id', $member->getKey())
                ->update([
                    'membership_status' => 'removed',
                    'removed_at' => now(),
                ]);

            $this->revokeTaskAssignments->forProject($lockedProject, $member, $removedBy);

            $this->auditLog->execute(
                $removedBy,
                'project.member.remove',
                $lockedProject,
                oldValues: ['user_id' => $member->getKey(), 'membership_status' => 'active'],
                newValues: ['membership_status' => 'removed'],
            );
        });
    }
}
