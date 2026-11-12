<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `settings.value` era un varchar(255). Hay valores que lo superan: el estado de
 * la última sincronización de motivos (con su mensaje de error) y los topes por
 * motivo del límite personalizado (un par por motivo). PostgreSQL rechaza esos
 * valores; con `text` no hay límite práctico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('value')->nullable()->change();
        });
    }
};
