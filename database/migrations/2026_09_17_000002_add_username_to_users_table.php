<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username')->unique()->nullable()->after('name');
            });
        }

        if (DB::table('users')->whereNull('username')->exists()) {
            DB::table('users')->whereNull('username')->orderBy('id')->each(function ($user) {
                $baseSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '.', $user->name), '.'));
                $slug = $baseSlug;
                $counter = 1;
                while (DB::table('users')->where('username', $slug)->where('id', '!=', $user->id)->exists()) {
                    $slug = $baseSlug.$counter;
                    $counter++;
                }
                DB::table('users')->where('id', $user->id)->update(['username' => $slug]);
            });
        }

        if (DB::getDriverName() === 'mysql') {
            $indexes = DB::select("SHOW INDEX FROM users WHERE Column_name = 'email' AND Non_unique = 0");
            foreach ($indexes as $index) {
                Schema::table('users', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index->Key_name);
                });
            }
        } else {
            try {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropUnique('users_email_unique');
                });
            } catch (Exception $e) {
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
            $table->dropUnique('users_email_unique');
            $table->string('email')->nullable(false)->unique()->change();
        });
    }
};
