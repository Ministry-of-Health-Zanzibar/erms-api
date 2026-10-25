<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSIONS = [
        'View Telemedicine',
        'Create Telemedicine Request',
        'Schedule Telemedicine Appointment',
        'Review Telemedicine Appointment',
        'Manage Telemedicine Report',
        'Record Telemedicine Decision',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        $rolePermissions = [
            'ROLE DIRECTOR GENERAL' => ['View Telemedicine'],
            'ROLE MKURUGENZI TIBA' => ['View Telemedicine', 'Create Telemedicine Request'],
            'ROLE BILL VERIFICATION OFFICER' => ['View Telemedicine', 'Schedule Telemedicine Appointment'],
            'ROLE HOSPITAL USER' => [
                'View Telemedicine',
                'Create Telemedicine Request',
                'Review Telemedicine Appointment',
                'Manage Telemedicine Report',
                'Record Telemedicine Decision',
            ],
            'ROLE TELEMEDICINE COORDINATOR' => self::PERMISSIONS,
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            $existingPermissionNames = $role->permissions->pluck('name')->all();
            $role->syncPermissions(array_values(array_unique([
                ...$existingPermissionNames,
                ...$permissionNames,
            ])));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::where('name', $permissionName)
                ->where('guard_name', 'web')
                ->delete();
        }

        if (Schema::hasTable('roles')) {
            Role::where('name', 'ROLE TELEMEDICINE COORDINATOR')
                ->where('guard_name', 'web')
                ->delete();
        }
    }
};
