<?php

namespace App\Services;

use App\Enums\CashTransactionType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Events\OrderSaved;
use App\Models\CashTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class OrderWorkflowService
{
    private function statusRank(OrderStatus $status): ?int
    {
        return match ($status) {
            OrderStatus::Pending => 0,
            OrderStatus::Confirmed => 1,
            OrderStatus::Preparing => 2,
            OrderStatus::Ready => 3,
            OrderStatus::Served => 4,
            OrderStatus::Completed => 5,
            OrderStatus::Cancelled => null,
        };
    }

    public function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        if ($to === OrderStatus::Cancelled) {
            return ! in_array($from, [OrderStatus::Completed, OrderStatus::Cancelled], true);
        }

        if (in_array($from, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
            return false;
        }

        $fromRank = $this->statusRank($from);
        $toRank = $this->statusRank($to);

        return $fromRank !== null && $toRank !== null && $toRank > $fromRank;
    }

    public function getValidTransitions(OrderStatus $current): array
    {
        return collect(OrderStatus::cases())
            ->filter(fn (OrderStatus $status) => $this->canTransition($current, $status))
            ->values()
            ->all();
    }

    public function createOrder(array $data, $user): Order
    {
        $order = DB::transaction(function () use ($data, $user) {
            $orderNumber = $this->generateOrderNumber();

            // Les prix sont toujours repris du catalogue côté serveur : la valeur
            // envoyée par le client (POS ou sync offline) n'est jamais fiable.
            $lines = collect($data['items'])->map(function ($item) {
                $menuItem = MenuItem::findOrFail($item['menu_item_id']);
                $quantity = (int) $item['quantity'];
                $unitPrice = (float) $menuItem->price;

                return [
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $quantity,
                ];
            });

            $total = $lines->sum('total_price');
            $discount = max(0, (float) ($data['discount'] ?? 0));
            if ($discount > $total) {
                $discount = $total;
            }

            $order = Order::create([
                'order_number' => $orderNumber,
                'table_id' => $data['table_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'notes' => $data['notes'] ?? null,
                'total' => $total - $discount,
                'discount' => $discount,
            ]);

            foreach ($lines as $line) {
                $order->orderItems()->create($line);
            }

            $order->load(['table', 'client', 'user', 'orderItems.menuItem']);

            $this->dispatchOrderCreatedNotifications($order);

            return $order;
        });

        OrderSaved::dispatch($order, 'created');

        return $order;
    }

    public function transitionStatus(Order $order, OrderStatus $newStatus, ?int $actorId = null): Order
    {
        if (! $this->canTransition($order->status, $newStatus)) {
            throw new \InvalidArgumentException(
                "Transition invalide : {$order->status->value} → {$newStatus->value}"
            );
        }

        $oldStatus = $order->status;

        /*
         * Toute la bascule (statut, facture, stock) tient dans une seule
         * transaction : un échec au milieu ne doit ni laisser une facture
         * sans stock déduit, ni un stock déduit sans facture.
         */
        DB::transaction(function () use ($order, $newStatus, $actorId) {
            $order->update(['status' => $newStatus]);

            if ($newStatus === OrderStatus::Served && ! $order->invoice) {
                $this->createInvoiceForOrder($order);
            }

            if ($newStatus === OrderStatus::Cancelled) {
                $this->settleOrderCancellation($order, $actorId);
                $this->releaseTable($order);
            }
        });

        $this->dispatchStatusChangeNotifications($order, $oldStatus, $newStatus);

        OrderSaved::dispatch($order, 'updated');

        return $order;
    }

    public function cancelOrder(Order $order, ?int $actorId = null): Order
    {
        if (! $this->canTransition($order->status, OrderStatus::Cancelled)) {
            throw new \InvalidArgumentException(
                "Impossible d'annuler une commande avec le statut : {$order->status->value}"
            );
        }

        $oldStatus = $order->status;

        DB::transaction(function () use ($order, $actorId) {
            $order->update(['status' => OrderStatus::Cancelled]);
            $this->settleOrderCancellation($order, $actorId);
            $this->releaseTable($order);
        });

        $this->dispatchStatusChangeNotifications($order, $oldStatus, OrderStatus::Cancelled);

        OrderSaved::dispatch($order, 'updated');

        return $order;
    }

    public function forceServe(Order $order): Order
    {
        if (in_array($order->status, [OrderStatus::Served, OrderStatus::Completed, OrderStatus::Cancelled], true)) {
            throw new \InvalidArgumentException(
                "Cette commande a déjà été traitée (statut : {$order->status->value})."
            );
        }

        $oldStatus = $order->status;

        DB::transaction(function () use ($order) {
            $order->update(['status' => OrderStatus::Served]);

            if (! $order->invoice) {
                $this->createInvoiceForOrder($order);
            }

            $this->releaseTable($order);
        });

        $this->dispatchStatusChangeNotifications($order, $oldStatus, OrderStatus::Served);

        OrderSaved::dispatch($order, 'updated');

        return $order;
    }

    /**
     * Défait facture, paiements, caisse et fidélité lors d'une annulation.
     *
     * Sans ce règlement, annuler une commande payée laissait le CA, la
     * caisse et les points de fidélité crédités pour un service jamais
     * rendu, et la facture restait « impayée » à jamais.
     *
     * Règle de caisse : la ligne `sale` fait partie du journal d'une
     * session — on ne la réécrit pas. Le remboursement part en `refund`
     * sur la caisse actuellement ouverte, là où la monnaie quitte
     * physiquement le tiroir. Si aucun tiroir n'est ouverte alors que la
     * vente était en espèces, l'annulation est refusée plutôt que de
     * laisser le tiroir crédité à tort.
     */
    private function settleOrderCancellation(Order $order, ?int $actorId = null): void
    {
        $invoice = $order->invoice()->with('payments')->first();

        if (! $invoice) {
            return;
        }

        $payments = $invoice->payments()->get();

        if ($payments->isNotEmpty()) {
            $cashSales = CashTransaction::query()
                ->where('reference_type', Payment::class)
                ->whereIn('reference_id', $payments->pluck('id')->all())
                ->where('type', CashTransactionType::Sale->value)
                ->get();

            if ($cashSales->isNotEmpty()) {
                $cashService = app(CashRegisterWorkflowService::class);

                try {
                    $openSession = $cashService->resolveOpenSessionForSale($order->user_id);
                } catch (\InvalidArgumentException) {
                    throw new \InvalidArgumentException(
                        'Annulation impossible : le remboursement en espèces exige une caisse ouverte. Ouvrez une caisse, puis réessayez.'
                    );
                }

                foreach ($cashSales as $cashSale) {
                    $cashService->addTransaction(
                        session: $openSession,
                        type: CashTransactionType::Refund,
                        amount: (float) $cashSale->amount,
                        userId: $actorId ?? $order->user_id,
                        reason: "Annulation commande {$order->order_number}",
                        paymentMethod: $cashSale->payment_method,
                    );
                }
            }

            $this->reverseClientLoyalty($invoice->client_id, $payments);

            $payments->each->delete();
        }

        $invoice->update(['status' => InvoiceStatus::Cancelled, 'paid_at' => null]);

        app(StockLedgerService::class)->restoreForOrder(
            $order,
            "Annulation commande {$order->order_number}",
            $actorId
        );
    }

    /**
     * Annule exactement ce qu'ont crédité les incréments de fidélité
     * (paiement par paiement : les points sont arrondis à l'unité chacun).
     */
    private function reverseClientLoyalty(?int $clientId, $payments): void
    {
        if (! $clientId || $payments->isEmpty()) {
            return;
        }

        $client = Client::find($clientId);
        if (! $client) {
            return;
        }

        $client->decrement('visits', $payments->count());
        $client->decrement('loyalty_points', (int) $payments->sum(fn (Payment $p) => floor((float) $p->amount / 1000)));
        $client->total_spent = max(0, (float) $client->total_spent - (float) $payments->sum('amount'));
        $client->save();
    }

    private function dispatchOrderCreatedNotifications(Order $order): void
    {
        $tableName = $order->table?->name ?? 'N/A';

        NotificationService::notifyRole(
            roleName: 'Cuisinier',
            title: "Nouvelle commande #{$order->order_number}",
            message: "Nouvelle commande pour la table {$tableName}.",
            type: 'order',
            icon: 'clipboard-list',
            color: 'info',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id, 'table_name' => $tableName],
        );

        NotificationService::notifyAdmins(
            title: "Nouvelle commande #{$order->order_number}",
            message: "Commande créée par {$order->user->name} pour la table {$tableName}.",
            type: 'order',
            icon: 'clipboard-list',
            color: 'info',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id, 'user_id' => $order->user_id],
        );
    }

    private function dispatchStatusChangeNotifications(Order $order, OrderStatus $oldStatus, OrderStatus $newStatus): void
    {
        match ($newStatus) {
            OrderStatus::Confirmed => $this->notifyConfirmed($order),
            OrderStatus::Preparing => $this->notifyPreparing($order),
            OrderStatus::Ready => $this->notifyReady($order),
            OrderStatus::Served => $this->notifyServed($order),
            OrderStatus::Cancelled => $this->notifyCancelled($order, $oldStatus),
            default => null,
        };
    }

    private function notifyConfirmed(Order $order): void
    {
        NotificationService::notifyRole(
            roleName: 'Cuisinier',
            title: "Commande confirmée #{$order->order_number}",
            message: "La commande #{$order->order_number} a été confirmée et est prête pour la préparation.",
            type: 'order',
            icon: 'check-circle',
            color: 'success',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id],
        );
    }

    private function notifyPreparing(Order $order): void
    {
        NotificationService::notifyUser(
            userId: $order->user_id,
            title: "Préparation en cours #{$order->order_number}",
            message: "La commande #{$order->order_number} est maintenant en préparation.",
            type: 'order',
            icon: 'loader',
            color: 'warning',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id],
        );
    }

    private function notifyReady(Order $order): void
    {
        NotificationService::notifyUser(
            userId: $order->user_id,
            title: "Commande prête #{$order->order_number}",
            message: "La commande #{$order->order_number} est prête à être servie.",
            type: 'order',
            icon: 'bell',
            color: 'success',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id],
        );

        NotificationService::notifyAdmins(
            title: "Commande prête #{$order->order_number}",
            message: "La commande #{$order->order_number} est prête à être servie.",
            type: 'order',
            icon: 'bell',
            color: 'success',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id],
        );
    }

    private function notifyServed(Order $order): void
    {
        NotificationService::notifyRoles(
            roleNames: ['Caissier', 'Admin', 'Manager'],
            title: "Commande servie #{$order->order_number}",
            message: "La commande #{$order->order_number} a été servie. En attente de paiement.",
            type: 'order',
            icon: 'check',
            color: 'success',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id, 'total' => $order->total],
            module: 'orders',
        );
    }

    private function notifyCancelled(Order $order, OrderStatus $oldStatus): void
    {
        $message = "La commande #{$order->order_number} a été annulée. (Statut précédent : {$oldStatus->label()})";

        NotificationService::notifyRole(
            roleName: 'Cuisinier',
            title: "Commande annulée #{$order->order_number}",
            message: $message,
            type: 'order',
            icon: 'x-circle',
            color: 'danger',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id, 'previous_status' => $oldStatus->value],
        );

        NotificationService::notifyUser(
            userId: $order->user_id,
            title: "Commande annulée #{$order->order_number}",
            message: $message,
            type: 'order',
            icon: 'x-circle',
            color: 'danger',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id, 'previous_status' => $oldStatus->value],
        );

        NotificationService::notifyAdmins(
            title: "Commande annulée #{$order->order_number}",
            message: $message,
            type: 'order',
            icon: 'x-circle',
            color: 'danger',
            actionUrl: "/orders/{$order->id}",
            metadata: ['order_id' => $order->id],
        );
    }

    private function createInvoiceForOrder(Order $order): Invoice
    {
        $taxRate = (float) Setting::get('tax_rate', 20.0);
        $subtotal = $order->total;
        $taxAmount = $subtotal * ($taxRate / 100);

        $invoiceNumber = 'INV-'.str_pad(
            (int) \DB::table('invoices')->max('id') + 1,
            6,
            '0',
            STR_PAD_LEFT
        );

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'client_id' => $order->client_id,
            'invoice_number' => $invoiceNumber,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $subtotal + $taxAmount,
            'status' => InvoiceStatus::Unpaid,
        ]);

        // La commande est servie : les ingrédients sortent du stock.
        app(\App\Services\StockLedgerService::class)
            ->deductForOrder($order, "Vente commande {$order->order_number}");

        return $invoice;
    }

    private function releaseTable(Order $order): void
    {
        if ($order->table) {
            $order->table->update(['status' => TableStatus::Available]);
        }
    }

    private function generateOrderNumber(): string
    {
        $lastNumber = (int) \DB::table('orders')->max('id');

        return 'ORD-'.str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
    }
}
