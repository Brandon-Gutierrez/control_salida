<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

// Siembra el administrador inicial (SUPERADMIN_ITEM / SUPERADMIN_NAME).
class SuperAdminSeeder extends Seeder
{
    /**
     * La cuenta se identifica por el item corporativo. No tiene contraseña
     * propia: inicia sesión con sus credenciales del sistema externo, y en ese
     * primer ingreso se completa su `external_identifier` sin duplicarla. Es
     * idempotente: si ya existe solo se asegura de que sea ADMIN.
     */
    public function run(): void
    {
        $item = config('services.super_admin.item');
        $name = config('services.super_admin.name');

        if (blank($item)) {
            $this->command?->warn('SUPERADMIN_ITEM no está definido: no se siembra ningún administrador.');

            return;
        }

        $adminRole = Role::firstOrCreate(['name' => Role::ADMIN]);

        $user = User::firstWhere('item', (int) $item);

        if (! $user) {
            User::create([
                'external_identifier' => 'seed-admin-'.(int) $item,
                'name' => filled($name) ? $name : 'Super administrador',
                'item' => (int) $item,
                'role_id' => $adminRole->role_id,
            ]);

            return;
        }

        // Una cuenta local de responsable de predio no existe en el sistema externo.
        if ($user->username !== null) {
            $this->command?->warn("El item {$item} pertenece a una cuenta local de responsable: no se promueve.");

            return;
        }

        if ($user->role_id !== $adminRole->role_id) {
            $user->update(['role_id' => $adminRole->role_id, 'premise_id' => null]);
        }
    }
}
