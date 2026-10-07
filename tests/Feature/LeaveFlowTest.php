<?php

namespace Tests\Feature;

use App\Models\LeaveReason;
use App\Models\Premise;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveLimitService;
use App\Support\ClientPlatform;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/**
 * Flujo de la app móvil: escanear el QR (salida / retorno), confirmar la salida
 * con el comprobante del escaneo y consultar el estado. El servicio externo y
 * Redis se simulan.
 */
class LeaveFlowTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private const LAT = -17.3935;

    private const LNG = -66.1570;

    private User $employee;

    private Premise $premise;

    private Premise $otherPremise;

    private int $reasonPremiseId;

    /** @var array<string, callable|PromiseInterface> */
    private array $external = [];

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => Role::EMPLOYEE]);
        Role::create(['name' => Role::ADMIN]);
        $this->employee = User::create([
            'external_identifier' => 'tok-1', 'name' => 'Juan', 'item' => 2, 'role_id' => $role->role_id,
        ]);
        $this->premise = Premise::create(['name' => 'Prado', 'latitude' => self::LAT, 'longitude' => self::LNG]);
        $this->otherPremise = Premise::create(['name' => 'Norte', 'latitude' => -16.4, 'longitude' => -68.2]);

        $reason = LeaveReason::create(['name' => 'Trámite', 'code' => 'T']);
        $this->reasonPremiseId = DB::table('reason_premise')->insertGetId([
            'reason_id' => $reason->reason_id, 'premise_id' => $this->premise->premise_id,
        ]);

        $this->fakeExternal();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Simula el servicio externo. Cada clave (employee, checkout, register)
     * puede reemplazarse por una respuesta o por una función.
     */
    private function fakeExternal(array $overrides = []): void
    {
        $this->external = array_merge([
            'employee' => fn () => Http::response(['status' => 0, 'name' => 'Juan', 'item' => 2, 'token' => 'tok-1']),
            'checkout' => fn () => Http::response(['error' => -1, 'data' => []]),
            'register' => fn () => Http::response(['status' => 0]),
            'reasons' => fn () => Http::response(['data' => null]),
        ], $overrides);

        $routes = [
            'employee' => env('API_GETEMPLOYEE'),
            'checkout' => env('API_GETCHECKOUT'),
            'register' => env('API_REGISTERCHECKOUT'),
            'reasons' => env('API_GETREASONS'),
        ];

        Http::fake(function (HttpRequest $request) use ($routes) {
            foreach ($routes as $key => $url) {
                if ($url && str_starts_with($request->url(), $url)) {
                    $handler = $this->external[$key];

                    return is_callable($handler) ? $handler($request) : $handler;
                }
            }

            return Http::response(['status' => 1]);
        });
    }

    private function onLeaveCheckout(): array
    {
        return ['checkout' => fn () => Http::response([
            'error' => 0,
            'data' => [['id_solicitud' => 77, 'fecha_salida' => '2026-10-07 10:00:00']],
        ])];
    }

    private function location(array $override = []): array
    {
        return array_merge([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'accuracy_m' => 8,
            'location_timestamp' => now()->toIso8601String(),
            'is_mocked' => false,
            'vpn_detected' => false,
        ], $override);
    }

    private function scan(string $qr = 'Prado+token'): TestResponse
    {
        return $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->postJson('/api/qr/scan', ['qrData' => $qr] + $this->location());
    }

    private function confirm(array $body = []): TestResponse
    {
        return $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->postJson('/api/leaves', $body + [
                'leaveTicket' => 'ticket-1',
                'namePremise' => 'Prado',
                'nameReason' => 'Trámite',
            ] + $this->location());
    }

    private function qrIsValidFor(Premise $premise): void
    {
        Redis::shouldReceive('get')->with('Prado+token')->andReturn((string) $premise->premise_id);
    }

    private function ticketIs(?array $payload): void
    {
        Redis::shouldReceive('get')->with('leave-ticket:ticket-1')
            ->andReturn($payload === null ? null : json_encode($payload));
    }

    private function ticketFor(Premise $premise, ?User $user = null): array
    {
        return ['user_id' => ($user ?? $this->employee)->user_id, 'premise_id' => $premise->premise_id];
    }

    private function openLeave(Premise $premise): Record
    {
        $pivotId = DB::table('reason_premise')
            ->where('premise_id', $premise->premise_id)->value('id')
            ?? DB::table('reason_premise')->insertGetId([
                'reason_id' => LeaveReason::value('reason_id'), 'premise_id' => $premise->premise_id,
            ]);

        return Record::create([
            'user_id' => $this->employee->user_id,
            'reason_premise_id' => $pivotId,
            'leave_time' => now()->subHour(),
            'return_time' => null,
        ]);
    }

    private function temporaryFailure(string $code, bool $retryable): array
    {
        return [
            'status' => 1,
            'code' => $code,
            'message' => $retryable
                ? 'Un servicio necesario no está disponible temporalmente. Intente nuevamente en unos segundos.'
                : 'No se pudo confirmar el resultado de la operación. Consulte el estado antes de volver a intentarla.',
            'retryable' => $retryable,
        ];
    }

    // --------------------------------------------------------------------- scan

    public function test_escaneo_con_qr_vencido(): void
    {
        Redis::shouldReceive('get')->with('Prado+token')->andReturn(null);

        $this->scan()->assertStatus(410)->assertExactJson([
            'status' => 1,
            'code' => 'QR_EXPIRED_OR_INVALID',
            'message' => 'Este QR venció o no es válido. Solicite uno actualizado y vuelva a escanear.',
            'retryable' => false,
        ]);
    }

    public function test_escaneo_con_redis_caido(): void
    {
        Redis::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));

        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('QR_SERVICE_UNAVAILABLE', true));
    }

    public function test_escaneo_con_servicio_de_empleados_inalcanzable(): void
    {
        $this->qrIsValidFor($this->premise);
        $this->fakeExternal(['employee' => fn () => throw new ConnectionException('sin red')]);

        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('EMPLOYEE_SERVICE_UNAVAILABLE', true));
    }

    public function test_escaneo_con_error_del_servicio_de_empleados(): void
    {
        $this->qrIsValidFor($this->premise);

        $this->fakeExternal(['employee' => fn () => Http::response('caído', 503)]);
        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('EMPLOYEE_SERVICE_UNAVAILABLE', true));

        $this->fakeExternal(['employee' => fn () => Http::response('no', 404)]);
        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('EMPLOYEE_SERVICE_UNAVAILABLE', false));
    }

    public function test_escaneo_de_empleado_no_identificado(): void
    {
        $this->qrIsValidFor($this->premise);
        $this->fakeExternal(['employee' => fn () => Http::response(['status' => 1])]);

        $this->scan()->assertStatus(422)->assertExactJson([
            'status' => 1,
            'code' => 'EMPLOYEE_NOT_IDENTIFIED',
            'message' => 'No se pudo identificar al usuario en el sistema. Verifique la cuenta e intente nuevamente.',
            'retryable' => false,
        ]);
    }

    public function test_escaneo_con_servicio_de_salidas_inalcanzable_o_con_error(): void
    {
        $this->qrIsValidFor($this->premise);

        $this->fakeExternal(['checkout' => fn () => throw new ConnectionException('sin red')]);
        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('CHECKOUT_SERVICE_UNAVAILABLE', true));

        $this->fakeExternal(['checkout' => fn () => Http::response('x', 500)]);
        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('CHECKOUT_SERVICE_UNAVAILABLE', true));
    }

    public function test_escaneo_sin_salida_activa_entrega_el_comprobante(): void
    {
        $this->qrIsValidFor($this->premise);
        Redis::shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $payload) {
            return str_starts_with($key, 'leave-ticket:')
                && $ttl === 40
                && json_decode($payload, true) === [
                    'user_id' => $this->employee->user_id,
                    'premise_id' => $this->premise->premise_id,
                ];
        });
        Redis::shouldReceive('ttl')->andReturn(40);

        $response = $this->scan()->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('action', 'showReasons')
            ->assertJsonPath('leaveTicketTTL', 30)
            ->assertJsonPath('message', 'QR escaneado correctamente')
            ->assertJsonStructure(['qrData', 'leaveTicket', 'leaveTicketTTL', 'leaveTicketExpiresAt']);

        $this->assertSame($response->json('qrData'), $response->json('leaveTicket'));
    }

    public function test_escaneo_sin_salida_activa_con_comprobante_no_guardado(): void
    {
        $this->qrIsValidFor($this->premise);
        Redis::shouldReceive('setex')->once();
        Redis::shouldReceive('ttl')->andReturn(10);

        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('LEAVE_TICKET_UNAVAILABLE', true));
    }

    public function test_escaneo_rechazado_por_limite_de_salidas(): void
    {
        $this->qrIsValidFor($this->premise);
        app(LeaveLimitService::class)->savePolicy('day', 1, null);
        Record::create([
            'user_id' => $this->employee->user_id, 'reason_premise_id' => $this->reasonPremiseId,
            'leave_time' => now()->startOfDay()->addMinute(), 'return_time' => now()->startOfDay()->addMinutes(5),
        ]);

        $this->scan()->assertStatus(403)->assertJson([
            'status' => 1,
            'code' => 'LEAVE_LIMIT_REACHED',
            'limit_type' => 'total',
            'limit' => 1,
            'used' => 1,
        ]);
    }

    public function test_escaneo_de_retorno_en_el_mismo_predio_registra_el_retorno(): void
    {
        $this->qrIsValidFor($this->premise);
        $this->fakeExternal($this->onLeaveCheckout());
        $record = $this->openLeave($this->premise);

        $this->scan()->assertOk()->assertExactJson([
            'status' => 0,
            'action' => 'showHome',
            'message' => 'Bienvenido de regreso, su retorno ha sido registrado correctamente',
        ]);

        $this->assertNotNull($record->fresh()->return_time);
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), env('API_REGISTERCHECKOUT'))
            && $r['in_item'] == 2 && $r['in_id_solicitud'] == 77);
    }

    public function test_escaneo_de_retorno_en_otro_predio_se_rechaza(): void
    {
        $this->qrIsValidFor($this->otherPremise);
        $this->fakeExternal($this->onLeaveCheckout());
        $record = $this->openLeave($this->premise);

        $this->scan()->assertStatus(403)->assertExactJson([
            'status' => 1,
            'code' => 'RETURN_PREMISE_MISMATCH',
            'message' => 'El predio de retorno es diferente al predio de salida',
        ]);

        $this->assertNull($record->fresh()->return_time);
    }

    public function test_escaneo_con_respuesta_de_salidas_no_reconocida(): void
    {
        $this->qrIsValidFor($this->premise);
        $this->fakeExternal(['checkout' => fn () => Http::response(['error' => 0, 'data' => [['otro' => 1]]])]);

        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('CHECKOUT_RESPONSE_INVALID', true));
    }

    public function test_escaneo_de_retorno_con_resultado_desconocido_o_rechazado(): void
    {
        $this->qrIsValidFor($this->premise);
        $record = $this->openLeave($this->premise);

        $this->fakeExternal($this->onLeaveCheckout() + ['register' => fn () => throw new ConnectionException('x')]);
        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('RETURN_RESULT_UNKNOWN', false));

        $this->fakeExternal($this->onLeaveCheckout() + ['register' => fn () => Http::response('x', 500)]);
        $this->scan()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('RETURN_REGISTRATION_FAILED', true));

        $this->assertNull($record->fresh()->return_time);
    }

    public function test_escaneo_exige_el_texto_del_qr_y_la_ubicacion(): void
    {
        $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->postJson('/api/qr/scan', $this->location())
            ->assertStatus(422)->assertJsonValidationErrors('qrData');

        $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->postJson('/api/qr/scan', ['qrData' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('latitude');
    }

    // ------------------------------------------------------------ confirm leave

    public function test_confirmar_exige_comprobante_o_qr(): void
    {
        $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->postJson('/api/leaves', ['namePremise' => 'Prado', 'nameReason' => 'Trámite'] + $this->location())
            ->assertStatus(422)->assertJsonValidationErrors(['qrData', 'leaveTicket']);
    }

    public function test_confirmar_con_comprobante_vencido(): void
    {
        $this->ticketIs(null);

        $this->confirm()->assertStatus(410)->assertExactJson([
            'status' => 1,
            'code' => 'LEAVE_TICKET_EXPIRED',
            'message' => 'El comprobante del escaneo venció. Vuelva a escanear el QR si sigue vigente; de lo contrario, solicite uno nuevo.',
            'retryable' => false,
        ]);
    }

    public function test_confirmar_con_redis_caido(): void
    {
        Redis::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));

        $this->confirm()->assertStatus(503)->assertExactJson([
            'status' => 1,
            'code' => 'QR_SERVICE_UNAVAILABLE',
            'message' => 'No se pudo validar el escaneo por un problema temporal. Intente nuevamente.',
            'retryable' => true,
        ]);
    }

    public function test_confirmar_con_comprobante_de_otra_cuenta(): void
    {
        $other = User::create([
            'external_identifier' => 'tok-2', 'name' => 'Otra', 'item' => 3, 'role_id' => $this->employee->role_id,
        ]);
        $this->ticketIs($this->ticketFor($this->premise, $other));

        $this->confirm()->assertStatus(403)->assertExactJson([
            'status' => 1,
            'code' => 'LEAVE_TICKET_INVALID',
            'message' => 'El escaneo no pertenece a esta cuenta. Vuelva a escanear el QR.',
            'retryable' => false,
        ]);
    }

    public function test_confirmar_acepta_el_alias_qr_data(): void
    {
        $this->ticketIs($this->ticketFor($this->premise));
        Redis::shouldReceive('del')->once()->with('leave-ticket:ticket-1');

        $this->confirm(['leaveTicket' => null, 'qrData' => 'ticket-1'])->assertOk();
    }

    public function test_confirmar_con_servicio_de_empleados_inalcanzable_o_con_error(): void
    {
        $this->ticketIs($this->ticketFor($this->premise));

        $this->fakeExternal(['employee' => fn () => throw new ConnectionException('x')]);
        $this->confirm()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('EMPLOYEE_SERVICE_UNAVAILABLE', true));

        $this->fakeExternal(['employee' => fn () => Http::response('x', 429)]);
        $this->confirm()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('EMPLOYEE_SERVICE_UNAVAILABLE', true));

        $this->fakeExternal(['employee' => fn () => Http::response('x', 404)]);
        $this->confirm()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('EMPLOYEE_SERVICE_UNAVAILABLE', false));
    }

    public function test_confirmar_con_empleado_no_identificado(): void
    {
        $this->ticketIs($this->ticketFor($this->premise));
        $this->fakeExternal(['employee' => fn () => Http::response(['status' => 1])]);

        $this->confirm()->assertStatus(422)->assertJsonPath('code', 'EMPLOYEE_NOT_IDENTIFIED');
    }

    public function test_confirmar_con_un_predio_distinto_al_del_qr(): void
    {
        $this->ticketIs($this->ticketFor($this->otherPremise));

        $this->confirm()->assertStatus(403)->assertExactJson([
            'status' => 1,
            'message' => 'El predio seleccionado no coincide con el predio del código QR.',
        ]);
    }

    public function test_confirmar_con_motivo_no_disponible_en_el_predio(): void
    {
        $this->ticketIs($this->ticketFor($this->premise));

        $this->confirm(['nameReason' => 'Inexistente'])->assertStatus(422)->assertExactJson([
            'status' => 1,
            'code' => 'REASON_NOT_AVAILABLE_FOR_PREMISE',
            'message' => 'El motivo de salida no está disponible para el predio seleccionado.',
            'retryable' => false,
        ]);
    }

    public function test_confirmar_rechazado_por_limite_de_salidas(): void
    {
        $this->ticketIs($this->ticketFor($this->premise));
        app(LeaveLimitService::class)->savePolicy('day', null, 1);
        Record::create([
            'user_id' => $this->employee->user_id, 'reason_premise_id' => $this->reasonPremiseId,
            'leave_time' => now()->startOfDay()->addMinute(), 'return_time' => now()->startOfDay()->addMinutes(5),
        ]);

        $this->confirm()->assertStatus(403)->assertJsonPath('code', 'LEAVE_LIMIT_REACHED')
            ->assertJsonPath('limit_type', 'premise');
    }

    public function test_confirmar_con_registro_externo_inalcanzable_o_rechazado(): void
    {
        $this->ticketIs($this->ticketFor($this->premise));

        $this->fakeExternal(['register' => fn () => throw new ConnectionException('x')]);
        $this->confirm()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('LEAVE_REGISTRATION_RESULT_UNKNOWN', false));

        $this->fakeExternal(['register' => fn () => Http::response('x', 500)]);
        $this->confirm()->assertStatus(502)
            ->assertExactJson($this->temporaryFailure('LEAVE_REGISTRATION_FAILED', true));

        $this->assertDatabaseCount('records', 0);
    }

    public function test_confirmar_registra_la_salida_y_consume_el_comprobante(): void
    {
        $this->ticketIs($this->ticketFor($this->premise));
        Redis::shouldReceive('del')->once()->with('leave-ticket:ticket-1');

        $this->confirm()->assertOk()->assertExactJson([
            'status' => 0,
            'message' => 'Salida temporal registrada correctamente',
        ]);

        $this->assertDatabaseHas('records', [
            'user_id' => $this->employee->user_id,
            'reason_premise_id' => $this->reasonPremiseId,
            'return_time' => null,
        ]);
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), env('API_REGISTERCHECKOUT'))
            && $r['in_item'] == 2 && $r['in_motivo'] === 'T');
    }

    public function test_confirmar_sigue_adelante_si_no_puede_invalidar_el_comprobante(): void
    {
        $this->ticketIs($this->ticketFor($this->premise));
        Redis::shouldReceive('del')->andThrow(new \RuntimeException('redis down'));

        $this->confirm()->assertOk()->assertJsonPath('status', 0);
        $this->assertDatabaseCount('records', 1);
    }

    // ----------------------------------------------------------- reasons/status

    public function test_motivos_de_un_predio_por_nombre(): void
    {
        $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson('/api/premises/Prado/reasons')
            ->assertOk()->assertExactJson(['reasons' => ['Trámite']]);
    }

    public function test_estado_de_salida_sin_salida_activa(): void
    {
        $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson('/api/me/leave-status')
            ->assertOk()
            ->assertJsonPath('isLeave', false)
            ->assertJsonPath('name', 'Juan')
            ->assertJsonPath('item', 2)
            ->assertJsonPath('role', 'EMPLOYEE')
            ->assertJsonStructure(['token', 'photo_url', 'job_title', 'area', 'stats' => ['day', 'week', 'month']]);
    }

    public function test_estado_de_salida_con_salida_activa_incluye_motivo_y_fecha(): void
    {
        $this->fakeExternal($this->onLeaveCheckout());
        $this->openLeave($this->premise);

        $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson('/api/me/leave-status')
            ->assertOk()
            ->assertJsonPath('isLeave', true)
            ->assertJsonPath('reason', 'Trámite')
            ->assertJsonPath('dateLeave', Carbon::createFromFormat('Y-m-d H:i:s', '2026-10-07 10:00:00')->toIso8601String());
    }

    public function test_estado_de_salida_con_error_externo(): void
    {
        $this->fakeExternal(['checkout' => fn () => Http::response('x', 500)]);
        $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson('/api/me/leave-status')
            ->assertStatus(400)->assertExactJson(['status' => 1, 'message' => 'Error externo']);

        $this->fakeExternal(['employee' => fn () => Http::response(['status' => 1])]);
        $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson('/api/me/leave-status')
            ->assertStatus(400)->assertExactJson(['status' => 1, 'message' => 'Error externo']);
    }

    // ----------------------------------------------------------- admin sync / QR

    public function test_el_admin_sincroniza_los_motivos(): void
    {
        $admin = User::create([
            'external_identifier' => 'ad', 'name' => 'Ana', 'item' => 9,
            'role_id' => Role::where('name', Role::ADMIN)->value('role_id'),
        ]);
        $this->fakeExternal(['reasons' => fn () => Http::response(['data' => [
            ['codigo' => 'T', 'descripcion' => 'Trámite'],
            ['codigo' => 'M', 'descripcion' => 'Médico'],
        ]])]);

        $first = $this->signIn($admin, ClientPlatform::WEB)->postJson('/api/admin/reasons/sync')->assertOk();
        $this->assertSame('Motivos de salida actualizados correctamente', $first->json('message'));
        $this->assertCount(1, $first->json('newPremises'));
        $this->assertDatabaseHas('reasons', ['code' => 'M', 'name' => 'Médico']);

        $this->signIn($admin, ClientPlatform::WEB)->postJson('/api/admin/reasons/sync')
            ->assertOk()->assertExactJson(['status' => 0, 'message' => 'No se encontraron motivos de salida nuevas']);
    }

    public function test_sincronizar_motivos_con_error_externo(): void
    {
        $admin = User::create([
            'external_identifier' => 'ad', 'name' => 'Ana', 'item' => 9,
            'role_id' => Role::where('name', Role::ADMIN)->value('role_id'),
        ]);
        $this->signIn($admin, ClientPlatform::WEB)->postJson('/api/admin/reasons/sync')
            ->assertStatus(400)->assertExactJson(['status' => 1, 'message' => 'Error al obtener los motivos de salida']);
    }

    public function test_el_admin_genera_el_qr_de_un_predio(): void
    {
        $admin = User::create([
            'external_identifier' => 'ad', 'name' => 'Ana', 'item' => 9,
            'role_id' => Role::where('name', Role::ADMIN)->value('role_id'),
        ]);
        Redis::shouldReceive('setex')->once()->withArgs(fn ($key, $ttl, $id) => str_starts_with($key, 'Prado+')
            && $ttl === 330 && $id === $this->premise->premise_id);
        Redis::shouldReceive('get')->andReturn((string) $this->premise->premise_id);
        Redis::shouldReceive('ttl')->andReturn(330);

        $this->signIn($admin, ClientPlatform::WEB)
            ->postJson('/api/admin/premises/'.$this->premise->premise_id.'/qr-tokens')
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('TTL', 300)
            ->assertJsonPath('premise.name', 'Prado')
            ->assertJsonStructure(['token', 'TTL', 'expires_at', 'premise' => ['premise_id', 'name']]);
    }

    public function test_generar_el_qr_con_redis_caido_o_sin_confirmacion(): void
    {
        $admin = User::create([
            'external_identifier' => 'ad', 'name' => 'Ana', 'item' => 9,
            'role_id' => Role::where('name', Role::ADMIN)->value('role_id'),
        ]);
        $url = '/api/admin/premises/'.$this->premise->premise_id.'/qr-tokens';

        Redis::shouldReceive('setex')->once()->andThrow(new \RuntimeException('redis down'));
        $this->signIn($admin, ClientPlatform::WEB)->postJson($url)->assertStatus(503)->assertJson([
            'status' => 1, 'code' => 'QR_SERVICE_UNAVAILABLE', 'retryable' => true,
        ]);
    }

    public function test_generar_el_qr_sin_confirmacion_de_redis(): void
    {
        $admin = User::create([
            'external_identifier' => 'ad', 'name' => 'Ana', 'item' => 9,
            'role_id' => Role::where('name', Role::ADMIN)->value('role_id'),
        ]);
        Redis::shouldReceive('setex')->once();
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('ttl')->andReturn(330);

        $this->signIn($admin, ClientPlatform::WEB)
            ->postJson('/api/admin/premises/'.$this->premise->premise_id.'/qr-tokens')
            ->assertStatus(503)->assertJson(['code' => 'QR_STORE_FAILED', 'retryable' => true]);
    }
}
