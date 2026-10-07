<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Define los cambios de la migración.
return new class extends Migration
{
    public function up(): void
    {
        // Define las columnas de la tabla.
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique();
            $table->string('password')->nullable();
            $table->foreignId('premise_id')->nullable()->after('role_id')
                ->constrained('premises', 'premise_id')->nullOnDelete();
        });

        DB::table('roles')->updateOrInsert(
            ['name' => 'PREMISE_MANAGER'],
            ['updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        // Define las columnas de la tabla.
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('premise_id');
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'password']);
        });

        DB::table('roles')->where('name', 'PREMISE_MANAGER')->delete();
    }
};
