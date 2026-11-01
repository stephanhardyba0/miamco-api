<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinanceRestoreDeleted extends Command
{
    protected $signature = 'finance:restore-deleted
        {--force : Applique réellement la restauration (sans cette option : rapport seul)}
        {--from= : Ne considère que les lignes supprimées à partir de cette date (Y-m-d)}
        {--to= : Ne considère que les lignes supprimées jusqu\'à cette date (Y-m-d)}
        {--with-orders : Inclut aussi les commandes et mouvements de stock supprimés}';

    protected $description = 'Restaure les écritures financières supprimées (soft-delete) : factures, leurs paiements et transactions de caisse liées. Sans --force, affiche uniquement un rapport.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $from = $this->option('from');
        $to = $this->option('to');

        $invoiceIds = $this->deletedQuery('invoices', $from, $to)->pluck('id');
        $paymentIds = DB::table('payments')
            ->whereNotNull('deleted_at')
            ->whereIn('invoice_id', $invoiceIds)
            ->pluck('id');

        $cashTxQuery = DB::table('cash_transactions')
            ->whereNotNull('deleted_at')
            ->where('reference_type', Payment::class);

        if ($paymentIds->isNotEmpty()) {
            $cashTxQuery->whereIn('reference_id', $paymentIds);
        } else {
            $cashTxQuery->whereRaw('1 = 0');
        }

        $cashTxIds = $cashTxQuery->pluck('id');

        $this->info('Écritures supprimées (soft-delete) détectées :');
        $this->table(
            ['Table', 'Lignes', 'Périmètre'],
            [
                ['invoices', $invoiceIds->count(), 'factures supprimées'],
                ['payments', $paymentIds->count(), 'paiements des factures ci-dessus'],
                ['cash_transactions', $cashTxIds->count(), 'transactions de caisse des paiements ci-dessus'],
            ]
        );

        if ($invoiceIds->isNotEmpty()) {
            $this->line('Échantillon de factures :');
            $samples = $this->deletedQuery('invoices', $from, $to)
                ->orderByDesc('deleted_at')
                ->limit(5)
                ->get(['id', 'invoice_number', 'status', 'total', 'deleted_at']);
            $this->table(
                ['id', 'invoice_number', 'status', 'total', 'deleted_at'],
                $samples->map(fn ($s) => [$s->id, $s->invoice_number, $s->status, $s->total, $s->deleted_at])->all()
            );
        }

        $orderIds = collect();
        $stockMovementIds = collect();
        if ($this->option('with-orders')) {
            $orderIds = $this->deletedQuery('orders', $from, $to)->pluck('id');
            $stockMovementIds = $this->deletedQuery('stock_movements', $from, $to)->pluck('id');
            $this->table(
                ['Table', 'Lignes'],
                [
                    ['orders', $orderIds->count()],
                    ['stock_movements', $stockMovementIds->count()],
                ]
            );
        }

        $total = $invoiceIds->count() + $paymentIds->count() + $cashTxIds->count()
            + $orderIds->count() + $stockMovementIds->count();

        if ($total === 0) {
            $this->info('Rien à restaurer.');

            return self::SUCCESS;
        }

        if (! $force) {
            $this->warn('RAPPORT SEULEMENT — relancez avec --force pour restaurer ces lignes.');
            $this->comment('En production : faites d\'abord un backup (mysqldump --single-transaction) puis :');
            $this->comment('  php artisan finance:restore-deleted --force');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($invoiceIds, $paymentIds, $cashTxIds, $orderIds, $stockMovementIds) {
            foreach ([
                'invoices' => $invoiceIds,
                'payments' => $paymentIds,
                'cash_transactions' => $cashTxIds,
                'orders' => $orderIds,
                'stock_movements' => $stockMovementIds,
            ] as $table => $ids) {
                if ($ids->isNotEmpty()) {
                    DB::table($table)->whereIn('id', $ids)->update(['deleted_at' => null]);
                }
            }
        });

        $this->info("Restauré : {$invoiceIds->count()} facture(s), {$paymentIds->count()} paiement(s), {$cashTxIds->count()} transaction(s) de caisse"
            .($this->option('with-orders') ? ", {$orderIds->count()} commande(s), {$stockMovementIds->count()} mouvement(s) de stock" : '').'.');

        return self::SUCCESS;
    }

    private function deletedQuery(string $table, ?string $from, ?string $to)
    {
        $query = DB::table($table)->whereNotNull('deleted_at');

        if ($from) {
            $query->where('deleted_at', '>=', $from);
        }

        if ($to) {
            $query->where('deleted_at', '<=', $to.' 23:59:59');
        }

        return $query;
    }
}
