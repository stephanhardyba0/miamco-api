<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Le Tableau de bord devient un module comme un autre (il partageait
     * le garde `module:orders`). Chaque compte qui a deja une liste de
     * modules la complete avec `dashboard` : personne ne perd son ecran
     * d'accueil au deploiement.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE users
            SET modules = JSON_ARRAY_APPEND(modules, '$', 'dashboard')
            WHERE modules IS NOT NULL
              AND JSON_CONTAINS(modules, '"dashboard"') = 0
        SQL);
    }

    public function down(): void
    {
        // Relecture-reecriture cote PHP : MySQL ne permet pas de retirer
        // une valeur d'un tableau JSON proprement en SQL.
        $users = DB::table('users')->whereNotNull('modules')->pluck('modules', 'id');

        foreach ($users as $id => $json) {
            $modules = json_decode($json, true);
            if (! is_array($modules) || ! in_array('dashboard', $modules, true)) {
                continue;
            }

            $remaining = array_values(array_diff($modules, ['dashboard']));
            DB::table('users')->where('id', $id)->update([
                'modules' => $remaining === [] ? null : json_encode($remaining),
            ]);
        }
    }
};
