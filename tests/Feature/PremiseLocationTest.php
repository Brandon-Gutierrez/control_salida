<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureLocationProof;
use App\Models\LeaveReason;
use App\Models\Premise;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActiveSession;
use App\Services\Location\PremiseLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/**
 * Ubicación: la prueba enviada por el teléfono es válida (middleware) y la
 * persona está a 50 m o menos del predio del QR o del comprobante (servicio).
 * Más la gestión de predios con coordenadas y responsable.
 */
class PremiseLocationTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private const LAT = -17.3935;   // El Prado, Cochabamba
    private const LNG = -66.1570;

    private Premise $premise;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => Role::EMPLOYEE]);
        Role::create(['name' => Role::ADMIN]);
        $this->premise = Premise::create(['name' => 'Prado', 'latitude' => self::LAT, 'longitude' => self::LNG]);
    }

    private function payload(array $override = []): array
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

    private function runMiddleware(array $override = []): Response
    {
        $request = Request::create('/api/qr/scan', 'POST', $this->payload($override));
        $request->headers->set('Accept', 'application/json');

        return app(EnsureLocationProof::class)->handle($request, fn () => response()->json(['ok' => true]));
    }

    /** 1 grado de latitud = 111.195 km. */
    private function metersNorth(float $m): float
    {
        return self::LAT + $m / 111_195;
    }

    private function assertLocation(array $override = [], ?Premise $premise = null): ?ApiException
    {
        $location = [
            'latitude' => $override['latitude'] ?? self::LAT,
            'longitude' => $override['longitude'] ?? self::LNG,
            'accuracy_m' => $override['accuracy_m'] ?? 8,
        ];

        try {
            app(PremiseLocationService::class)->assertWithinPremise($location, ($premise ?? $this->premise)->premise_id);
        } catch (ApiException $exception) {
            return $exception;
        }

        return null;
    }

    private function codeOf(?ApiException $exception): ?string
    {
        return $exception ? json_decode($exception->render()->getContent(), true)['code'] : null;
    }

    // ------------------------------------------------ prueba de ubicación (middleware)

    public function test_una_prueba_valida_pasa_y_queda_en_la_peticion(): void
    {
        $this->assertSame(200, $this->runMiddleware()->getStatusCode());
    }

    public function test_el_middleware_ya_no_decide_la_distancia(): void
    {
        // Lejos de todo predio: la distancia la comprueba el servicio, con el predio del QR.
        $this->assertSame(200, $this->runMiddleware(['latitude' => $this->metersNorth(5000)])->getStatusCode());
    }

    public function test_gps_falso_se_rechaza_aunque_este_en_el_predio(): void
    {
        $r = $this->runMiddleware(['is_mocked' => true]);
        $this->assertSame(403, $r->getStatusCode());
        $this->assertSame('LOCATION_SPOOFING_DETECTED', json_decode($r->getContent(), true)['code']);
    }

    public function test_vpn_se_rechaza_aunque_este_en_el_predio(): void
    {
        $r = $this->runMiddleware(['vpn_detected' => true]);
        $this->assertSame('LOCATION_SPOOFING_DETECTED', json_decode($r->getContent(), true)['code']);
    }

    public function test_ubicacion_vieja_se_rechaza(): void
    {
        $this->assertSame(403, $this->runMiddleware(['location_timestamp' => now()->subMinutes(10)->toIso8601String()])->getStatusCode());
    }

    public function test_ubicacion_del_futuro_se_rechaza(): void
    {
        $this->assertSame(403, $this->runMiddleware(['location_timestamp' => now()->addMinutes(5)->toIso8601String()])->getStatusCode());
    }

    public function test_precision_cero_se_rechaza(): void
    {
        $this->expectException(ValidationException::class);
        $this->runMiddleware(['accuracy_m' => 0]);
    }

    public function test_sin_banderas_antifraude_se_rechaza(): void
    {
        $this->expectException(ValidationException::class);
        $request = Request::create('/x', 'POST', [
            'latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy_m' => 5,
            'location_timestamp' => now()->toIso8601String(),
        ]);
        app(EnsureLocationProof::class)->handle($request, fn () => response()->json([]));
    }

    // ------------------------------------------------ distancia al predio (50 m)

    public function test_el_radio_es_de_50_metros_y_la_precision_maxima_de_30(): void
    {
        $service = app(PremiseLocationService::class);
        $this->assertSame(50, $service->radiusMeters());
        $this->assertSame(30, $service->maxAccuracyMeters());
    }

    public function test_dentro_de_los_50_metros_se_acepta(): void
    {
        $this->assertNull($this->assertLocation(['latitude' => $this->metersNorth(25)]));
        $this->assertNull($this->assertLocation(['latitude' => $this->metersNorth(48)]));
    }

    public function test_fuera_de_los_50_metros_se_rechaza(): void
    {
        $error = $this->assertLocation(['latitude' => $this->metersNorth(60)]);

        $this->assertSame('OUTSIDE_PREMISE_RADIUS', $this->codeOf($error));
        $this->assertStringContainsString('50 m o menos del predio Prado', $error->getMessage());
    }

    public function test_con_buena_precision_basta_con_estar_a_50_metros_aunque_la_precision_sea_de_30(): void
    {
        // La regla ya no resta la precisión del radio: 45 m con GPS de 30 m es válido.
        $this->assertNull($this->assertLocation(['latitude' => $this->metersNorth(45), 'accuracy_m' => 30]));
    }

    public function test_un_gps_demasiado_impreciso_se_rechaza_aunque_este_en_el_predio(): void
    {
        $error = $this->assertLocation(['accuracy_m' => 31]);

        $this->assertSame('LOCATION_ACCURACY_TOO_LOW', $this->codeOf($error));
        $this->assertStringContainsString('máximo 30 m', $error->getMessage());
    }

    public function test_un_predio_sin_coordenadas_no_acepta_escaneos(): void
    {
        $sin = Premise::create(['name' => 'Sin mapa']);

        $this->assertSame('PREMISE_WITHOUT_LOCATION', $this->codeOf($this->assertLocation([], $sin)));
    }

    public function test_el_radio_se_puede_configurar(): void
    {
        config(['geofence.radius_m' => 100]);

        $this->assertNull($this->assertLocation(['latitude' => $this->metersNorth(90)]));
        $this->assertSame('OUTSIDE_PREMISE_RADIUS', $this->codeOf($this->assertLocation(['latitude' => $this->metersNorth(110)])));
    }

    // ------------------------------- la distancia es al predio del QR, no a cualquiera

    private function employee(): User
    {
        return User::create([
            'external_identifier' => 'emp', 'name' => 'Juan', 'item' => 2,
            'role_id' => Role::where('name', Role::EMPLOYEE)->value('role_id'),
        ]);
    }

    public function test_cerca_de_otro_predio_no_sirve_para_el_qr_de_este(): void
    {
        // La persona está junto al predio Norte pero escanea el QR de Prado.
        $norte = Premise::create(['name' => 'Norte', 'latitude' => -16.4, 'longitude' => -68.2]);
        Redis::shouldReceive('get')->with('Prado+token')->andReturn((string) $this->premise->premise_id);

        $this->signIn($this->employee(), 'mobile')
            ->postJson('/api/qr/scan', ['qrData' => 'Prado+token'] + $this->payload([
                'latitude' => $norte->latitude, 'longitude' => $norte->longitude,
            ]))
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTSIDE_PREMISE_RADIUS');
    }

    public function test_al_confirmar_la_ubicacion_debe_seguir_en_el_predio_del_comprobante(): void
    {
        $employee = $this->employee();
        $norte = Premise::create(['name' => 'Norte', 'latitude' => -16.4, 'longitude' => -68.2]);
        $reason = LeaveReason::create(['name' => 'Trámite', 'code' => 'T']);
        DB::table('reason_premise')->insert(['reason_id' => $reason->reason_id, 'premise_id' => $this->premise->premise_id]);
        Redis::shouldReceive('get')->with('leave-ticket:ticket-1')->andReturn(json_encode([
            'user_id' => $employee->user_id, 'premise_id' => $this->premise->premise_id,
        ]));
        Http::fake();

        // Escaneó en Prado, pero al confirmar ya está lejos (en el predio Norte).
        $this->signIn($employee, 'mobile')
            ->postJson('/api/leaves', [
                'leaveTicket' => 'ticket-1', 'namePremise' => 'Prado', 'nameReason' => 'Trámite',
            ] + $this->payload(['latitude' => $norte->latitude, 'longitude' => $norte->longitude]))
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTSIDE_PREMISE_RADIUS');

        $this->assertDatabaseCount('records', 0);
        Http::assertNothingSent();
    }

    // ------------------------------------ el radio llega a la web de administración

    public function test_la_lista_de_predios_incluye_el_radio_de_la_geocerca(): void
    {
        $this->admin()->getJson('/api/admin/premises')
            ->assertOk()
            ->assertJsonPath('geofence_radius_m', 50);

        config(['geofence.radius_m' => 80]);
        $this->admin()->getJson('/api/admin/premises')->assertJsonPath('geofence_radius_m', 80);
    }

    // ---------------------------------------------------- gestión de predios

    private function admin(): static
    {
        $admin = User::firstOrCreate(['external_identifier' => 'adm'], [
            'name' => 'Admin', 'item' => 1,
            'role_id' => Role::where('name', Role::ADMIN)->value('role_id'),
        ]);

        return $this->signIn($admin);
    }

    private function manager(string $u, ?int $premiseId = null): User
    {
        return User::create([
            'external_identifier' => "m-$u", 'name' => "Gestor $u", 'item' => random_int(10, 99999),
            'username' => $u, 'password' => bcrypt('x-clave-1234'),
            'role_id' => Role::where('name', Role::MANAGE_PREMISE)->value('role_id'),
            'premise_id' => $premiseId,
        ]);
    }

    public function test_crear_predio_con_coordenadas_y_responsable(): void
    {
        $m = $this->manager('ana');
        $res = $this->admin()->postJson('/api/admin/premises', [
            'name' => 'Nuevo', 'latitude' => -17.39, 'longitude' => -66.15, 'manager_user_id' => $m->user_id,
        ])->assertCreated();

        $res->assertJsonPath('data.manager.user_id', $m->user_id);
        $this->assertSame($res->json('data.id'), $m->fresh()->premise_id);
    }

    public function test_crear_predio_sin_coordenadas_falla(): void
    {
        $this->admin()->postJson('/api/admin/premises', ['name' => 'Sin mapa'])->assertStatus(422);
    }

    public function test_editar_nombre_ubicacion_y_cambiar_responsable(): void
    {
        $old = $this->manager('vieja', $this->premise->premise_id);
        $new = $this->manager('nuevo');
        UserActiveSession::create(['user_id' => $old->user_id, 'session_id' => 's1', 'ip_address' => '1.1.1.1']);

        $this->admin()->putJson("/api/admin/premises/{$this->premise->premise_id}", [
            'name' => 'Prado 2', 'latitude' => -17.4, 'longitude' => -66.16, 'manager_user_id' => $new->user_id,
        ])->assertOk()->assertJsonPath('data.name', 'Prado 2')->assertJsonPath('data.manager.user_id', $new->user_id);

        $this->assertNull($old->fresh()->premise_id);
        $this->assertSame($this->premise->premise_id, $new->fresh()->premise_id);
        $this->assertSame(0, UserActiveSession::where('user_id', $old->user_id)->count());
    }

    public function test_quitar_responsable(): void
    {
        $m = $this->manager('sola', $this->premise->premise_id);
        $this->admin()->putJson("/api/admin/premises/{$this->premise->premise_id}", ['manager_user_id' => null])
            ->assertOk()->assertJsonPath('data.manager', null);
        $this->assertNull($m->fresh()->premise_id);
    }

    public function test_el_responsable_debe_tener_rol_manage_premise(): void
    {
        $emp = User::create(['external_identifier' => 'e', 'name' => 'E', 'item' => 5,
            'role_id' => Role::where('name', Role::EMPLOYEE)->value('role_id')]);
        $this->admin()->putJson("/api/admin/premises/{$this->premise->premise_id}", ['manager_user_id' => $emp->user_id])
            ->assertStatus(422);
    }

    public function test_el_listado_incluye_ubicacion_y_responsable(): void
    {
        $this->manager('lista', $this->premise->premise_id);
        $this->admin()->getJson('/api/admin/premises')->assertOk()
            ->assertJsonPath('data.0.manager.name', 'Gestor lista');
    }

    // ------------------------------------ un responsable por predio / clave

    public function test_no_se_crea_un_segundo_responsable_para_el_mismo_predio(): void
    {
        $this->manager('primero', $this->premise->premise_id);
        $this->admin()->postJson('/api/admin/users/premise-managers', [
            'name' => 'Otro', 'username' => 'segundo', 'premise_id' => $this->premise->premise_id,
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Gestor primero'));
        $this->assertSame(0, User::where('username', 'segundo')->count());
    }

    public function test_no_se_reasigna_un_predio_ocupado_a_otro_responsable(): void
    {
        $this->manager('dueno', $this->premise->premise_id);
        $otro = $this->manager('otro');
        $this->admin()->putJson("/api/admin/users/{$otro->user_id}/premise", ['premise_id' => $this->premise->premise_id])
            ->assertStatus(422);
        $this->assertNull($otro->fresh()->premise_id);
    }

    public function test_no_se_asigna_el_rol_gestor_a_un_predio_ocupado(): void
    {
        $this->manager('dueno2', $this->premise->premise_id);
        $emp = User::create(['external_identifier' => 'e2', 'name' => 'E2', 'item' => 77,
            'role_id' => Role::where('name', Role::EMPLOYEE)->value('role_id')]);
        $this->admin()->putJson("/api/admin/users/{$emp->user_id}/role", [
            'role_id' => Role::where('name', Role::MANAGE_PREMISE)->value('role_id'),
            'premise_id' => $this->premise->premise_id,
        ])->assertStatus(422);
    }

    public function test_el_mismo_responsable_puede_reafirmar_su_predio(): void
    {
        $m = $this->manager('mismo', $this->premise->premise_id);
        $this->admin()->putJson("/api/admin/users/{$m->user_id}/premise", ['premise_id' => $this->premise->premise_id])
            ->assertOk();
    }

    public function test_el_admin_cambia_la_clave_del_responsable_y_se_cierran_sus_sesiones(): void
    {
        $m = $this->manager('clave', $this->premise->premise_id);
        UserActiveSession::create(['user_id' => $m->user_id, 'session_id' => 's9', 'ip_address' => '1.1.1.1']);

        $this->admin()->putJson("/api/admin/users/{$m->user_id}/password", ['password' => 'nueva-clave-segura-1'])
            ->assertOk()->assertJsonPath('generated_password', null);

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('nueva-clave-segura-1', $m->fresh()->password));
        $this->assertSame(0, UserActiveSession::where('user_id', $m->user_id)->count());
    }

    public function test_el_admin_regenera_la_clave_del_responsable(): void
    {
        $m = $this->manager('regen', $this->premise->premise_id);
        $res = $this->admin()->putJson("/api/admin/users/{$m->user_id}/password", [])->assertOk();

        $generated = $res->json('generated_password');
        $this->assertGreaterThanOrEqual(20, strlen($generated));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($generated, $m->fresh()->password));
    }

    public function test_clave_corta_o_cuenta_que_no_es_responsable_se_rechaza(): void
    {
        $m = $this->manager('corta', $this->premise->premise_id);
        $this->admin()->putJson("/api/admin/users/{$m->user_id}/password", ['password' => 'corta'])->assertStatus(422);

        $emp = User::create(['external_identifier' => 'e3', 'name' => 'E3', 'item' => 78,
            'role_id' => Role::where('name', Role::EMPLOYEE)->value('role_id')]);
        $this->admin()->putJson("/api/admin/users/{$emp->user_id}/password", [])->assertStatus(422);
    }
}
