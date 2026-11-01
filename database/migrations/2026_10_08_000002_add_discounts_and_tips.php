<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('discount', 10, 2)->default(0)->after('total');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('tip_amount', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('discount');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('tip_amount');
        });
    }
};
