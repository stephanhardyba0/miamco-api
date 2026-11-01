<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderSaved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public string $action = 'updated'
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('restaurant')];
    }

    public function broadcastAs(): string
    {
        return 'order.saved';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'order' => [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status instanceof \BackedEnum ? $this->order->status->value : $this->order->status,
                'table_id' => $this->order->table_id,
                'table_name' => $this->order->table?->name,
                'total' => (float) $this->order->total,
                'updated_at' => $this->order->updated_at?->toISOString(),
            ],
        ];
    }
}
