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

    public function test_promotes_an_existing_account_with_the_same_item(): void
    {
        config(['services.super_admin' => ['item' => '777', 'name' => null]]);
        $this->seed(DatabaseSeeder::class);

        $employee = Role::where('name', Role::EMPLOYEE)->firstOrFail();
        $user = User::create([
            'external_identifier' => 'token-1',
            'name' => 'Persona',
            'item' => 888,
            'role_id' => $employee->role_id,
        ]);

        config(['services.super_admin' => ['item' => '888', 'name' => 'Otro']]);
        $this->seed(DatabaseSeeder::class);

        $user->refresh();
        $this->assertSame(Role::ADMIN, $user->role->name);
        $this->assertSame('Persona', $user->name);
        $this->assertSame(1, User::where('item', 888)->count());
    }

    public function test_skips_the_admin_when_no_item_is_configured(): void
    {
        config(['services.super_admin' => ['item' => null, 'name' => null]]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertTrue(Role::where('name', Role::ADMIN)->exists());
    }
}
