<?php

namespace Modules\AI\Authorization;

use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Modules\AI\Models\AIActivityLog;
use Modules\AI\Models\AIConversation;
use Modules\AI\Models\AIAccessPolicy;
use Modules\AI\Models\AISetting;
use Modules\Audit\Models\AuditLog;
use Modules\Files\Models\File;
use Modules\Identity\Models\User;
use Modules\Meetings\Models\Meeting;
use Modules\Projects\Models\Project;
use Modules\Reports\Models\ReportExport;
use Modules\Tasks\Models\Task;
use Modules\Teams\Models\Team;

class AIResourceAccess
{
    private const RESOURCE_MODELS = [
        'projects' => Project::class,
        'tasks' => Task::class,
        'teams' => Team::class,
        'meetings' => Meeting::class,
        'reports' => ReportExport::class,
        'file_metadata' => File::class,
    ];

    private const ACTIONS = ['read', 'search', 'summarize', 'analyze', 'generate'];

    public function queryFor(
        User $user,
        string $resource,
        string $action,
        ?AIConversation $conversation = null,
    ): Builder {
        $policies = collect();
        $denial = null;

        if (! $user->is_active
            || $user->trashed()
            || ! $user->can('ai.use')
            || ! $this->hasResourcePermission($user, $resource)
            || ! AISetting::query()->whereKey(1)->where('is_enabled', true)->exists()
            || ! isset(self::RESOURCE_MODELS[$resource])
            || ! \in_array($action, self::ACTIONS, true)) {
            $denial = 'invalid_or_inactive_request';
        } elseif ($conversation && (int) $conversation->created_by_user_id !== (int) $user->getKey()) {
            $denial = 'conversation_not_owned';
        } elseif ($conversation && $conversation->project_id !== null
            && (! $conversation->project || ! Gate::forUser($user)->allows('view', $conversation->project))) {
            $denial = 'conversation_project_not_accessible';
        } else {
            $roleIds = $user->roles()->pluck('roles.id');
            $policies = AIAccessPolicy::query()
                ->whereIn('role_id', $roleIds)
                ->where('resource_type', $resource)
                ->where('action', $action)
                ->where('is_enabled', true)
                ->get();

            if ($policies->isEmpty()) {
                $denial = 'no_enabled_policy';
            }
        }

        if ($denial !== null) {
            $this->deny($user, $conversation, $resource, $denial);
        }

        $model = self::RESOURCE_MODELS[$resource];
        $query = $model::query();

        if ($resource === 'file_metadata') {
            $query->select([
                'files.id', 'files.original_name', 'files.mime_type', 'files.size_bytes',
                'files.checksum_sha256', 'files.uploaded_by_user_id', 'files.created_at',
            ]);
        }

        $hasOrganizationScope = $policies->contains(
            fn (AIAccessPolicy $policy) => $policy->access_scope === 'organization'
                && $user->hasRole('Administrator'),
        );

        $scopedPolicies = $policies->filter(
            fn (AIAccessPolicy $policy) => $this->scopeApplies($user, $resource, $policy->access_scope),
        )->values();

        if (! $hasOrganizationScope && $scopedPolicies->isEmpty()) {
            $this->deny($user, $conversation, $resource, 'unsupported_or_out_of_scope');
        }

        if (! $hasOrganizationScope) {
            $query->where(function (Builder $outer) use ($scopedPolicies, $user, $resource): void {
                foreach ($scopedPolicies as $index => $policy) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $outer->{$method}(fn (Builder $scope) => $this->applyScope(
                        $scope,
                        $user,
                        $resource,
                        $policy->access_scope,
                    ));
                }
            });
        }

        if ($conversation?->project_id !== null) {
            $this->applyConversationProjectScope($query, $resource, (int) $conversation->project_id);
        }

        $this->logDecision($user, $conversation, $resource, 'access.allowed', 'policy_and_scope_matched');

