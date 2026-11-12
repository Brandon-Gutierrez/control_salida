<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de `records` para las consultas de cada persona:
 * - salidas por persona y fecha (estadísticas del día, la semana, el mes o un rango);
 * - salidas abiertas (sin retorno) de una persona, que se consultan en cada
 *   escaneo; el índice parcial solo contiene las pocas filas abiertas;
 * - el motivo-predio de cada salida (cuenta de usos por motivo y comprobaciones
 *   de las claves foráneas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->index(['user_id', 'leave_time'], 'records_user_leave_time_idx');
            $table->index('reason_premise_id', 'records_reason_premise_idx');
        });

        // Índice parcial (PostgreSQL y SQLite lo soportan con esta sintaxis).
        DB::statement(
            'CREATE INDEX records_open_leave_idx ON records (user_id, leave_time) WHERE return_time IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS records_open_leave_idx');

        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex('records_user_leave_time_idx');
            $table->dropIndex('records_reason_premise_idx');
        });
    }
};
