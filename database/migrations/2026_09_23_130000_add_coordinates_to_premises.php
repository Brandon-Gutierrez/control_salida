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
        Schema::table('premises', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        // Define las columnas de la tabla.
        Schema::table('premises', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
