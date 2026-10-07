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
        // Un dispositivo por cuenta y por aplicación (web / mobile): un admin
        // puede tener su navegador y su teléfono, pero solo uno de cada uno.
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('platform', 10);
            $table->char('device_hash', 64);
            $table->timestamp('bound_at');
            $table->timestamps();
            $table->unique(['user_id', 'platform']);
        });

        // Las vinculaciones anteriores eran de la app móvil. Un CHAR(64) vacío
        // queda relleno de espacios: esos valores no son un dispositivo real.
        DB::table('users')->whereNotNull('device_id')->orderBy('user_id')
            // Define las columnas de la tabla.
            ->each(function ($user) {
                $hash = trim((string) $user->device_id);
                if (strlen($hash) !== 64) {
                    return;
                }
                DB::table('user_devices')->insert([
                    'user_id' => $user->user_id,
                    'platform' => 'mobile',
                    'device_hash' => $hash,
                    'bound_at' => $user->device_bound_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        // Define las columnas de la tabla.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'device_bound_at']);
        });

        // La sesión activa se limita por aplicación (una web y una móvil).
        Schema::table('user_active_sessions', function (Blueprint $table) {
            $table->string('platform', 10)->nullable();
        });
    }

    public function down(): void
    {
        // Define las columnas de la tabla.
        Schema::table('user_active_sessions', function (Blueprint $table) {
            $table->dropColumn('platform');
        });

        // Define las columnas de la tabla.
        Schema::table('users', function (Blueprint $table) {
            $table->char('device_id', 64)->nullable();
            $table->timestamp('device_bound_at')->nullable();
        });

        DB::table('user_devices')->where('platform', 'mobile')->orderBy('id')
            // Procesa el elemento indicado.
            ->each(fn ($device) => DB::table('users')
                ->where('user_id', $device->user_id)
                ->update(['device_id' => $device->device_hash, 'device_bound_at' => $device->bound_at]));

        Schema::dropIfExists('user_devices');
    }
};
