<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liste blanche des modules métiers — la meme que celle acceptee
     * par l'API a la creation/compta d'un utilisateur.
     */
    private const MODULES = [
        'pos', 'orders', 'tables', 'zones', 'reservations', 'invoices',
        'cash_registers', 'menu', 'clients', 'stock', 'reports', 'users', 'settings',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Acces aux modules, choisis explicitement par utilisateur.
            // null = ancien compte : on retombe sur les bundles de role.
            $table->json('modules')->nullable()->after('is_active');
        });

        // Reprise des comptes existants : leurs modules = modules distincts
        // des permissions de leur role (pas d'acces perdu au passage).
        $users = DB::table('users')->pluck('id', 'id');

        foreach ($users as $userId) {
            $moduleNames = DB::table('role_permission')
                ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
                ->join('user_role', 'user_role.role_id', '=', 'role_permission.role_id')
                ->where('user_role.user_id', $userId)
                ->pluck('permissions.name')
                ->map(fn (string $name) => explode('.', $name)[0])
                ->unique()
                ->filter(fn (string $module) => in_array($module, self::MODULES, true))
                ->values()
                ->all();

            if ($moduleNames !== []) {
                DB::table('users')->where('id', $userId)->update(['modules' => json_encode($moduleNames)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
