<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bascule les horodatages stockés d'UTC vers le fuseau de Brazzaville.
     *
     * Changer `config/app.php` ne suffit pas : Laravel écrit dans la base la
     * valeur de `Carbon::now()` telle quelle, sans conversion. Les lignes déjà
     * écrites sont donc des instants UTC que l'application, désormais en
     * UTC+1, relirait une heure trop tôt — exactement le décalage qu'on veut
     * faire disparaître.
     *
     * Après ce décalage, seules les lignes situées en 23h UTC (00h à
     * Brazzaville) changent de journée : 5 paiements, 5 factures, 3 mouvements
     * de caisse et 5 commandes sur la production actuelle.
     *
     * Seules les tables métier sont touchées : les tables de framework
     * (jobs, cache, sessions) portent des horodatages entiers ou servent à
     * l'expiration, pas à la comptabilité.
     */
    private const SYSTEM_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'notifications',
        'personal_access_tokens',
    ];

    public function up(): void
    {
        $this->shift('1 HOUR');
    }

    public function down(): void
    {
        $this->shift('-1 HOUR');
    }

    private function shift(string $interval): void
    {
        $columns = DB::select(
            "SELECT table_name AS tbl, column_name AS col
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND data_type IN ('datetime', 'timestamp')
             ORDER BY table_name, ordinal_position"
        );

        foreach ($columns as $column) {
            if (in_array($column->tbl, self::SYSTEM_TABLES, true)) {
                continue;
            }

            $table = '`'.str_replace('`', '', $column->tbl).'`';
            $col = '`'.str_replace('`', '', $column->col).'`';

            DB::statement(
                "UPDATE {$table} SET {$col} = DATE_ADD({$col}, INTERVAL {$interval}) WHERE {$col} IS NOT NULL"
            );
        }
    }
};
