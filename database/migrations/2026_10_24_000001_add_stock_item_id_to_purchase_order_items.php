<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            // Lien vers l'article de stock : sans lui, la réception d'un bon
            // ne pouvait pas approvisionner l'inventaire (ligne texte libre).
            $table->foreignId('stock_item_id')
                ->nullable()
                ->after('name')
                ->constrained('stock_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_id');
        });
    }
};
