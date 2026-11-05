<?php

namespace Tests\Feature;

use App\Models\LeaveReason;
use App\Models\Premise;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Contadores de "Inicio" del panel de administración. */
class AdminSummaryTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private Role $employeeRole;
    private Role $adminRole;
    private Role $managerRole;
    private int $item = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employeeRole = Role::create(['name' => Role::EMPLOYEE]);
        $this->adminRole = Role::create(['name' => Role::ADMIN]);
        $this->managerRole = Role::where('name', Role::MANAGE_PREMISE)->firstOrFail();
    }

    private function user(Role $role, array $extra = []): User
    {
        $this->item++;

        return User::create([
            'external_identifier' => "u-{$this->item}", 'name' => "Persona {$this->item}",
            'item' => $this->item, 'role_id' => $role->role_id,
        ] + $extra);
    }

    private function premise(string $name, bool $located = true): Premise
    {
        return Premise::create([
            'name' => $name,
            'latitude' => $located ? -17.39 : null,
            'longitude' => $located ? -66.15 : null,
        ]);
    }

    private function enable(Premise $premise, string $code): void
    {
        $reason = LeaveReason::firstOrCreate(['code' => $code], ['name' => "Motivo {$code}"]);
        DB::table('reason_premise')->insert(['reason_id' => $reason->reason_id, 'premise_id' => $premise->premise_id]);
    }

    public function test_cuenta_predios_y_usuarios(): void
    {
        $admin = $this->user($this->adminRole);

        // Predio completo: ubicación, responsable y motivos.
        $ok = $this->premise('Completo');
        $this->enable($ok, 'A');
        $this->user($this->managerRole, ['premise_id' => $ok->premise_id, 'username' => 'm1', 'password' => 'x']);
        // Predio sin ubicación, sin responsable y sin motivos.
        $this->premise('Vacío', located: false);
        // Predio con ubicación pero sin responsable ni motivos.
        $this->premise('Sin nada');

        // Responsable sin predio asignado.
        $this->user($this->managerRole, ['username' => 'm2', 'password' => 'x']);
        // Empleados: uno ya usa la app móvil y otro no.
        $withApp = $this->user($this->employeeRole);
        UserDevice::create(['user_id' => $withApp->user_id, 'platform' => 'mobile', 'device_hash' => str_repeat('a', 64), 'bound_at' => now()]);
        $this->user($this->employeeRole);
        // Un dispositivo web no cuenta como "usa la app".
        $webOnly = $this->user($this->employeeRole);
        UserDevice::create(['user_id' => $webOnly->user_id, 'platform' => 'web', 'device_hash' => str_repeat('b', 64), 'bound_at' => now()]);

        $this->signIn($admin, ClientPlatform::WEB)
            ->getJson('/api/admin/summary')
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.premises.total', 3)
            ->assertJsonPath('data.premises.without_location', 1)
            ->assertJsonPath('data.premises.without_manager', 2)
            ->assertJsonPath('data.premises.without_reasons', 2)
            ->assertJsonPath('data.users.total', 6)
            ->assertJsonPath('data.users.admins', 1)
            ->assertJsonPath('data.users.managers_without_premise', 1)
            ->assertJsonPath('data.users.employees_without_app', 2);
    }

    public function test_sin_datos_devuelve_ceros(): void
    {
        $this->signIn($this->user($this->adminRole), ClientPlatform::WEB)
            ->getJson('/api/admin/summary')
            ->assertOk()
            ->assertJsonPath('data.premises.total', 0)
            ->assertJsonPath('data.users.total', 1)
            ->assertJsonPath('data.users.employees_without_app', 0);
    }

    public function test_solo_el_admin_en_la_web_puede_verlo(): void
    {
        $this->signIn($this->user($this->employeeRole), ClientPlatform::MOBILE)
            ->getJson('/api/admin/summary')->assertStatus(403);
    }
}