        return $query;
    }

    private function scopeApplies(User $user, string $resource, string $scope): bool
    {
        return match ($scope) {
            'organization' => $user->hasRole('Administrator'),
            'assigned_projects' => \in_array($resource, ['projects', 'tasks', 'meetings', 'reports', 'file_metadata'], true),
            'assigned_teams' => $resource === 'teams',
            'own' => \in_array($resource, ['tasks', 'reports'], true),
            'invited_meetings' => $resource === 'meetings',
            default => false,
        };
    }

    private function applyScope(Builder $query, User $user, string $resource, string $scope): void
    {
        if ($scope === 'assigned_projects') {
            match ($resource) {
                'projects' => $query->whereHas('memberships', fn (Builder $members) => $members
                    ->where('user_id', $user->getKey())->active()),
                'tasks' => $query->whereHas('project.memberships', fn (Builder $members) => $members
                    ->where('user_id', $user->getKey())->active()),
                'meetings' => $query->whereHas('project.memberships', fn (Builder $members) => $members
                    ->where('user_id', $user->getKey())->active()),
                'reports' => $query->whereNotNull('project_id')
                    ->whereHas('project.memberships', fn (Builder $members) => $members
                        ->where('user_id', $user->getKey())->active()),
                'file_metadata' => $query->where(function (Builder $files) use ($user) {
                    $files->whereHas('projects.memberships', fn (Builder $members) => $members
                        ->where('user_id', $user->getKey())->active())
                        ->orWhereHas('tasks.project.memberships', fn (Builder $members) => $members
                            ->where('user_id', $user->getKey())->active())
                        ->orWhereHas('messageAttachments.message.project.memberships', fn (Builder $members) => $members
                            ->where('user_id', $user->getKey())->active());
                }),
                default => null,
            };

            return;
        }

        if ($scope === 'assigned_teams') {
            $query->where('is_active', true);

            if ($user->hasRole('Project Manager')) {
                $query->whereHas('projects.memberships', fn (Builder $members) => $members
                    ->where('user_id', $user->getKey())->active());
            } else {
                $query->whereHas('memberships', fn (Builder $members) => $members
                    ->where('user_id', $user->getKey())->active());
            }
            return;
        }

        if ($scope === 'own' && $resource === 'tasks') {
            $query->whereHas('assignees', fn (Builder $assignees) => $assignees->whereKey($user->getKey()))
                ->whereHas('project.memberships', fn (Builder $members) => $members
                    ->where('user_id', $user->getKey())->active());

            return;
        }

        if ($scope === 'own' && $resource === 'reports') {
            $query->where('created_by_user_id', $user->getKey())
                ->where('report_type', 'user')
                ->whereNull('project_id');

            return;
        }

        if ($scope === 'invited_meetings') {
            $query->whereExists(function ($participants) use ($user) {
                $participants->selectRaw('1')
                    ->from('meeting_participants')
                    ->join('projects', 'projects.id', '=', 'meetings.project_id')
                    ->whereNull('projects.deleted_at')
                    ->join('project_teams', function ($join) {
                        $join->on('project_teams.project_id', '=', 'meetings.project_id')
                            ->on('project_teams.team_id', '=', 'meeting_participants.eligible_team_id');
                    })
                    ->join('team_members', function ($join) use ($user) {
                        $join->on('team_members.team_id', '=', 'project_teams.team_id')
                            ->where('team_members.user_id', '=', $user->getKey())
                            ->where('team_members.membership_status', '=', 'active');
                    })
                    ->join('teams', function ($join) {
                        $join->on('teams.id', '=', 'team_members.team_id')
                            ->where('teams.is_active', '=', true)
                            ->whereNull('teams.deleted_at');
                    })
                    ->whereColumn('meeting_participants.meeting_id', 'meetings.id')
                    ->whereColumn('meeting_participants.eligible_team_id', 'project_teams.team_id')
                    ->whereColumn('meeting_participants.user_id', 'team_members.user_id')
                    ->whereNull('meeting_participants.access_revoked_at')
                    ->where('meeting_participants.response_status', '!=', 'declined')
                    ->whereNull('meetings.cancelled_at')
                    ->where('meetings.status', '!=', 'cancelled');
            });
        }
    }

    private function applyConversationProjectScope(Builder $query, string $resource, int $projectId): void
    {
        match ($resource) {
            'projects' => $query->whereKey($projectId),
            'tasks', 'meetings', 'reports' => $query->where('project_id', $projectId),
            'teams' => $query->whereHas('projects', fn (Builder $projects) => $projects->whereKey($projectId)),
            'file_metadata' => $query->where(function (Builder $files) use ($projectId): void {
                $files->whereHas('projects', fn (Builder $projects) => $projects->whereKey($projectId))
                    ->orWhereHas('tasks', fn (Builder $tasks) => $tasks->where('project_id', $projectId))
                    ->orWhereHas('messageAttachments.message', fn (Builder $messages) => $messages
                        ->where('project_id', $projectId));
            }),
            default => throw new AuthorizationException('Permission denied for the requested AI resource.'),
        };
    }

    private function hasResourcePermission(User $user, string $resource): bool
    {
        $permission = match ($resource) {
            'projects' => 'projects.view',
            'tasks' => 'tasks.view',
            'teams' => $user->hasRole('Administrator') ? 'teams.view.all' : 'teams.view.assigned',
            'meetings' => 'meetings.view',
            'reports' => $user->hasRole('Administrator')
                ? 'reports.view.all'
                : ($user->hasRole('Project Manager') ? 'reports.view.project' : 'reports.view.own'),
            'file_metadata' => 'files.view',
            default => null,
        };

        return $permission !== null && $user->can($permission);
    }

    private function logDecision(
        User $user,
        ?AIConversation $conversation,
        string $resource,
        string $decision,
        string $reason,
    ): void {
        AIActivityLog::create([
            'user_id' => $user->getKey(),
            'conversation_id' => $conversation?->getKey(),
            'action' => $decision,
            'resource_type' => $resource,
            'metadata' => ['reason' => $reason],
            'created_at' => now(),
        ]);

        if ($decision === 'access.allowed' && $user->hasRole('Administrator')) {
            AuditLog::create([
                'actor_user_id' => $user->getKey(),
                'action' => 'admin.ai.resource.access',
                'subject_type' => $resource,
                'new_values' => ['action' => $decision, 'reason' => $reason],
            ]);
        }
    }

    private function deny(User $user, ?AIConversation $conversation, string $resource, string $reason): never
    {
        $this->logDecision($user, $conversation, $resource, 'access.denied', $reason);

        throw new AuthorizationException('Permission denied for the requested AI resource.');
    }
}
