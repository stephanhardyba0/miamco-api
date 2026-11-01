<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Applique la matrice de permissions par rôle. Safe en production :
     * ne fait que synchroniser les pivots role_permission (aucune donnée métier).
     */
    public function up(): void
    {
        $allPermissions = Permission::pluck('id')->toArray();

        foreach (RolePermissionMatrix::map() as $name => $permissionNames) {
            $permissionIds = $permissionNames === '*'
                ? $allPermissions
                : Permission::whereIn('name', $permissionNames)->pluck('id')->toArray();

            $role = Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $name]
            );
            $role->permissions()->sync($permissionIds);
        }
    }

    public function down(): void
    {
        //
    }
};
