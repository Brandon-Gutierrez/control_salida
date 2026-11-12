<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cubre la siembra inicial: roles y administrador identificado por su item. */
class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_roles_and_a_single_admin_idempotently(): void
    {
        config(['services.super_admin' => ['item' => '4321', 'name' => 'Admin Inicial']]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['EMPLOYEE', 'ADMIN', 'MANAGE_PREMISE'],
            Role::orderBy('role_id')->pluck('name')->all(),
        );

        $admin = User::where('item', 4321)->sole();
        $this->assertSame('Admin Inicial', $admin->name);
        $this->assertSame(Role::ADMIN, $admin->role->name);
    }

    public function test_promotes_an_existing_account_when_there_is_no_admin(): void
    {
        config(['services.super_admin' => ['item' => null, 'name' => null]]);
        $this->seed(DatabaseSeeder::class);

        $user = $this->employee(888);

        config(['services.super_admin' => ['item' => '888', 'name' => 'Otro']]);
        $this->seed(DatabaseSeeder::class);

        $user->refresh();
        $this->assertSame(Role::ADMIN, $user->role->name);
        $this->assertSame('Persona', $user->name);
        $this->assertSame(1, User::where('item', 888)->count());
    }

    public function test_does_not_undo_a_demotion_while_another_admin_exists(): void
    {
        config(['services.super_admin' => ['item' => '888', 'name' => 'Inicial']]);
        $this->seed(DatabaseSeeder::class);

        $admin = Role::where('name', Role::ADMIN)->firstOrFail();
        $employee = Role::where('name', Role::EMPLOYEE)->firstOrFail();
        $this->employee(999)->update(['role_id' => $admin->role_id]);
        User::where('item', 888)->update(['role_id' => $employee->role_id]);

        // Un reinicio del contenedor vuelve a sembrar.
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(Role::EMPLOYEE, User::where('item', 888)->sole()->role->name);
    }

    public function test_skips_the_admin_when_no_item_is_configured(): void
    {
        config(['services.super_admin' => ['item' => null, 'name' => null]]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertTrue(Role::where('name', Role::ADMIN)->exists());
    }

    private function employee(int $item): User
    {
        return User::create([
            'external_identifier' => 'token-'.$item,
            'name' => 'Persona',
            'item' => $item,
            'role_id' => Role::where('name', Role::EMPLOYEE)->firstOrFail()->role_id,
        ]);
    }
}
