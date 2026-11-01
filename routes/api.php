<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashRegisterController;
use App\Http\Controllers\Api\V1\CashRegisterSessionController;
use App\Http\Controllers\Api\V1\CashTransactionController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MenuCategoryController;
use App\Http\Controllers\Api\V1\MenuItemController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\StockItemController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\ZoneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Les groupes utilisent le middleware `module:xxx` : il déduit l'action
| (view/create/edit/delete) depuis la méthode HTTP, de sorte qu'une simple
| permission de lecture n'autorise plus les écritures.
*/

Route::prefix('v1')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::put('/profile', [AuthController::class, 'updateProfile']);
            Route::put('/password', [AuthController::class, 'changePassword'])->middleware('throttle:5,1');
        });

        // Users — attribution des 5 roles figes (aucune permission individuelle)
        Route::middleware('module:users')->group(function () {
            Route::apiResource('users', UserController::class);
            Route::post('/users/{id}/toggle-active', [UserController::class, 'toggleActive']);

            // Journal d'activité : qui a fait quoi, quand.
            Route::get('/activity', [ActivityController::class, 'index']);
            Route::get('/activity/stats', [ActivityController::class, 'stats']);
        });

        // Roles & Permissions — LECTURE SEULE (roles figes par code)
        Route::middleware('module:roles')->group(function () {
            Route::get('/roles', [RoleController::class, 'index']);
            Route::get('/roles/{role}', [RoleController::class, 'show']);
            Route::get('/permissions', [PermissionController::class, 'index']);
        });

        // Menu
        Route::middleware('module:menu')->group(function () {
            Route::apiResource('menu/categories', MenuCategoryController::class);
            Route::apiResource('menu/items', MenuItemController::class);
            Route::put('/menu/items/{id}/toggle-availability', [MenuItemController::class, 'toggleAvailability']);
        });

        // Zones
        Route::middleware('module:zones')->group(function () {
            Route::apiResource('zones', ZoneController::class);
        });

        // Tables
        Route::middleware('module:tables')->group(function () {
            Route::apiResource('tables', TableController::class);
            Route::post('/tables/{id}/occupy', [TableController::class, 'occupy']);
            Route::post('/tables/{id}/release', [TableController::class, 'release']);
            Route::post('/tables/{id}/reserve', [TableController::class, 'reserve']);
        });

        // Orders
        Route::middleware('module:orders')->group(function () {
            Route::apiResource('orders', OrderController::class);
            Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
            Route::put('/orders/{id}/cancel', [OrderController::class, 'cancel']);
            Route::put('/orders/{id}/serve', [OrderController::class, 'serve']);
        });

        // Invoices
        Route::middleware('module:invoices')->group(function () {
            Route::apiResource('invoices', InvoiceController::class)->except(['destroy']);
            Route::put('/invoices/{id}/pay', [InvoiceController::class, 'pay']);
            Route::put('/invoices/{id}/unpay', [InvoiceController::class, 'unpay']);
            Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
        });

        // Cash Registers
        Route::middleware('module:cash_registers')->group(function () {
            Route::get('/cash-registers', [CashRegisterController::class, 'index']);
        });

        // Cash Register Sessions
        Route::middleware('module:cash_registers')->group(function () {
            Route::get('/cash-register-sessions', [CashRegisterSessionController::class, 'index']);
            Route::get('/cash-register-sessions/current', [CashRegisterSessionController::class, 'current']);
            Route::get('/cash-register-sessions/{session}', [CashRegisterSessionController::class, 'show']);
            Route::post('/cash-register-sessions/open', [CashRegisterSessionController::class, 'open']);
            Route::put('/cash-register-sessions/{session}/close', [CashRegisterSessionController::class, 'close']);
            Route::post('/cash-register-sessions/{id}/reopen', [CashRegisterSessionController::class, 'reopen']);
            Route::delete('/cash-register-sessions/{id}', [CashRegisterSessionController::class, 'destroy']);
        });

        // Cash Transactions
        Route::middleware('module:cash_registers')->group(function () {
            Route::post('/cash-transactions', [CashTransactionController::class, 'store']);
            Route::get('/cash-transactions', [CashTransactionController::class, 'index']);
            Route::delete('/cash-transactions/{id}', [CashTransactionController::class, 'destroy']);
        });

        // Clients
        Route::middleware('module:clients')->group(function () {
            Route::apiResource('clients', ClientController::class);
            Route::post('/clients/{id}/toggle-active', [ClientController::class, 'toggleActive']);
        });

        // Stock
        Route::middleware('module:stock')->group(function () {
            Route::apiResource('stock-items', StockItemController::class);
            Route::post('/stock-movements', [StockMovementController::class, 'store']);
        });

        // Reservations
        Route::middleware('module:reservations')->group(function () {
            Route::apiResource('reservations', ReservationController::class);
            Route::put('/reservations/{id}/confirm', [ReservationController::class, 'confirm']);
            Route::put('/reservations/{id}/cancel', [ReservationController::class, 'cancel']);
        });

        // Notifications (données propres à l'utilisateur connecté)
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
            Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
            Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
        });

        // POS
        Route::middleware('module:pos')->group(function () {
            Route::post('/pos/sale', [PosController::class, 'sale']);
        });

        // Dashboard — module dedie : l'acces se gere seul par employe,
        // sans exiger le module Commandes avec.
        Route::middleware('module:dashboard')->get('/dashboard', [DashboardController::class, 'index']);

        // Reports
        Route::middleware('module:reports')->get('/reports/financial', [ReportController::class, 'financial']);

        // Sync
        Route::middleware('module:orders')->group(function () {
            Route::get('/sync/changes', [SyncController::class, 'changes']);
            Route::post('/sync/push', [SyncController::class, 'push']);
        });

        // Settings
        Route::middleware('module:settings')->group(function () {
            Route::get('/settings', [SettingController::class, 'index']);
            Route::put('/settings', [SettingController::class, 'update']);
        });
    });
});
