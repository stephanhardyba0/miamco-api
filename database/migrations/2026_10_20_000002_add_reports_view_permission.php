<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Droit dédié `reports.view`.
     *
     * Les rapports étaient protégés par `settings.view`, réservé à Admin et
     * Manager : un caissier ne pouvait donc pas vérifier ses propres ventes
     * du jour dans un logiciel de point de vente.
     *
     * Safe en production : ne fait que créer une permission et synchroniser
     * les pivots role_permission (aucune donnée métier).
     */
    public function up(): void
    {
        Permission::firstOrCreate(
            ['name' => 'reports.view'],
            ['module' => 'reports', 'action' => 'view', 'guard_name' => 'web']
        );

        $this->syncMatrix();
    }

    public function down(): void
    {
        // La permission reste : la retirer couperait l'accès des Admin aux
        // rapports si d'autres permissions personnalisées s'y appuient.
    }

    private function syncMatrix(): void
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
};
