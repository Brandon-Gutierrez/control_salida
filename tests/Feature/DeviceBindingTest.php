<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserActiveSession;
use App\Models\UserDevice;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/**
 * Reglas de acceso por aplicación y dispositivo:
 * - web: ADMIN y MANAGE_PREMISE; mobile: ADMIN y EMPLOYEE.
 * - Un dispositivo por cuenta y aplicación; cambiarlo requiere que
 *   administración (o TI) desvincule el anterior.
 */
class DeviceBindingTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private const LAPTOP = 'laptop-navegador-0000000000000001';
    private const OTHER_LAPTOP = 'laptop-navegador-0000000000000002';
    private const PHONE = 'telefono-android-00000000000000001';
    private const OTHER_PHONE = 'telefono-android-00000000000000002';

    private Role $employeeRole;
    private Role $adminRole;
    private Role $managerRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employeeRole = Role::create(['name' => Role::EMPLOYEE]);
        $this->adminRole = Role::create(['name' => Role::ADMIN]);
        $this->managerRole = Role::where('name', Role::MANAGE_PREMISE)->firstOrFail();

        // El servicio externo acepta cualquier usuario cuya clave sea "ok".
        Http::fake(function (HttpRequest $request) {
            if ($request['password'] !== 'ok') {
                return Http::response(['status' => 1], 200);
            }

            return Http::response([
                'status' => 0,
                'token' => 'ext-' . $request['username'],
                'name' => 'Nombre ' . $request['username'],
                'item' => crc32($request['username']) % 1_000_000,
            ]);
        });
    }

    private function externalUser(string $username, Role $role): User
    {
        return User::create([
            'external_identifier' => "ext-$username",
            'name' => "Nombre $username",
            'item' => crc32($username) % 1_000_000,
            'role_id' => $role->role_id,
        ]);
    }

    private function manager(): User
    {
        $premise = \App\Models\Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);

        return User::create([
            'external_identifier' => 'premise-manager:portero',
            'name' => 'Portero',
            'item' => 9_999_999,
            'role_id' => $this->managerRole->role_id,
            'username' => 'portero',
            'password' => Hash::make('clave-segura-123'),
            'premise_id' => $premise->premise_id,
        ]);
    }

    /**
     * Cada petición real es un proceso nuevo: sin usuario, encabezados ni datos
     * de sesión en memoria (la sesión solo se recupera con su cookie).
     */
    private function newRequest(): static
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->flushHeaders();
        $this->app['session']->driver()->flush();

        return $this;
    }

    private function doLogin(string $username, string $password, string $platform, string $deviceId): TestResponse
    {
        return $this->newRequest()->login($username, $password, $platform, $deviceId);
    }

    /** Petición autenticada con la cookie de sesión que devolvió el login. */
    private function me(TestResponse $login, ?string $deviceId): TestResponse
    {
        $cookie = $login->getCookie(config('session.cookie'), false)->getValue();
        $this->newRequest()->withCredentials()->withUnencryptedCookie(config('session.cookie'), $cookie);
        if ($deviceId !== null) {
            $this->withHeader('DeviceId', $deviceId);
        }

        return $this->getJson('/api/auth/me');
    }

    // ------------------------------------------------------------ web

    public function test_admin_web_queda_vinculado_a_su_navegador_y_mantiene_la_sesion(): void
    {
        $this->externalUser('ana', $this->adminRole);

        $login = $this->doLogin('ana', 'ok', ClientPlatform::WEB, self::LAPTOP)->assertOk();

        $this->assertDatabaseCount('user_devices', 1);
        $this->assertDatabaseHas('user_devices', ['platform' => 'web']);
        $this->me($login, self::LAPTOP)->assertOk()->assertJsonPath('user.name', 'Nombre ana');
    }

    public function test_admin_web_desde_otro_navegador_debe_contactar_a_ti(): void
    {
        $this->externalUser('ana', $this->adminRole);
        $this->doLogin('ana', 'ok', ClientPlatform::WEB, self::LAPTOP)->assertOk();

        $this->doLogin('ana', 'ok', ClientPlatform::WEB, self::OTHER_LAPTOP)
            ->assertStatus(403)
            ->assertJsonPath('code', 'DEVICE_NOT_AUTHORIZED')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'TI'));
        $this->assertDatabaseCount('user_active_sessions', 1);
    }

    public function test_gestor_web_desde_otro_navegador_debe_contactar_a_ti(): void
    {
        $this->manager();
        $this->doLogin('portero', 'clave-segura-123', ClientPlatform::WEB, self::LAPTOP)->assertOk();

        $this->doLogin('portero', 'clave-segura-123', ClientPlatform::WEB, self::OTHER_LAPTOP)
            ->assertStatus(403)
            ->assertJsonPath('code', 'DEVICE_NOT_AUTHORIZED')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'TI'));
    }

    public function test_empleado_no_puede_entrar_a_la_web(): void
    {
        $this->externalUser('juan', $this->employeeRole);

        $this->doLogin('juan', 'ok', ClientPlatform::WEB, self::LAPTOP)
            ->assertStatus(403)
            ->assertJsonPath('code', 'PLATFORM_NOT_ALLOWED')
            ->assertJsonPath('message', 'Los empleados solo pueden ingresar desde la aplicación móvil.');
        $this->assertDatabaseCount('user_devices', 0);
        $this->assertDatabaseCount('user_active_sessions', 0);
    }

    // ------------------------------------------------------------ mobile

    public function test_empleado_movil_queda_vinculado_a_su_telefono(): void
    {
        $this->externalUser('juan', $this->employeeRole);

        $login = $this->doLogin('juan', 'ok', ClientPlatform::MOBILE, self::PHONE)->assertOk();

        $this->me($login, self::PHONE)->assertOk();
        $this->assertDatabaseHas('user_devices', ['platform' => 'mobile']);
    }

    public function test_empleado_desde_otro_telefono_debe_contactar_a_recursos_humanos(): void
    {
        $this->externalUser('juan', $this->employeeRole);
        $this->doLogin('juan', 'ok', ClientPlatform::MOBILE, self::PHONE)->assertOk();

        $this->doLogin('juan', 'ok', ClientPlatform::MOBILE, self::OTHER_PHONE)
            ->assertStatus(403)
            ->assertJsonPath('code', 'DEVICE_NOT_AUTHORIZED')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Recursos Humanos'));
    }

    public function test_gestor_no_puede_entrar_a_la_app_movil(): void
    {
        $this->manager();

        $this->doLogin('portero', 'clave-segura-123', ClientPlatform::MOBILE, self::PHONE)
            ->assertStatus(403)
            ->assertJsonPath('code', 'PLATFORM_NOT_ALLOWED');
        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_admin_usa_web_y_movil_con_un_dispositivo_en_cada_una(): void
    {
        $this->externalUser('ana', $this->adminRole);

        $this->doLogin('ana', 'ok', ClientPlatform::WEB, self::LAPTOP)->assertOk();
        $this->doLogin('ana', 'ok', ClientPlatform::MOBILE, self::PHONE)->assertOk();

        $this->assertDatabaseCount('user_devices', 2);
        $this->assertSame(1, UserActiveSession::where('platform', 'web')->count());
        $this->assertSame(1, UserActiveSession::where('platform', 'mobile')->count());

        $this->doLogin('ana', 'ok', ClientPlatform::MOBILE, self::OTHER_PHONE)->assertStatus(403);
    }

    // ------------------------------------------------ sesión y peticiones

    public function test_mismo_dispositivo_reemplaza_la_sesion_anterior(): void
    {
        $this->externalUser('ana', $this->adminRole);
        $first = $this->doLogin('ana', 'ok', ClientPlatform::WEB, self::LAPTOP)->assertOk();
        $this->doLogin('ana', 'ok', ClientPlatform::WEB, self::LAPTOP)->assertOk();

        $this->assertSame(1, UserActiveSession::where('platform', 'web')->count());
        $this->me($first, self::LAPTOP)->assertStatus(401);
    }

    public function test_cada_peticion_debe_venir_del_dispositivo_vinculado(): void
    {
        $this->externalUser('juan', $this->employeeRole);
        $login = $this->doLogin('juan', 'ok', ClientPlatform::MOBILE, self::PHONE)->assertOk();

        $this->me($login, self::OTHER_PHONE)->assertStatus(401)->assertJsonPath('code', 'DEVICE_NOT_AUTHORIZED');
        $this->me($login, null)->assertStatus(401)->assertJsonPath('code', 'DEVICE_NOT_AUTHORIZED');
        $this->me($login, self::PHONE)->assertOk();
    }

    public function test_login_exige_aplicacion_y_dispositivo(): void
    {
        $this->externalUser('ana', $this->adminRole);

        $this->newRequest()->withHeader('DeviceId', self::LAPTOP)
            ->postJson('/api/auth/login', ['username' => 'ana', 'password' => 'ok'])
            ->assertStatus(422)->assertJsonPath('code', 'CLIENT_PLATFORM_REQUIRED');

        $this->newRequest()->withHeader(ClientPlatform::HEADER, 'web')
            ->postJson('/api/auth/login', ['username' => 'ana', 'password' => 'ok'])
            ->assertStatus(422)->assertJsonPath('code', 'DEVICE_ID_REQUIRED');
    }

    public function test_credenciales_incorrectas_responden_401_sin_vincular(): void
    {
        $this->externalUser('juan', $this->employeeRole);

        $this->doLogin('juan', 'mala', ClientPlatform::MOBILE, self::PHONE)->assertStatus(401);
        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_sesion_antigua_sin_aplicacion_debe_iniciar_sesion_de_nuevo(): void
    {
        $user = $this->externalUser('juan', $this->employeeRole);

        $this->withoutMiddleware(\App\Http\Middleware\CheckActiveSession::class)
            ->actingAs($user, 'web')
            ->withHeader('DeviceId', self::PHONE)
            ->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_PLATFORM_MISSING');
    }

    public function test_cada_aplicacion_solo_usa_sus_rutas(): void
    {
        $admin = $this->externalUser('ana', $this->adminRole);

        $this->signIn($admin, ClientPlatform::WEB)->getJson('/api/me/leave-status')->assertStatus(403);
        $this->newRequest()->signIn($admin, ClientPlatform::MOBILE)->getJson('/api/admin/users')->assertStatus(403);
        $this->newRequest()->signIn($admin, ClientPlatform::WEB)->getJson('/api/admin/users')->assertOk();
    }

    // ------------------------------------------ desvincular (RRHH / TI)

    public function test_admin_desvincula_el_telefono_y_el_empleado_entra_con_uno_nuevo(): void
    {
        $employee = $this->externalUser('juan', $this->employeeRole);
        $admin = $this->externalUser('ana', $this->adminRole);
        $this->doLogin('juan', 'ok', ClientPlatform::MOBILE, self::PHONE)->assertOk();

        $this->newRequest()->signIn($admin, ClientPlatform::WEB)
            ->postJson("/api/admin/users/{$employee->user_id}/device/reset", ['platform' => 'mobile'])
            ->assertOk();

        $this->assertDatabaseMissing('user_devices', ['user_id' => $employee->user_id]);
        $this->assertSame(0, UserActiveSession::where('user_id', $employee->user_id)->count());
        $this->doLogin('juan', 'ok', ClientPlatform::MOBILE, self::OTHER_PHONE)->assertOk();
    }

    public function test_admin_desvincula_el_navegador_de_un_gestor(): void
    {
        $manager = $this->manager();
        $admin = $this->externalUser('ana', $this->adminRole);
        $this->doLogin('portero', 'clave-segura-123', ClientPlatform::WEB, self::LAPTOP)->assertOk();

        $this->newRequest()->signIn($admin, ClientPlatform::WEB)
            ->postJson("/api/admin/users/{$manager->user_id}/device/reset", ['platform' => 'web'])
            ->assertOk();

        $this->doLogin('portero', 'clave-segura-123', ClientPlatform::WEB, self::OTHER_LAPTOP)->assertOk();
    }

    public function test_no_se_desvincula_una_aplicacion_que_el_rol_no_usa(): void
    {
        $employee = $this->externalUser('juan', $this->employeeRole);
        $admin = $this->externalUser('ana', $this->adminRole);

        $this->signIn($admin, ClientPlatform::WEB)
            ->postJson("/api/admin/users/{$employee->user_id}/device/reset", ['platform' => 'web'])
            ->assertStatus(422);
    }

    public function test_admin_no_desvincula_el_navegador_que_esta_usando_pero_si_su_telefono(): void
    {
        $admin = $this->externalUser('ana', $this->adminRole);

        $this->signIn($admin, ClientPlatform::WEB)
            ->postJson("/api/admin/users/{$admin->user_id}/device/reset", ['platform' => 'web'])
            ->assertStatus(422);

        $this->signIn($admin, ClientPlatform::WEB)
            ->postJson("/api/admin/users/{$admin->user_id}/device/reset", ['platform' => 'mobile'])
            ->assertOk();
    }

    public function test_ti_desvincula_desde_la_consola(): void
    {
        $admin = $this->externalUser('ana', $this->adminRole);
        UserDevice::create(['user_id' => $admin->user_id, 'platform' => 'web', 'device_hash' => hash('sha256', self::LAPTOP), 'bound_at' => now()]);
        UserDevice::create(['user_id' => $admin->user_id, 'platform' => 'mobile', 'device_hash' => hash('sha256', self::PHONE), 'bound_at' => now()]);

        $this->artisan('devices:reset', ['user' => (string) $admin->item, '--platform' => 'web'])->assertSuccessful();

        $this->assertDatabaseMissing('user_devices', ['user_id' => $admin->user_id, 'platform' => 'web']);
        $this->assertDatabaseHas('user_devices', ['user_id' => $admin->user_id, 'platform' => 'mobile']);
    }

    public function test_el_listado_muestra_los_dispositivos_sin_exponer_el_identificador(): void
    {
        $employee = $this->externalUser('juan', $this->employeeRole);
        $admin = $this->externalUser('ana', $this->adminRole);
        UserDevice::create(['user_id' => $employee->user_id, 'platform' => 'mobile', 'device_hash' => hash('sha256', self::PHONE), 'bound_at' => now()]);

        $users = collect($this->signIn($admin)->getJson('/api/admin/users')->assertOk()->json('data'));
        $row = $users->firstWhere('user_id', $employee->user_id);

        $this->assertSame('mobile', $row['devices'][0]['platform']);
        $this->assertArrayNotHasKey('device_hash', $row['devices'][0]);
    }
}
