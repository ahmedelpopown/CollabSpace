<?php

namespace Modules\Projects\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectMember;

class AddProjectMemberAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {}

    public function execute(Project $project, User $member, User $addedBy): ProjectMember
    {
        return DB::transaction(function () use ($project, $member, $addedBy): ProjectMember {
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($addedBy)->authorize('assignMembers', $lockedProject);

            $activeMember = User::query()
                ->whereKey($member->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $activeMember) {
                throw new \DomainException('Inactive users cannot be added to a project.');
            }

            $membership = DB::table('project_members')
                ->where('project_id', $lockedProject->getKey())
                ->where('user_id', $member->getKey())
                ->lockForUpdate()
                ->first();

            if ($membership?->membership_status === 'active') {
                return $lockedProject->memberships()
                    ->where('user_id', $member->getKey())
                    ->firstOrFail();
            }

            DB::table('project_members')->updateOrInsert(
                [
                    'project_id' => $lockedProject->getKey(),
                    'user_id' => $member->getKey(),
                ],
                [
                    'membership_status' => 'active',
                    'added_by_user_id' => $addedBy->getKey(),
                    'invited_at' => null,
                    'joined_at' => now(),
                    'removed_at' => null,
                ],
            );

            $this->auditLog->execute(
                $addedBy,
                'project.member.assign',
                $lockedProject,
                oldValues: $membership ? ['membership_status' => $membership->membership_status] : [],
                newValues: ['user_id' => $member->getKey(), 'membership_status' => 'active'],
            );

            return $lockedProject->memberships()
                ->where('user_id', $member->getKey())
                ->firstOrFail();
        });
    }
}
