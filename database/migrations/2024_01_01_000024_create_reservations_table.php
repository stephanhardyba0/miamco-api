<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('table_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('guest_name');
            $table->integer('guest_count')->default(1);
            $table->date('reservation_date');
            $table->time('reservation_time');
            $table->string('status')->default('pending'); // pending, confirmed, cancelled, no_show
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('table_id');
            $table->index('client_id');
            $table->index('status');
            $table->index(['reservation_date', 'reservation_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
