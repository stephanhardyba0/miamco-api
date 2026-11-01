<?php

namespace App\Console\Commands;

use App\Services\SmartAlertService;
use Illuminate\Console\Command;

class CheckAlerts extends Command
{
    protected $signature = 'miamco:check-alerts';

    protected $description = 'Run all smart alert checks and create notifications';

    public function handle(): int
    {
        $this->info('Running alert checks...');

        $results = SmartAlertService::runAllChecks();

        $this->info('Alert check results:');
        $this->table(
            ['Check', 'Alerts Created'],
            [
                ['Long Orders', $results['long_orders']],
                ['Long Preparation', $results['long_preparation']],
                ['Low Stock', $results['low_stock']],
                ['Cash Discrepancy', $results['cash_discrepancy']],
            ]
        );

        $total = array_sum($results);
        $this->info("Done. {$total} alert(s) created.");

        return self::SUCCESS;
    }
}
