<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El rol del responsable de predio pasa a llamarse MANAGE_PREMISE. */
    public function up(): void
    {
        $renamed = DB::table('roles')
            ->where('name', 'PREMISE_MANAGER')
            ->update(['name' => 'MANAGE_PREMISE', 'updated_at' => now()]);

        if ($renamed === 0) {
            DB::table('roles')->updateOrInsert(
                ['name' => 'MANAGE_PREMISE'],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'MANAGE_PREMISE')
            ->update(['name' => 'PREMISE_MANAGER', 'updated_at' => now()]);
    }
};
