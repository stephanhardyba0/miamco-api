<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StatusController extends Controller
{
    public function index(Request $request)
    {
        $started = microtime(true);

        $checks = [];

        // Base de données
        try {
            $t0 = microtime(true);
            DB::select('SELECT 1');
            $checks['base de données'] = [
                'ok' => true,
                'detail' => round((microtime(true) - $t0) * 1000).' ms',
            ];
        } catch (\Throwable $e) {
            $checks['base de données'] = ['ok' => false, 'detail' => 'inaccessible'];
        }

        // Cache
        try {
            Cache::put('healthcheck', 1, 10);
            $checks['cache'] = ['ok' => Cache::get('healthcheck') === 1, 'detail' => config('cache.default')];
        } catch (\Throwable $e) {
            $checks['cache'] = ['ok' => false, 'detail' => 'en erreur'];
        }

        // File d'attente (temps réel + jobs)
        try {
            $jobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $checks['file d\'attente'] = [
                'ok' => $failed === 0,
                'detail' => $jobs.' en attente, '.$failed.' échoué(s)',
            ];
        } catch (\Throwable $e) {
            $checks['file d\'attente'] = ['ok' => false, 'detail' => 'inaccessible'];
        }

        // Temps réel (Reverb)
        $reverbPort = (int) (env('REVERB_PORT') ?: 6001);
        $socket = @fsockopen('127.0.0.1', $reverbPort, $errno, $errstr, 1.5);
        if ($socket) {
            fclose($socket);
            $checks['temps réel (websocket)'] = ['ok' => true, 'detail' => '127.0.0.1:'.$reverbPort];
        } else {
            $checks['temps réel (websocket)'] = ['ok' => false, 'detail' => 'port '.$reverbPort.' fermé'];
        }

        // Stockage
        $checks['stockage'] = [
            'ok' => is_writable(storage_path('framework')) && is_writable(storage_path('logs')),
            'detail' => round(disk_free_space(storage_path()) / 1073741824, 1).' Go libres',
        ];

        // Migrations
        try {
            $ran = DB::table('migrations')->count();
            $files = count(glob(database_path('migrations/*.php')));
            $pending = max(0, $files - $ran);
            $checks['migrations'] = ['ok' => $pending === 0, 'detail' => $ran.' appliquée(s), '.$pending.' en attente'];
        } catch (\Throwable $e) {
            $checks['migrations'] = ['ok' => false, 'detail' => 'inaccessible'];
        }

        // Modules en temps réel (volumes)
        $modules = [];
        foreach ([
            'commandes' => 'orders',
            'factures' => 'invoices',
            'paiements' => 'payments',
            'stock' => 'stock_items',
            'menu' => 'menu_items',
            'clients' => 'clients',
            'utilisateurs' => 'users',
        ] as $label => $table) {
            try {
                $modules[$label] = ['ok' => true, 'detail' => number_format(DB::table($table)->count(), 0, ',', ' ').' lignes'];
            } catch (\Throwable $e) {
                $modules[$label] = ['ok' => false, 'detail' => 'table absente'];
            }
        }

        $allOk = ! collect(array_merge($checks, $modules))->contains(fn ($c) => ! $c['ok']);

        $data = [
            'service' => 'Miam & Co API',
            'version' => config('app.version', '1.9a'),
            'status' => $allOk ? 'operational' : 'degraded',
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'environment' => app()->environment(),
            'checks' => $checks,
            'modules' => $modules,
            'updated_at' => now()->toIso8601String(),
            'generated_in_ms' => round((microtime(true) - $started) * 1000),
        ];

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json($data, $allOk ? 200 : 503, ['Cache-Control' => 'no-store, max-age=0']);
        }

        return response()
            ->view('status', $data)
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
