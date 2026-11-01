<?php

namespace App\Events;

use App\Models\Table;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableSaved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Table $table,
        public string $action = 'updated'
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('restaurant')];
    }

    public function broadcastAs(): string
    {
        return 'table.saved';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'table' => [
                'id' => $this->table->id,
                'name' => $this->table->name,
                'status' => $this->table->status instanceof \BackedEnum ? $this->table->status->value : $this->table->status,
                'zone_id' => $this->table->zone_id,
                'updated_at' => $this->table->updated_at?->toISOString(),
            ],
        ];
    }
}
