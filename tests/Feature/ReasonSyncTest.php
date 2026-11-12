<?php

namespace Tests\Feature;

use App\Models\LeaveReason;
use App\Models\Premise;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Repositories\PremiseRepository;
use App\Services\Leave\LeaveReasonSyncService;
use App\Support\ClientPlatform;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Sincronización de los motivos de salida con Comteco: manual, programada y sus protecciones. */
class ReasonSyncTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private User $admin;

    /** Respuesta vigente de Comteco; las pruebas la cambian con `comtecoReplies`. */
    private \Closure $reply;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.external_api' => ['key' => 'k', 'reasons_url' => 'https://ext.test/reasons']]);
        // Una sola simulación (Http::fake se acumula): devuelve lo que diga `$this->reply`.
        $this->reply = fn () => Http::response(['data' => []]);
        Http::fake(['ext.test/*' => fn () => ($this->reply)()]);
        Role::create(['name' => Role::EMPLOYEE]);
        $adminRole = Role::create(['name' => Role::ADMIN]);
        $this->admin = User::create(['external_identifier' => 'ad', 'name' => 'Ana', 'item' => 9, 'role_id' => $adminRole->role_id]);
    }

    /** Comteco responde con este catálogo (código => descripción). */
    private function comtecoSends(array $catalog): void
    {
        $this->comtecoReplies(fn () => Http::response(['data' => collect($catalog)
            ->map(fn ($name, $code) => ['codigo' => (string) $code, 'descripcion' => $name])
            ->values()->all()]));
    }

    private function comtecoReplies(\Closure $reply): void
    {
        $this->reply = $reply;
    }

    private function seedCatalog(array $catalog): void
    {
        foreach ($catalog as $code => $name) {
            LeaveReason::create(['code' => (string) $code, 'name' => $name]);
        }
    }

    private function sync(): \Illuminate\Testing\TestResponse
    {
        return $this->signIn($this->admin, ClientPlatform::WEB)->postJson('/api/admin/reasons/sync');
    }

    /** @return array<int, string> Nombres de los motivos activos. */
    private function active(): array
    {
        return LeaveReason::orderBy('name')->pluck('name')->all();
    }

    // ------------------------------------------------------------- resultado

    public function test_agrega_actualiza_y_cuenta_los_cambios(): void
    {
        $this->seedCatalog(['T' => 'Trámite', 'M' => 'Medico']);
        $this->comtecoSends(['T' => 'Trámite', 'M' => 'Médico', 'A' => 'Almuerzo']);

        $this->sync()->assertOk()
            ->assertJsonPath('message', 'Motivos de salida actualizados correctamente')
            ->assertJsonPath('result', ['added' => 1, 'updated' => 1, 'reactivated' => 0, 'deactivated' => 0])
            ->assertJsonPath('sync.status', 'success')
            ->assertJsonPath('sync.source', 'manual')
            ->assertJsonPath('sync.actor', 'Ana');

        $this->assertSame(['Almuerzo', 'Médico', 'Trámite'], $this->active());
    }

    public function test_sin_cambios_lo_dice(): void
    {
        $this->seedCatalog(['T' => 'Trámite']);
        $this->comtecoSends(['T' => 'Trámite']);

        $this->sync()->assertOk()
            ->assertJsonPath('message', 'No se encontraron cambios en los motivos de salida')
            ->assertJsonPath('result.added', 0);
    }

    // ------------------------------------------------------- desactivar / reactivar

    public function test_un_motivo_que_comteco_retira_se_desactiva_pero_el_historial_lo_conserva(): void
    {
        $this->seedCatalog(['T' => 'Trámite', 'M' => 'Médico', 'A' => 'Almuerzo']);
        $premise = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        app(PremiseRepository::class)->syncReasonsByName($premise, ['Trámite', 'Médico']);
        $relationId = \App\Models\ReasonPremise::where('reason_id', LeaveReason::where('code', 'M')->value('reason_id'))->value('id');
        $record = Record::create([
            'user_id' => $this->admin->user_id, 'reason_premise_id' => $relationId,
            'leave_time' => now()->subHour(), 'return_time' => now(),
        ]);
        $this->comtecoSends(['T' => 'Trámite', 'A' => 'Almuerzo']);

        $this->sync()->assertOk()->assertJsonPath('result.deactivated', 1);

        // Ya no se ofrece...
        $this->assertSame(['Almuerzo', 'Trámite'], $this->active());
        $this->assertSame(['Trámite'], $premise->fresh()->reasons()->pluck('name')->all());
        $this->signIn($this->admin, ClientPlatform::WEB)->getJson('/api/admin/reasons')
            ->assertJsonPath('reasons', ['Almuerzo', 'Trámite']);
        // ...pero el historial la sigue mostrando.
        $this->assertSame('Médico', Record::with('reasonPremise.reason')->find($record->record_id)->reasonPremise->reason->name);
        $this->assertSoftDeleted('reasons', ['code' => 'M']);
    }

    public function test_si_comteco_vuelve_a_enviar_un_motivo_se_reactiva(): void
    {
        $this->seedCatalog(['T' => 'Trámite', 'M' => 'Médico', 'A' => 'Almuerzo']);
        LeaveReason::where('code', 'M')->first()->delete();
        $this->comtecoSends(['T' => 'Trámite', 'M' => 'Médico', 'A' => 'Almuerzo']);

        $this->sync()->assertOk()->assertJsonPath('result.reactivated', 1);

        $this->assertSame(['Almuerzo', 'Médico', 'Trámite'], $this->active());
        $this->assertSame(1, LeaveReason::withTrashed()->where('code', 'M')->count(), 'no debe duplicar el motivo');
    }

    // ----------------------------------------------- respuestas inválidas: no se toca nada

    private function assertCatalogUntouched(): void
    {
        $this->assertSame(['Médico', 'Trámite'], $this->active());
        $this->assertSame(0, LeaveReason::onlyTrashed()->count());
    }

    public static function invalidResponses(): array
    {
        return [
            'lista vacía' => [['data' => []], 422, 'REASONS_RESPONSE_INVALID', 'vacía'],
            'sin código' => [['data' => [['descripcion' => 'Algo']]], 422, 'REASONS_RESPONSE_INVALID', 'sin código'],
            'sin descripción' => [['data' => [['codigo' => 'X', 'descripcion' => '  ']]], 422, 'REASONS_RESPONSE_INVALID', 'sin descripción'],
            'códigos repetidos' => [['data' => [
                ['codigo' => 'T', 'descripcion' => 'Uno'], ['codigo' => 'T', 'descripcion' => 'Dos'],
            ]], 422, 'REASONS_RESPONSE_INVALID', 'repetidos'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidResponses')]
    public function test_una_respuesta_invalida_conserva_el_catalogo(array $body, int $status, string $code, string $fragment): void
    {
        $this->seedCatalog(['T' => 'Trámite', 'M' => 'Médico']);
        $this->comtecoReplies(fn () => Http::response($body));

        $this->sync()->assertStatus($status)
            ->assertJsonPath('code', $code)
            ->assertJsonPath('message', fn ($message) => str_contains($message, $fragment) && str_contains($message, 'se conserva el catálogo'));

        $this->assertCatalogUntouched();
        $this->assertSame('failed', app(LeaveReasonSyncService::class)->status()['status']);
    }

    public function test_si_comteco_no_responde_bien_se_conserva_el_catalogo(): void
    {
        $this->seedCatalog(['T' => 'Trámite', 'M' => 'Médico']);

        foreach ([
            fn () => Http::response('x', 500),
            fn () => Http::response(['data' => null]),
            fn () => Http::response(['error' => 5, 'data' => [['codigo' => 'Z', 'descripcion' => 'Z']]]),
        ] as $reply) {
            $this->comtecoReplies($reply);
            $this->sync()->assertStatus(400)->assertExactJson(['status' => 1, 'message' => 'Error al obtener los motivos de salida']);
        }

        $this->comtecoReplies(fn () => throw new ConnectionException('sin red'));
        $this->sync()->assertStatus(502)->assertJsonPath('code', 'REASONS_SERVICE_UNAVAILABLE');

        $this->assertCatalogUntouched();
    }

    public function test_una_falla_no_borra_la_fecha_de_la_ultima_sincronizacion_correcta(): void
    {
        $this->seedCatalog(['T' => 'Trámite']);
        $this->comtecoSends(['T' => 'Trámite']);
        $this->sync()->assertOk();
        $lastSuccess = app(LeaveReasonSyncService::class)->status()['last_success_at'];

        $this->comtecoReplies(fn () => Http::response('x', 500));
        $this->sync()->assertStatus(400);

        $status = app(LeaveReasonSyncService::class)->status();
        $this->assertSame('failed', $status['status']);
        $this->assertSame('Error al obtener los motivos de salida', $status['error']);
        $this->assertSame($lastSuccess, $status['last_success_at']);
    }

    // ------------------------------------------- protección contra desactivar demasiado

    public function test_no_desactiva_mas_de_la_mitad_de_los_motivos_de_una_vez(): void
    {
        $this->seedCatalog(['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D']);
        $this->comtecoSends(['A' => 'A']); // retiraría 3 de 4

        $this->sync()->assertStatus(422)
            ->assertJsonPath('code', 'REASONS_SYNC_TOO_DESTRUCTIVE')
            ->assertJsonPath('message', fn ($m) => str_contains($m, '3 de 4'));

        $this->assertSame(4, LeaveReason::count());
    }

    public function test_exactamente_la_mitad_si_se_permite(): void
    {
        $this->seedCatalog(['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D']);
        $this->comtecoSends(['A' => 'A', 'B' => 'B']); // retira 2 de 4

        $this->sync()->assertOk()->assertJsonPath('result.deactivated', 2);
    }

    public function test_el_comando_con_force_permite_una_retirada_masiva_legitima(): void
    {
        $this->seedCatalog(['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D']);
        $this->comtecoSends(['A' => 'A']);

        $this->artisan('reasons:sync')->assertFailed();
        $this->assertSame(4, LeaveReason::count());

        $this->artisan('reasons:sync --force')->assertSuccessful();
        $this->assertSame(1, LeaveReason::count());
        $this->assertSame('console', app(LeaveReasonSyncService::class)->status()['source']);
    }

    // ---------------------------------------------------- una sola ejecución a la vez

    public function test_no_se_permiten_dos_sincronizaciones_a_la_vez(): void
    {
        $this->comtecoSends(['T' => 'Trámite']);
        $held = Cache::lock('reasons-sync', 60);
        $this->assertTrue($held->get());

        $this->sync()->assertStatus(409)->assertJsonPath('code', 'REASONS_SYNC_IN_PROGRESS');
        $this->assertDatabaseCount('reasons', 0);

        $held->release();
        $this->sync()->assertOk();
    }

    // --------------------------------------------------- tarea programada y comando

    public function test_la_sincronizacion_programada_corre_de_lunes_a_viernes_a_las_00_00_hora_de_bolivia(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'reasons:sync'));

        // 00:00 y dos reintentos (00:15 y 00:30).
        $this->assertSame(['0 0 * * 1-5', '15 0 * * 1-5', '30 0 * * 1-5'], $events->map->expression->sort()->values()->all());
        foreach ($events as $event) {
            $this->assertSame('America/La_Paz', (string) $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
            $this->assertStringContainsString('--scheduled', $event->command);
        }
    }

    public function test_los_reintentos_programados_no_hacen_nada_si_hoy_ya_funciono(): void
    {
        $this->seedCatalog(['T' => 'Trámite']);
        $this->comtecoSends(['T' => 'Trámite', 'N' => 'Nuevo']);

        $this->artisan('reasons:sync --scheduled')->assertSuccessful();
        $this->assertSame('scheduled', app(LeaveReasonSyncService::class)->status()['source']);
        $this->assertSame(2, LeaveReason::count());

        // Comteco agrega otro motivo: el reintento de las 00:15 no corre porque ya sincronizó hoy.
        $this->comtecoSends(['T' => 'Trámite', 'N' => 'Nuevo', 'X' => 'Extra']);
        $this->artisan('reasons:sync --scheduled')->expectsOutput('Los motivos ya se sincronizaron hoy.')->assertSuccessful();
        $this->assertSame(2, LeaveReason::count());

        // A mano (sin --scheduled) sí se puede volver a ejecutar.
        $this->artisan('reasons:sync')->assertSuccessful();
        $this->assertSame(3, LeaveReason::count());
    }

    public function test_si_la_de_las_00_00_falla_el_reintento_corre(): void
    {
        $this->seedCatalog(['T' => 'Trámite']);
        $this->comtecoReplies(fn () => Http::response('x', 500));
        $this->artisan('reasons:sync --scheduled')->assertFailed();
        $this->assertFalse(app(LeaveReasonSyncService::class)->hasSucceededToday());

        $this->comtecoSends(['T' => 'Trámite', 'N' => 'Nuevo']);
        $this->artisan('reasons:sync --scheduled')->assertSuccessful();

        $this->assertSame(2, LeaveReason::count());
        $this->assertTrue(app(LeaveReasonSyncService::class)->hasSucceededToday());
    }

    // ------------------------------------------------------------ panel (lectura)

    public function test_el_catalogo_lista_activos_y_retirados_con_su_uso(): void
    {
        $this->seedCatalog(['T' => 'Trámite', 'M' => 'Médico']);
        $premise = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        app(PremiseRepository::class)->syncReasonsByName($premise, ['Trámite']);
        LeaveReason::where('code', 'M')->first()->delete();

        $this->signIn($this->admin, ClientPlatform::WEB)->getJson('/api/admin/reasons/catalog')
            ->assertOk()
            ->assertJsonPath('data.sync', null)
            ->assertJsonPath('data.reasons.0.name', 'Trámite')
            ->assertJsonPath('data.reasons.0.active', true)
            ->assertJsonPath('data.reasons.0.premises_count', 1)
            ->assertJsonPath('data.reasons.1.name', 'Médico')
            ->assertJsonPath('data.reasons.1.active', false)
            ->assertJsonPath('data.reasons.1.code', 'M');
    }

    public function test_el_resumen_de_inicio_avisa_si_la_ultima_sincronizacion_fallo(): void
    {
        $this->signIn($this->admin, ClientPlatform::WEB)->getJson('/api/admin/summary')
            ->assertJsonPath('data.reasons_sync', null);

        $this->comtecoReplies(fn () => Http::response('x', 500));
        $this->sync()->assertStatus(400);

        $this->signIn($this->admin, ClientPlatform::WEB)->getJson('/api/admin/summary')
            ->assertJsonPath('data.reasons_sync.status', 'failed')
            ->assertJsonPath('data.reasons_sync.error', 'Error al obtener los motivos de salida');
    }

    public function test_solo_el_admin_ve_el_catalogo_y_sincroniza(): void
    {
        $employee = User::create([
            'external_identifier' => 'e', 'name' => 'Juan', 'item' => 2,
            'role_id' => Role::where('name', Role::EMPLOYEE)->value('role_id'),
        ]);

        $this->signIn($employee, ClientPlatform::MOBILE)->getJson('/api/admin/reasons/catalog')->assertStatus(403);
        $this->signIn($employee, ClientPlatform::MOBILE)->postJson('/api/admin/reasons/sync')->assertStatus(403);
    }
}
