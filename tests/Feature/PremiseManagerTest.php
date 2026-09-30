<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckActiveSession;
use App\Models\Premise;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActiveSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/**
 * Cubre el rol MANAGE_PREMISE: cuenta de un solo predio que únicamente puede
 * generar el QR de ese predio y nunca cerrar sesión ni usar otras rutas.
 */
class PremiseManagerTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private Role $employeeRole;
    private Role $adminRole;
    private Role $managerRole;
    private Premise $premise;
    private Premise $otherPremise;

    protected function setUp(): void
    {
        parent::setUp();

        // La migración de renombre ya deja MANAGE_PREMISE; se crean los demás.
        $this->employeeRole = Role::create(['name' => Role::EMPLOYEE]);
        $this->adminRole = Role::create(['name' => Role::ADMIN]);
        $this->managerRole = Role::where('name', Role::MANAGE_PREMISE)->firstOrFail();

        $this->premise = Premise::create(['name' => 'Predio Central', 'latitude' => -16.5, 'longitude' => -68.1]);
        $this->otherPremise = Premise::create(['name' => 'Predio Norte', 'latitude' => -16.4, 'longitude' => -68.2]);
    }

    private function makeUser(Role $role, array $attrs = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'external_identifier' => "ext-$n",
            'name' => "Usuario $n",
            'item' => 1000 + $n,
            'role_id' => $role->role_id,
        ], $attrs));
    }

    private function makeManager(?Premise $premise = null, string $username = 'gestor1', string $password = 'clave-segura-123'): User
    {
        return $this->makeUser($this->managerRole, [
            'username' => $username,
            'password' => Hash::make($password),
            'premise_id' => ($premise ?? $this->premise)->premise_id,
        ]);
    }

    /** Peticiones autenticadas sin depender de la cookie de sesión del navegador. */
    private function as(User $user): static
    {
        return $this->signIn($user);
    }

    // ---------------------------------------------------------------- login

    public function test_el_rol_se_llama_manage_premise(): void
    {
        $this->assertSame('MANAGE_PREMISE', Role::MANAGE_PREMISE);
        $this->assertDatabaseMissing('roles', ['name' => 'PREMISE_MANAGER']);
    }

    public function test_el_gestor_inicia_sesion_con_credenciales_locales_y_recibe_su_predio(): void
    {
        $this->makeManager();

        $response = $this->login('gestor1', 'clave-segura-123');

        $response->assertOk()
            ->assertJsonPath('user.role.name', 'MANAGE_PREMISE')
            ->assertJsonPath('user.premise.name', 'Predio Central');
        $this->assertDatabaseCount('user_active_sessions', 1);
    }

    public function test_contrasena_incorrecta_no_inicia_sesion(): void
    {
        $this->makeManager();

        $this->login('gestor1', 'otra-clave')
            ->assertStatus(401);
        $this->assertDatabaseCount('user_active_sessions', 0);
    }

    public function test_un_gestor_sin_predio_no_puede_iniciar_sesion(): void
    {
        $manager = $this->makeManager();
        $manager->update(['premise_id' => null]);

        $this->login('gestor1', 'clave-segura-123')
            ->assertStatus(403)
            ->assertJsonPath('code', 'PREMISE_NOT_ASSIGNED');
        $this->assertDatabaseCount('user_active_sessions', 0);
    }

    public function test_una_cuenta_local_que_no_es_gestor_no_puede_entrar_con_su_clave_local(): void
    {
        $this->makeUser($this->employeeRole, [
            'username' => 'empleado1',
            'password' => Hash::make('clave-segura-123'),
        ]);

        $this->login('empleado1', 'clave-segura-123')
            ->assertStatus(401);
    }

    // ------------------------------------------------------- sin cerrar sesión

    public function test_el_gestor_no_puede_cerrar_sesion_y_su_sesion_sigue_activa(): void
    {
        $manager = $this->makeManager();
        UserActiveSession::create([
            'user_id' => $manager->user_id, 'session_id' => 'sesion-abc',
            'device_name' => 'x', 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
        ]);

        $this->as($manager)->postJson('/api/auth/logout')
            ->assertStatus(403)
            ->assertJsonPath('code', 'LOGOUT_NOT_ALLOWED');

        $this->assertDatabaseHas('user_active_sessions', ['session_id' => 'sesion-abc']);
    }

    public function test_el_admin_si_puede_cerrar_sesion(): void
    {
        $admin = $this->makeUser($this->adminRole);

        $this->as($admin)->postJson('/api/auth/logout')->assertOk();
    }

    // ------------------------------------------------------------- generación QR

    private function fakeRedis(): void
    {
        Redis::shouldReceive('setex')->andReturnTrue();
        Redis::shouldReceive('get')->andReturn((string) $this->premise->premise_id);
        Redis::shouldReceive('ttl')->andReturn(330);
    }

    public function test_el_gestor_genera_el_qr_solo_de_su_predio(): void
    {
        $this->fakeRedis();
        $manager = $this->makeManager($this->premise);

        $response = $this->as($manager)->postJson('/api/manager/qr-token');

        $response->assertOk()->assertJsonPath('premise.name', 'Predio Central');
        $this->assertStringStartsWith('Predio Central+', $response->json('token'));
    }

    public function test_el_gestor_no_puede_pedir_el_qr_de_otro_predio(): void
    {
        $this->fakeRedis();
        $manager = $this->makeManager($this->premise);

        // Aunque envíe otro premise_id, el servidor usa el de su cuenta.
        $response = $this->as($manager)->postJson('/api/manager/qr-token', [
            'premise_id' => $this->otherPremise->premise_id,
            'name' => 'Predio Norte',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('Predio Central+', $response->json('token'));
        $this->assertStringNotContainsString('Norte', $response->json('token'));
    }

    public function test_el_gestor_no_puede_usar_la_ruta_de_qr_de_administracion(): void
    {
        $manager = $this->makeManager($this->premise);

        $this->as($manager)
            ->postJson('/api/admin/premises/' . $this->otherPremise->premise_id . '/qr-tokens')
            ->assertStatus(403);
    }

    public function test_solo_el_gestor_puede_usar_la_ruta_de_gestor(): void
    {
        foreach ([$this->employeeRole, $this->adminRole] as $role) {
            $this->as($this->makeUser($role))
                ->postJson('/api/manager/qr-token')
                ->assertStatus(403);
        }
    }

    // ------------------------------------------- aislamiento del resto de la API

    public function test_el_gestor_no_puede_acceder_a_ninguna_otra_ruta_de_la_api(): void
    {
        $manager = $this->makeManager();
        $this->as($manager);

        $forbidden = [
            ['GET', '/api/admin/premises'],
            ['POST', '/api/admin/premises'],
            ['GET', '/api/admin/users'],
            ['GET', '/api/admin/roles'],
            ['GET', '/api/admin/reasons'],
            ['POST', '/api/admin/reasons/sync'],
            ['PUT', '/api/admin/users/' . $manager->user_id . '/role'],
            ['PUT', '/api/admin/users/' . $manager->user_id . '/premise'],
            ['POST', '/api/admin/users/premise-managers'],
            ['GET', '/api/me/leave-status'],
            ['POST', '/api/qr/scan'],
            ['POST', '/api/leaves'],
            ['GET', '/api/premises/Predio%20Central/reasons'],
        ];

        foreach ($forbidden as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(403, "$method $uri debería estar prohibida para MANAGE_PREMISE");
        }

        // Lo único permitido además del QR: consultar quién es (restaurar sesión).
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.premise.name', 'Predio Central');
    }

    public function test_un_gestor_sin_predio_recibe_403_al_pedir_el_qr(): void
    {
        $manager = $this->makeManager();
        $manager->update(['premise_id' => null]);

        $this->as($manager)->postJson('/api/manager/qr-token')->assertStatus(403);
    }

    public function test_sin_sesion_no_hay_acceso(): void
    {
        $this->postJson('/api/manager/qr-token')->assertStatus(401);
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    // ------------------------------------------- administración de gestores

    public function test_el_admin_crea_un_gestor_con_clave_generada_una_sola_vez(): void
    {
        $admin = $this->makeUser($this->adminRole);

        $response = $this->as($admin)->postJson('/api/admin/users/premise-managers', [
            'name' => 'Ana Responsable',
            'username' => 'ana_gestora',
            'premise_id' => $this->premise->premise_id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role.name', 'MANAGE_PREMISE')
            ->assertJsonPath('data.premise.name', 'Predio Central');
        $generated = $response->json('generated_password');
        $this->assertGreaterThanOrEqual(10, strlen($generated));
        // La clave nunca se guarda ni se devuelve en claro después.
        $this->assertArrayNotHasKey('password', $response->json('data'));

        // En producción cada petición es un proceso nuevo; aquí se restablece el guard.
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->login('ana_gestora', $generated)
            ->assertOk();
    }

    public function test_el_admin_no_puede_crear_gestor_con_usuario_repetido_ni_predio_inexistente(): void
    {
        $admin = $this->makeUser($this->adminRole);
        $this->makeManager(username: 'repetido');

        $this->as($admin)->postJson('/api/admin/users/premise-managers', [
            'name' => 'X', 'username' => 'repetido', 'premise_id' => $this->premise->premise_id,
        ])->assertStatus(422)->assertJsonValidationErrors('username');

        $this->postJson('/api/admin/users/premise-managers', [
            'name' => 'X', 'username' => 'otro', 'premise_id' => 9999,
        ])->assertStatus(422)->assertJsonValidationErrors('premise_id');
    }

    public function test_el_empleado_no_puede_crear_gestores(): void
    {
        $this->as($this->makeUser($this->employeeRole))
            ->postJson('/api/admin/users/premise-managers', [
                'name' => 'X', 'username' => 'u', 'premise_id' => $this->premise->premise_id,
            ])->assertStatus(403);
    }

    public function test_asignar_el_rol_gestor_exige_un_predio(): void
    {
        $admin = $this->makeUser($this->adminRole);
        $employee = $this->makeUser($this->employeeRole);

        $this->as($admin)->putJson("/api/admin/users/{$employee->user_id}/role", [
            'role_id' => $this->managerRole->role_id,
        ])->assertStatus(422);
        $this->assertSame($this->employeeRole->role_id, $employee->fresh()->role_id);

        $this->putJson("/api/admin/users/{$employee->user_id}/role", [
            'role_id' => $this->managerRole->role_id,
            'premise_id' => $this->premise->premise_id,
        ])->assertOk()
            ->assertJsonPath('data.role.name', 'MANAGE_PREMISE')
            ->assertJsonPath('data.premise.name', 'Predio Central');
    }

    public function test_al_dejar_de_ser_gestor_pierde_el_predio_y_se_cierran_sus_sesiones(): void
    {
        $admin = $this->makeUser($this->adminRole);
        $user = $this->makeUser($this->managerRole, ['premise_id' => $this->premise->premise_id]);
        UserActiveSession::create([
            'user_id' => $user->user_id, 'session_id' => 's1',
            'device_name' => 'x', 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
        ]);

        $this->as($admin)->putJson("/api/admin/users/{$user->user_id}/role", [
            'role_id' => $this->employeeRole->role_id,
        ])->assertOk();

        $this->assertNull($user->fresh()->premise_id);
        $this->assertDatabaseCount('user_active_sessions', 0);
    }

    public function test_una_cuenta_local_de_gestor_no_puede_pasar_a_otro_rol(): void
    {
        $admin = $this->makeUser($this->adminRole);
        $manager = $this->makeManager();

        $this->as($admin)->putJson("/api/admin/users/{$manager->user_id}/role", [
            'role_id' => $this->adminRole->role_id,
        ])->assertStatus(422);
        $this->assertSame($this->managerRole->role_id, $manager->fresh()->role_id);
    }

    public function test_un_admin_no_puede_cambiar_su_propio_rol(): void
    {
        $admin = $this->makeUser($this->adminRole);

        $this->as($admin)->putJson("/api/admin/users/{$admin->user_id}/role", [
            'role_id' => $this->managerRole->role_id,
            'premise_id' => $this->premise->premise_id,
        ])->assertStatus(422);
        $this->assertSame($this->adminRole->role_id, $admin->fresh()->role_id);
    }

    public function test_el_admin_reasigna_el_predio_de_un_gestor_sin_cerrar_su_sesion(): void
    {
        $admin = $this->makeUser($this->adminRole);
        $manager = $this->makeManager($this->premise);
        UserActiveSession::create([
            'user_id' => $manager->user_id, 'session_id' => 's-gestor',
            'device_name' => 'x', 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
        ]);

        $this->as($admin)->putJson("/api/admin/users/{$manager->user_id}/premise", [
            'premise_id' => $this->otherPremise->premise_id,
        ])->assertOk()->assertJsonPath('data.premise.name', 'Predio Norte');

        $this->assertDatabaseHas('user_active_sessions', ['session_id' => 's-gestor']);
    }

    public function test_solo_se_asigna_predio_a_cuentas_gestor(): void
    {
        $admin = $this->makeUser($this->adminRole);
        $employee = $this->makeUser($this->employeeRole);

        $this->as($admin)->putJson("/api/admin/users/{$employee->user_id}/premise", [
            'premise_id' => $this->premise->premise_id,
        ])->assertStatus(422);
    }

    public function test_el_listado_de_usuarios_incluye_el_predio_del_gestor(): void
    {
        $admin = $this->makeUser($this->adminRole);
        $this->makeManager($this->premise);

        $rows = collect($this->as($admin)->getJson('/api/admin/users')->assertOk()->json('data'));
        $manager = $rows->firstWhere('role.name', 'MANAGE_PREMISE');

        $this->assertSame('Predio Central', $manager['premise']['name']);
    }
}
