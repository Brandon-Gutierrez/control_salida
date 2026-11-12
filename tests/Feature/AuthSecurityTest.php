<?php

namespace Tests\Feature;

use App\Models\Premise;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActiveSession;
use App\Services\Auth\LoginThrottle;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/**
 * Inicio de sesión: límite de intentos, mensajes que no delatan qué cuentas
 * existen, Comteco caído distinto de credenciales incorrectas, IP real detrás
 * del gateway y datos de Comteco que no salen en las respuestas.
 */
class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private Role $employeeRole;

    private Role $adminRole;

    private Role $managerRole;

    /** Respuesta vigente de Comteco al iniciar sesión. */
    private \Closure $comteco;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.external_api' => ['key' => 'k', 'login_url' => 'https://ext.test/login']]);
        $this->employeeRole = Role::create(['name' => Role::EMPLOYEE]);
        $this->adminRole = Role::create(['name' => Role::ADMIN]);
        $this->managerRole = Role::where('name', Role::MANAGE_PREMISE)->firstOrFail();

        // La clave correcta de todos los usuarios de Comteco es "ok".
        $this->comteco = fn (HttpRequest $request) => $request['password'] === 'ok'
            ? Http::response(['status' => 0, 'token' => 'tok-'.$request['username'], 'name' => 'Nombre '.$request['username'], 'item' => crc32($request['username']) % 1_000_000])
            : Http::response(['status' => 1]);
        Http::fake(['ext.test/*' => fn (HttpRequest $request) => ($this->comteco)($request)]);
    }

    private function externalUser(string $username, ?Role $role = null): User
    {
        return User::create([
            'external_identifier' => 'tok-'.$username, 'name' => 'Nombre '.$username,
            'item' => crc32($username) % 1_000_000, 'role_id' => ($role ?? $this->employeeRole)->role_id,
        ]);
    }

    private function loginFrom(string $username, string $password, string $ip = '10.0.0.1', string $platform = ClientPlatform::MOBILE, array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($headers)->login($username, $password, $platform, $this->deviceIdFor($username));
    }

    private function comtecoCalls(): int
    {
        return Http::recorded(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://ext.test/login'))->count();
    }

    private function failLogins(string $username, int $times, string $ip = '10.0.0.1'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->loginFrom($username, 'mala', $ip)->assertStatus(401);
        }
    }

    // ---------------------------------------------- datos de Comteco fuera de las respuestas

    public function test_el_identificador_de_comteco_no_sale_en_el_login_ni_en_me(): void
    {
        $this->externalUser('juan');

        $login = $this->loginFrom('juan', 'ok')->assertOk();
        $login->assertJsonMissingPath('user.external_identifier');
        $this->assertStringNotContainsString('tok-juan', $login->getContent());
        // Lo que la app sí necesita.
        $login->assertJsonPath('user.name', 'Nombre juan')->assertJsonPath('user.role.name', 'EMPLOYEE');

        $user = User::where('item', crc32('juan') % 1_000_000)->firstOrFail();
        $this->assertSame('tok-juan', $user->external_identifier, 'el servidor sí lo conserva');
        $this->assertArrayNotHasKey('external_identifier', $user->toArray());

        $me = $this->signIn($user, ClientPlatform::MOBILE)->getJson('/api/auth/me')->assertOk();
        $me->assertJsonMissingPath('user.external_identifier');
        $this->assertStringNotContainsString('tok-juan', $me->getContent());
    }

    // ----------------------------------------------------------------- límite de intentos

    public function test_a_los_5_fallos_de_una_cuenta_desde_una_ip_se_bloquea(): void
    {
        $this->externalUser('juan');
        $this->failLogins('juan', 5);

        $blocked = $this->loginFrom('juan', 'mala')->assertStatus(429);

        $blocked->assertJsonPath('code', 'LOGIN_THROTTLED')
            ->assertJsonPath('status', 'ERROR')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Demasiados intentos'));
        $this->assertGreaterThan(0, $blocked->json('retry_after'));
        $this->assertLessThanOrEqual(60, $blocked->json('retry_after'));
        $this->assertSame((string) $blocked->json('retry_after'), $blocked->headers->get('Retry-After'));
        // Bloqueado: ni se consulta a Comteco.
        $this->assertSame(5, $this->comtecoCalls());
    }

    public function test_estando_bloqueado_ni_la_clave_correcta_entra(): void
    {
        $this->externalUser('juan');
        $this->failLogins('juan', 5);

        $this->loginFrom('juan', 'ok')->assertStatus(429);
    }

    public function test_el_bloqueo_dura_un_minuto(): void
    {
        $this->externalUser('juan');
        $this->failLogins('juan', 5);
        $this->loginFrom('juan', 'ok')->assertStatus(429);

        $this->travel(61)->seconds();

        $this->loginFrom('juan', 'ok')->assertOk();
    }

    public function test_un_inicio_correcto_reinicia_el_contador_de_la_cuenta(): void
    {
        $this->externalUser('juan');
        $this->failLogins('juan', 4);
        $this->loginFrom('juan', 'ok')->assertOk();

        // Otros 4 fallos no bloquean: el contador de la cuenta volvió a cero.
        $this->failLogins('juan', 4);
        $this->loginFrom('juan', 'mala')->assertStatus(401);
    }

    public function test_otra_cuenta_o_otra_ip_no_se_ven_afectadas_por_el_bloqueo(): void
    {
        $this->externalUser('juan');
        $this->externalUser('maria');
        $this->failLogins('juan', 5);
        $this->loginFrom('juan', 'mala')->assertStatus(429);

        // Otra cuenta desde la misma IP.
        $this->loginFrom('maria', 'ok')->assertOk();
        // La misma cuenta desde otra IP.
        $this->loginFrom('juan', 'ok', '10.0.0.2')->assertOk();
    }

    public function test_a_los_20_fallos_de_una_ip_se_bloquea_cualquier_cuenta(): void
    {
        for ($i = 1; $i <= LoginThrottle::MAX_PER_IP; $i++) {
            $this->loginFrom("usuario{$i}", 'mala')->assertStatus(401);
        }

        // Una cuenta nueva y con la clave correcta, pero desde la IP que ya falló 20 veces.
        $this->externalUser('nuevo');
        $this->loginFrom('nuevo', 'ok')->assertStatus(429)->assertJsonPath('code', 'LOGIN_THROTTLED');
        // Desde otra IP, sin problema.
        $this->loginFrom('nuevo', 'ok', '10.0.0.9')->assertOk();
    }

    public function test_la_ip_real_viene_de_x_forwarded_for_detras_del_gateway(): void
    {
        $this->externalUser('juan');
        // Todas llegan desde la IP del gateway (10.0.0.1) pero de clientes distintos.
        $forwarded = fn (string $ip) => ['X-Forwarded-For' => $ip];
        for ($i = 0; $i < 5; $i++) {
            $this->loginFrom('juan', 'mala', '10.0.0.1', ClientPlatform::MOBILE, $forwarded('203.0.113.7'))->assertStatus(401);
        }

        $this->loginFrom('juan', 'ok', '10.0.0.1', ClientPlatform::MOBILE, $forwarded('203.0.113.7'))->assertStatus(429);
        // Otro cliente detrás del mismo gateway no queda bloqueado.
        $this->loginFrom('juan', 'ok', '10.0.0.1', ClientPlatform::MOBILE, $forwarded('203.0.113.8'))->assertOk();
        // Y la sesión guarda la IP del cliente, no la del gateway.
        $this->assertSame('203.0.113.8', UserActiveSession::latest('id')->firstOrFail()->ip_address);
    }

    // -------------------------------------------- Comteco caído no es una contraseña incorrecta

    public function test_si_comteco_no_responde_se_dice_y_no_se_cuenta_como_intento(): void
    {
        $this->externalUser('juan');

        foreach ([
            fn () => throw new ConnectionException('sin red'),
            fn () => Http::response('caído', 500),
            fn () => Http::response('saturado', 429),
        ] as $reply) {
            $this->comteco = $reply;
            for ($i = 0; $i < 3; $i++) {
                $this->loginFrom('juan', 'ok')->assertStatus(503)->assertJsonPath('code', 'AUTH_SERVICE_UNAVAILABLE');
            }
        }

        // Nueve fallos de servicio y aun así no está bloqueado: entra en cuanto Comteco vuelve.
        $this->comteco = fn (HttpRequest $request) => Http::response(['status' => 0, 'token' => 't', 'name' => 'Juan', 'item' => 1]);
        $this->loginFrom('juan', 'ok')->assertOk();
    }

    public function test_un_error_de_plataforma_con_credenciales_correctas_no_cuenta(): void
    {
        $this->externalUser('juan'); // empleado: no puede entrar a la web

        for ($i = 0; $i < 7; $i++) {
            $this->loginFrom('juan', 'ok', '10.0.0.1', ClientPlatform::WEB)->assertStatus(403)->assertJsonPath('code', 'PLATFORM_NOT_ALLOWED');
        }
    }

    // --------------------------------------------- no se revela qué cuentas existen

    public function test_misma_respuesta_para_clave_incorrecta_y_para_cuenta_inexistente(): void
    {
        $premise = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        User::create([
            'external_identifier' => 'premise-manager:portero', 'name' => 'Portero', 'item' => 9_999_999,
            'role_id' => $this->managerRole->role_id, 'username' => 'portero',
            'password' => Hash::make('clave-segura-123'), 'premise_id' => $premise->premise_id,
        ]);

        $wrongPassword = $this->loginFrom('portero', 'otra-clave-123', '10.0.0.5', ClientPlatform::WEB);
        $unknownUser = $this->loginFrom('no-existe', 'otra-clave-123', '10.0.0.6', ClientPlatform::WEB);

        $this->assertSame(401, $wrongPassword->getStatusCode());
        $this->assertSame($wrongPassword->getStatusCode(), $unknownUser->getStatusCode());
        $this->assertSame($wrongPassword->json(), $unknownUser->json());
        $this->assertSame('INVALID_CREDENTIALS', $unknownUser->json('code'));
    }
}
