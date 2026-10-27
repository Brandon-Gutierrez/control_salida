<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El tope de salidas al mismo predio ya no existe: se reemplazó por el límite por motivo. */
    public function up(): void
    {
        DB::table('settings')->where('key', 'leave_limit_max_per_premise')->delete();
    }

    public function down(): void
    {
        // El valor anterior no se conserva.
    }
};
