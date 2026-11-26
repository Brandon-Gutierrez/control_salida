<?php

namespace Tests\Feature;

use App\Models\LeaveReason;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Detalle de las salidas de la persona (predio, motivo, horas y duración). */
class MyLeavesTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private User $employee;

    private const HISTORY_URL = 'https://comteco.test/listOutputByItem';

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => Role::EMPLOYEE]);
        $this->employee = User::create([
            'external_identifier' => 'e1', 'name' => 'Juan', 'item' => 2057, 'role_id' => $role->role_id,
        ]);
        config([
            'services.external_api.leave_history_url' => self::HISTORY_URL,
            'services.external_api.key' => 'test-key',
        ]);
        Http::preventStrayRequests();
    }

    private function leave(string $from, ?string $to, array $fields = []): array
    {
        return $fields + [
            'ID_SOLICITUD' => '106392',
            'COD_EMPLEADO' => (string) $this->employee->item,
            'COD_MOTIVO' => 'PARTICULAR',
            'FECHA_SALIDA' => $from,
            'FECHA_RETORNO' => $to,
            'PREDIO' => null,
        ];
    }

    private function history(array $rows, int $error = 0): void
    {
        Http::fake([self::HISTORY_URL.'?*' => Http::response(['error' => $error, 'data' => $rows])]);
    }

    private function list(string $from, string $to)
    {
        return $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson("/api/me/leaves?from={$from}&to={$to}");
    }

    public function test_devuelve_predio_motivo_horas_y_duracion_la_mas_reciente_primero(): void
    {
        LeaveReason::create(['name' => 'Trámite particular', 'code' => 'PARTICULAR'])->delete();
        $this->history([
            $this->leave('2026-11-16 12:17:40', '2026-11-16 12:34:44', ['COD_MOTIVO' => 'ALMUERZO']),
            $this->leave('2026-11-18 10:06:40', '2026-11-18 10:33:08', ['PREDIO' => 'Prado']),
        ]);

        $this->list('2026-11-16', '2026-11-18')->assertOk()->assertExactJson([
            'status' => 0,
            'data' => [
                [
                    'leave_time' => '2026-11-18T10:06:40-04:00',
                    'return_time' => '2026-11-18T10:33:08-04:00',
                    'premise' => 'Prado',
                    'reason' => 'Trámite particular',
                    'minutes' => 26,
                ],
                [
                    'leave_time' => '2026-11-16T12:17:40-04:00',
                    'return_time' => '2026-11-16T12:34:44-04:00',
                    'premise' => null,
                    'reason' => 'ALMUERZO',
                    'minutes' => 17,
                ],
            ],
        ]);
    }

    public function test_una_salida_sin_retorno_no_tiene_hora_de_retorno_ni_duracion(): void
    {
        $this->history([$this->leave('2025-10-15 08:00:00', null)]);

        $data = $this->list('2025-10-15', '2025-10-15')->assertOk()->json('data');

        $this->assertNull($data[0]['return_time']);
        $this->assertNull($data[0]['minutes']);
    }

    public function test_solo_incluye_el_rango_y_las_salidas_de_la_persona(): void
    {
        $this->history([
            $this->leave('2025-10-14 23:59:59', '2025-10-15 00:10:00'),
            $this->leave('2025-10-15 00:00:00', '2025-10-15 00:10:00'),
            $this->leave('2025-10-15 23:59:59', '2025-10-16 00:30:00'),
            $this->leave('2025-10-16 00:00:00', '2025-10-16 00:05:00'),
            $this->leave('2025-10-15 09:00:00', '2025-10-15 09:05:00', ['COD_EMPLEADO' => '9']),
        ]);

        $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson('/api/me/leaves?from=2025-10-15&to=2025-10-15&item=9')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.leave_time', '2025-10-15T23:59:59-04:00')
            ->assertJsonPath('data.0.minutes', 30)
            ->assertJsonPath('data.1.leave_time', '2025-10-15T00:00:00-04:00');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === self::HISTORY_URL.'?item=2057'
            && $request->hasHeader('keysoftware', 'test-key'));
        Http::assertSentCount(1);
    }

    public function test_exige_fechas_validas(): void
    {
        $client = $this->signIn($this->employee, ClientPlatform::MOBILE);

        $client->getJson('/api/me/leaves')->assertStatus(422);
        $client->getJson('/api/me/leaves?from=2025-10-15&to=2025-10-01')->assertStatus(422);
    }

    public function test_el_historial_vacio_devuelve_una_lista_vacia(): void
    {
        $this->history([]);

        $this->list('2025-10-15', '2025-10-15')->assertOk()->assertExactJson(['status' => 0, 'data' => []]);
    }

    public function test_reporta_un_fallo_de_comteco_como_error_temporal(): void
    {
        $this->history([], error: 1);

        $this->list('2025-10-15', '2025-10-15')->assertStatus(502)
            ->assertJsonPath('code', 'LEAVE_HISTORY_UNAVAILABLE');
    }
}
