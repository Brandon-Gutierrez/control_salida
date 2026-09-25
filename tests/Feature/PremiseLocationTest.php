<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckActiveSession;
use App\Http\Middleware\CheckPremiseLocation;
use App\Models\Premise;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActiveSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** Validación de ubicación (50 m) y gestión de predios con coordenadas y responsable. */
class PremiseLocationTest extends TestCase
{
    use RefreshDatabase;

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

    private function runMiddleware(array $override = []): Response
    {
        $payload = array_merge([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'accuracy_m' => 8,
            'location_timestamp' => now()->toIso8601String(),
            'is_mocked' => false,
            'vpn_detected' => false,
        ], $override);

        $request = Request::create('/api/qr/scan', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');

        return app(CheckPremiseLocation::class)->handle($request, fn () => response()->json(['ok' => true]));
    }

    /** 1 grado de latitud = 111.195 km. */
    private function metersNorth(float $m): float
    {
        return self::LAT + $m / 111_195;
    }

    public function test_dentro_de_50_metros_se_acepta(): void
    {
        $this->assertSame(200, $this->runMiddleware(['latitude' => $this->metersNorth(25)])->getStatusCode());
    }

    public function test_a_mas_de_50_metros_se_rechaza(): void
    {
        $this->assertSame(403, $this->runMiddleware(['latitude' => $this->metersNorth(120)])->getStatusCode());
    }

    public function test_la_imprecision_reportada_cuenta_contra_el_radio(): void
    {
        $this->assertSame(403, $this->runMiddleware(['latitude' => $this->metersNorth(45), 'accuracy_m' => 20])->getStatusCode());
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
        app(CheckPremiseLocation::class)->handle($request, fn () => response()->json([]));
    }

    // ---------------------------------------------------- gestión de predios

    private function admin(): static
    {
        $admin = User::create([
            'external_identifier' => 'adm', 'name' => 'Admin', 'item' => 1,
            'role_id' => Role::where('name', Role::ADMIN)->value('role_id'),
        ]);

        return $this->withoutMiddleware(CheckActiveSession::class)->actingAs($admin, 'web');
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
}
