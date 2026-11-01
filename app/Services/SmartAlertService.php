<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\AppNotification;
use App\Models\CashRegisterSession;
use App\Models\Order;
use App\Models\StockItem;

class SmartAlertService
{
    public static function runAllChecks(): array
    {
        return [
            'long_orders' => static::checkLongOrders(),
            'long_preparation' => static::checkLongPreparation(),
            'low_stock' => static::checkLowStock(),
            'cash_discrepancy' => static::checkCashDiscrepancy(),
        ];
    }

    public static function checkLongOrders(): int
    {
        $threshold = now()->subMinutes(45);

        $orders = Order::where('status', OrderStatus::Pending)
            ->where('created_at', '<=', $threshold)
            ->get();

        foreach ($orders as $order) {
            NotificationService::notifyRoles(
                ['Admin', 'Manager', 'Cuisinier'],
                'Commande en attente depuis longtemps',
                "La commande {$order->order_number} est en attente depuis plus de 45 minutes.",
                'long_order',
                'i-lucide-alert-triangle',
                'warning',
                '/orders',
                ['order_id' => $order->id],
                true,
                'orders',
            );
        }

        return $orders->count();
    }

    public static function checkLongPreparation(): int
    {
        $threshold = now()->subMinutes(30);

        $orders = Order::where('status', OrderStatus::Preparing)
            ->where('updated_at', '<=', $threshold)
            ->get();

        foreach ($orders as $order) {
            NotificationService::notifyRoles(
                ['Admin', 'Manager', 'Serveur'],
                'Préparation trop longue',
                "La commande {$order->order_number} est en préparation depuis plus de 30 minutes.",
                'long_preparation',
                'i-lucide-clock',
                'warning',
                '/orders',
                ['order_id' => $order->id],
                true,
                'orders',
            );
        }

        return $orders->count();
    }

    public static function checkLowStock(): int
    {
        $lowStockItems = StockItem::lowStock()->get();

        foreach ($lowStockItems as $item) {
            // Une seule alerte par article et par 24h : pas de rafale toutes les 5 minutes.
            $alreadyNotified = AppNotification::where('type', 'low_stock')
                ->where('created_at', '>=', now()->subDay())
                ->where('metadata->stock_item_id', $item->id)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            NotificationService::notifyAdmins(
                'Stock bas',
                "Le stock de \"{$item->name}\" est bas ({$item->quantity} {$item->unit}, minimum: {$item->min_quantity} {$item->unit}).",
                'low_stock',
                'i-lucide-package',
                'error',
                '/stock',
                ['stock_item_id' => $item->id],
                true,
            );
        }

        return $lowStockItems->count();
    }

    public static function checkCashDiscrepancy(): int
    {
        $alerts = 0;

        $openSessions = CashRegisterSession::where('status', 'open')
            ->with('transactions')
            ->get();

        foreach ($openSessions as $session) {
            $sales = $session->transactions()
                ->where('type', 'sale')
                ->sum('amount');

            $cashIn = $session->transactions()
                ->where('type', 'deposit')
                ->sum('amount');

            $cashOut = $session->transactions()
                ->where('type', 'expense')
                ->sum('amount');

            $expectedBalance = (float) $session->opening_amount + (float) $sales + (float) $cashIn - (float) $cashOut;

            if ($expectedBalance < 0) {
                NotificationService::notifyAdmins(
                    'Anomalie de caisse',
                    "La session de caisse #{$session->id} présente un solde négatif ({$expectedBalance} FCFA).",
                    'cash_discrepancy',
                    'i-lucide-alert-circle',
                    'error',
                    '/cash-register',
                    ['session_id' => $session->id],
                    true,
                );
                $alerts++;
            }
        }

        return $alerts;
    }
}
