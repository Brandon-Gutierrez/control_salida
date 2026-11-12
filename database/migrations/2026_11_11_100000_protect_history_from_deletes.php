<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El historial de salidas no puede perderse por un borrado en cascada.
 *
 * - Las claves foráneas que hoy borraban `records` en cascada pasan a RESTRICT
 *   (salida → persona, salida → motivo-predio, motivo-predio → motivo y predio,
 *   persona → rol): no se puede borrar nada que tenga historial asociado.
 * - Cada motivo se habilita una sola vez por predio (la fila se reutiliza al
 *   retirarlo y volver a habilitarlo, con borrado lógico).
 * - En PostgreSQL, además, un trigger impide borrar o vaciar `records` y borrar
 *   `reason_premise` aunque alguien lo intente directamente con SQL; así el
 *   `record_id` consecutivo no vuelve a tener huecos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicated = DB::table('reason_premise')
            ->select('premise_id', 'reason_id')
            ->groupBy('premise_id', 'reason_id')
            ->havingRaw('count(*) > 1')
            ->exists();
        if ($duplicated) {
            throw new RuntimeException(
                'Hay motivos repetidos en un mismo predio (tabla reason_premise). '
                .'Corríjalos antes de migrar: las salidas dependen de esas filas.',
            );
        }

        $this->replaceForeignKeys(restrict: true);

        Schema::table('reason_premise', function (Blueprint $table) {
            $table->unique(['premise_id', 'reason_id'], 'reason_premise_premise_reason_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_history_delete() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'La tabla % es historial permanente: no se puede borrar.', TG_TABLE_NAME
                        USING ERRCODE = 'restrict_violation';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER records_no_delete BEFORE DELETE ON records
                    FOR EACH ROW EXECUTE FUNCTION prevent_history_delete();
                CREATE TRIGGER records_no_truncate BEFORE TRUNCATE ON records
                    FOR EACH STATEMENT EXECUTE FUNCTION prevent_history_delete();
                CREATE TRIGGER reason_premise_no_delete BEFORE DELETE ON reason_premise
                    FOR EACH ROW EXECUTE FUNCTION prevent_history_delete();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS records_no_delete ON records;
                DROP TRIGGER IF EXISTS records_no_truncate ON records;
                DROP TRIGGER IF EXISTS reason_premise_no_delete ON reason_premise;
                DROP FUNCTION IF EXISTS prevent_history_delete();
            SQL);
        }

        Schema::table('reason_premise', function (Blueprint $table) {
            $table->dropUnique('reason_premise_premise_reason_unique');
        });

        $this->replaceForeignKeys(restrict: false);
    }

    /** Vuelve a crear las claves foráneas con RESTRICT (o con CASCADE al revertir). */
    private function replaceForeignKeys(bool $restrict): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['reason_premise_id']);
        });
        Schema::table('reason_premise', function (Blueprint $table) {
            $table->dropForeign(['reason_id']);
            $table->dropForeign(['premise_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
        });

        $action = fn ($foreign) => $restrict ? $foreign->restrictOnDelete() : $foreign->cascadeOnDelete();

        Schema::table('records', function (Blueprint $table) use ($action) {
            $action($table->foreign('user_id')->references('user_id')->on('users'));
            $action($table->foreign('reason_premise_id')->references('id')->on('reason_premise'));
        });
        Schema::table('reason_premise', function (Blueprint $table) use ($action) {
            $action($table->foreign('reason_id')->references('reason_id')->on('reasons'));
            $action($table->foreign('premise_id')->references('premise_id')->on('premises'));
        });
        Schema::table('users', function (Blueprint $table) use ($action) {
            $action($table->foreign('role_id')->references('role_id')->on('roles'));
        });
    }
};
