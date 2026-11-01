<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'type' => $this->type,
            'loyalty_points' => $this->loyalty_points,
            'total_spent' => $this->total_spent,
            'visits' => $this->visits,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
