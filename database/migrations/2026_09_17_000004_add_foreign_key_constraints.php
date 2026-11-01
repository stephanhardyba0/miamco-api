<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('table_id')->references('id')->on('tables')->nullOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->cascadeOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->foreign('cash_register_id')->references('id')->on('cash_registers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreign('cash_register_session_id')->references('id')->on('cash_register_sessions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('tables', function (Blueprint $table) {
            $table->foreign('zone_id')->references('id')->on('zones')->nullOnDelete();
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->foreign('menu_category_id')->references('id')->on('menu_categories')->cascadeOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('table_id')->references('id')->on('tables')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['table_id']);
            $table->dropForeign(['client_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['menu_item_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['client_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dropForeign(['cash_register_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropForeign(['cash_register_session_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('tables', function (Blueprint $table) {
            $table->dropForeign(['zone_id']);
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['menu_category_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['stock_item_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['table_id']);
            $table->dropForeign(['client_id']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
