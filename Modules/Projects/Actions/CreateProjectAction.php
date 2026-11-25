<?php

namespace Modules\Projects\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Actions\RecordAuditLogAction;
use Modules\Identity\Models\User;
use Modules\Projects\Models\Project;

class CreateProjectAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {}

    public function execute(User $creator, array $data): Project
    {
        Gate::forUser($creator)->authorize('create', Project::class);

        $memberIds = array_values(array_unique(array_map(
            'intval',
            $data['member_ids'] ?? [],
        )));

        $memberIds = array_values(array_filter(
            $memberIds,
            fn (int $memberId): bool => $memberId !== (int) $creator->getKey(),
        ));

        $attributes = Arr::only($data, [
            'name',
            'description',
            'starts_on',
            'deadline',
            'priority',
        ]);

        return DB::transaction(function () use ($creator, $attributes, $memberIds): Project {
            $project = new Project($attributes);
            $project->created_by_user_id = $creator->getKey();
            $project->save();

            $project->members()->attach($creator->getKey(), [
                'membership_status' => 'active',
                'added_by_user_id' => $creator->getKey(),
                'joined_at' => now(),
            ]);

            $this->auditLog->execute(
                $creator,
                'project.create',
                $project,
                newValues: ['creator_user_id' => $creator->getKey()],
            );

            if ($memberIds === []) {
                return $project;
            }

            Gate::forUser($creator)->authorize('assignMembers', $project);

            $members = User::query()
                ->whereKey($memberIds)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (User $user): int => (int) $user->getKey());

            if ($members->count() !== count($memberIds)) {
                throw ValidationException::withMessages([
                    'member_ids' => ['One or more selected users are inactive or unavailable.'],
                ]);
            }

            $now = now();
            $project->members()->attach($members->mapWithKeys(
                fn (User $member): array => [$member->getKey() => [
                    'membership_status' => 'active',
                    'added_by_user_id' => $creator->getKey(),
                    'joined_at' => $now,
                ]],
            )->all());

            foreach ($members as $member) {
                $this->auditLog->execute(
                    $creator,
                    'project.member.assign',
                    $project,
                    newValues: ['user_id' => $member->getKey()],
                );
            }

            return $project;
        });
    }
}
