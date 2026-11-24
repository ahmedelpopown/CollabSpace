<?php

namespace Modules\AccessControl\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlSeeder extends Seeder
{
    private const PERMISSIONS = [
        'users.view' => 'View user accounts.',
        'users.create' => 'Create user accounts.',
        'users.update' => 'Update user accounts.',
        'users.delete' => 'Delete user accounts.',
        'roles.manage' => 'Manage global roles.',
        'permissions.manage' => 'Manage global permissions.',
        'teams.view.assigned' => 'View teams the user belongs to or can access.',
        'teams.view.all' => 'View all teams.',
        'teams.create' => 'Create teams.',
        'teams.update' => 'Update teams.',
        'teams.delete' => 'Delete teams.',
        'teams.members.assign' => 'Add or remove team members.',
        'projects.view' => 'View projects the user is authorized to access.',
        'projects.create' => 'Create projects.',
        'projects.update' => 'Update authorized projects.',
        'projects.delete' => 'Delete projects.',
        'projects.members.assign' => 'Explicitly assign project members.',
        'projects.teams.assign' => 'Assign teams to projects.',
        'tasks.view' => 'View tasks within the permitted project or assignment scope.',
        'tasks.create' => 'Create tasks.',
        'tasks.update' => 'Update tasks.',
        'tasks.delete' => 'Delete tasks.',
        'tasks.assign' => 'Assign tasks to active project members.',
        'tasks.status.update.assigned' => 'Update status of tasks assigned to the current user.',
        'files.view' => 'View permitted file metadata and files.',
        'files.upload' => 'Upload permitted project files.',
        'chat.read' => 'Read project chat where project access is granted.',
        'chat.send' => 'Send messages in projects the user can access.',
        'chat.edit.own' => 'Edit the current user’s own chat messages.',
        'chat.delete.own' => 'Delete the current user’s own chat messages.',
        'meetings.view' => 'View meetings in the permitted project or invitation scope.',
        'meetings.create' => 'Schedule project meetings.',
        'meetings.reschedule' => 'Reschedule project meetings.',
        'meetings.cancel' => 'Cancel project meetings.',
        'meetings.invite' => 'Invite eligible members to project meetings.',
        'meetings.attend' => 'Attend eligible, invited meetings.',
        'reports.view.all' => 'View all reports.',
        'reports.view.project' => 'View reports for authorized projects.',
        'reports.view.own' => 'View personal reports.',
        'reports.generate.project' => 'Generate project reports.',
        'analytics.view.project' => 'View analytics for authorized projects.',
        'ai.use' => 'Use AI subject to enabled resource policies and data scope.',
        'ai.configure' => 'Configure AI settings and access policies.',
        'settings.manage' => 'Manage system settings.',
        'audit.view' => 'View audit logs.',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $definitions = [
                'Administrator' => [
                    'description' => 'System-wide administration.',
                    'permissions' => array_keys(self::PERMISSIONS),
                ],
                'Project Manager' => [
                    'description' => 'Manage projects with explicit active project membership.',
                    'permissions' => [
                        'projects.view', 'projects.create', 'projects.update',
                        'projects.members.assign', 'projects.teams.assign',
                        'teams.view.assigned', 'reports.view.project',
                        'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.assign',
                        'files.view', 'files.upload', 'chat.read', 'chat.send', 'chat.edit.own', 'chat.delete.own',
                        'meetings.view', 'meetings.create', 'meetings.reschedule', 'meetings.cancel', 'meetings.invite',
                        'reports.generate.project', 'analytics.view.project', 'ai.use',
                    ],
                ],
                'Team Member' => [
                    'description' => 'Access explicitly assigned projects, tasks, chat, files, and eligible meetings.',
                    'permissions' => [
                        'teams.view.assigned', 'projects.view', 'tasks.view', 'tasks.status.update.assigned',
                        'files.view', 'files.upload', 'chat.read', 'chat.send', 'chat.edit.own', 'chat.delete.own',
                        'meetings.view', 'meetings.attend', 'reports.view.own', 'ai.use',
                    ],
                ],
            ];

            $permissions = collect(self::PERMISSIONS)->map(function (string $description, string $name) {
                $permission = Permission::query()->firstOrCreate([
                    'name' => $name,
                    'guard_name' => 'web',
                ]);
                $permission->forceFill(['description' => $description])->save();

                return $permission;
            });

            foreach ($definitions as $name => $definition) {
                $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                $role->forceFill([
                    'description' => $definition['description'],
                    'is_system_role' => true,
                ])->save();
                $role->syncPermissions($permissions->whereIn('name', $definition['permissions'])->values());
            }

        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
