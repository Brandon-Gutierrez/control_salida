<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

// Carga los datos iniciales.
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Una migración deja MANAGE_PREMISE creado antes que los demás; si
        // nadie lo usa se recrea para que los ids queden en el orden correcto.
        $manage = Role::where('name', 'MANAGE_PREMISE')->first();
        if ($manage && ! Role::where('name', 'EMPLOYEE')->exists() && ! $manage->users()->exists()) {
            $manage->delete();
        }

        // Orden fijo: EMPLOYEE, ADMIN, MANAGE_PREMISE (todos en mayúsculas).
        foreach (['EMPLOYEE', 'ADMIN', 'MANAGE_PREMISE'] as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
    }
}
