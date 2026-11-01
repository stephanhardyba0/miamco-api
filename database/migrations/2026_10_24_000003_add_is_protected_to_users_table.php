<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Compte a suppression impossible : le superadmin par defaut,
            // et tout compte que l'administrateur veut designer ensuite.
            $table->boolean('is_protected')->default(false)->after('is_active');
        });

        // Le superadmin designe : il ne part jamais, ni par l'UI ni par l'API.
        DB::table('users')
            ->where('email', 'webmaster@miamcocg.site')
            ->update(['is_protected' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_protected');
        });
    }
};
