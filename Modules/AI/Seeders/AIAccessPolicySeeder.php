<?php

namespace Modules\AI\Seeders;

use Illuminate\Database\Seeder;
use Modules\AI\Models\AIAccessPolicy;
use Spatie\Permission\Models\Role;

class AIAccessPolicySeeder extends Seeder
{
    private const RESOURCES = ['projects', 'tasks', 'teams', 'meetings', 'reports', 'file_metadata'];

    private const ACTIONS = ['read', 'search', 'summarize', 'analyze', 'generate'];

    private const SCOPES = ['own', 'assigned_teams', 'assigned_projects', 'invited_meetings', 'organization'];

    private const ALLOWED = [
        'Administrator' => ['*' => ['organization']],
        'Project Manager' => [
            'projects' => ['assigned_projects'],
            'tasks' => ['assigned_projects'],
            'teams' => ['assigned_teams'],
            'meetings' => ['assigned_projects'],
            'reports' => ['assigned_projects'],
            'file_metadata' => ['assigned_projects'],
        ],
        'Team Member' => [
            'projects' => ['assigned_projects'],
            'tasks' => ['own'],
            'teams' => ['assigned_teams'],
            'meetings' => ['invited_meetings'],
            'reports' => ['own'],
            'file_metadata' => ['assigned_projects'],
        ],
    ];

    public function run(): void
    {
        foreach (Role::query()->whereIn('name', array_keys(self::ALLOWED))->get() as $role) {
            foreach (self::RESOURCES as $resource) {
                foreach (self::ACTIONS as $action) {
                    foreach (self::SCOPES as $scope) {
                        $allowedScopes = self::ALLOWED[$role->name][$resource]
                            ?? self::ALLOWED[$role->name]['*']
                            ?? [];
                        $enabled = in_array($scope, $allowedScopes, true);

                        AIAccessPolicy::query()->updateOrCreate(
                            [
                                'role_id' => $role->getKey(),
                                'resource_type' => $resource,
                                'action' => $action,
                                'access_scope' => $scope,
                            ],
                            ['is_enabled' => $enabled],
                        );
                    }
                }
            }
        }
    }
}
