<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Define los cambios de la migración.
return new class extends Migration
{
    public function up(): void
    {
        // Define las columnas de la tabla.
        Schema::table('users', function (Blueprint $table) {
            $table->char('device_id', 64)->nullable();
            $table->timestamp('device_bound_at')->nullable();
        });
    }

    public function down(): void
    {
        // Define las columnas de la tabla.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'device_bound_at']);
        });
    }
};
