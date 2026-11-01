<?php

namespace App\Events;

use App\Models\StockItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockSaved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StockItem $item,
        public string $action = 'updated'
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('restaurant')];
    }

    public function broadcastAs(): string
    {
        return 'stock.saved';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'stock_item' => [
                'id' => $this->item->id,
                'name' => $this->item->name,
                'quantity' => (float) $this->item->quantity,
                'min_quantity' => (float) $this->item->min_quantity,
                'updated_at' => $this->item->updated_at?->toISOString(),
            ],
        ];
    }
}
