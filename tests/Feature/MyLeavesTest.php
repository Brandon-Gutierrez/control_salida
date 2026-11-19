<?php

namespace Tests\Feature;

use App\Models\LeaveReason;
use App\Models\Premise;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Detalle de las salidas de la persona (predio, motivo, horas y duración). */
class MyLeavesTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private User $employee;

    private int $reasonPremiseId;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => Role::EMPLOYEE]);
        $this->employee = User::create([
            'external_identifier' => 'e1', 'name' => 'Juan', 'item' => 2057, 'role_id' => $role->role_id,
        ]);
        $premise = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        $reason = LeaveReason::create(['name' => 'Trámite', 'code' => 'T']);
        $this->reasonPremiseId = DB::table('reason_premise')->insertGetId([
            'reason_id' => $reason->reason_id, 'premise_id' => $premise->premise_id,
        ]);
    }

    private function leave(string $from, ?string $to, ?User $user = null): void
    {
        Record::create([
            'user_id' => ($user ?? $this->employee)->user_id,
            'reason_premise_id' => $this->reasonPremiseId,
            'leave_time' => $from,
            'return_time' => $to,
        ]);
    }

    private function list(string $from, string $to)
    {
        return $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson("/api/me/leaves?from={$from}&to={$to}");
    }

    public function test_devuelve_predio_motivo_horas_y_duracion_la_mas_reciente_primero(): void
    {
        $this->leave('2025-10-15 08:00:00', '2025-10-15 08:45:00');
        $this->leave('2025-10-15 11:00:00', '2025-10-15 11:30:00');

        $data = $this->list('2025-10-15', '2025-10-15')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame('Prado', $data[0]['premise']);
        $this->assertSame('Trámite', $data[0]['reason']);
        $this->assertStringStartsWith('2025-10-15T11:00:00', $data[0]['leave_time']);
        $this->assertStringStartsWith('2025-10-15T11:30:00', $data[0]['return_time']);
        $this->assertSame(30, $data[0]['minutes']);
        $this->assertSame(45, $data[1]['minutes']);
    }

    public function test_una_salida_sin_retorno_no_tiene_hora_de_retorno_ni_duracion(): void
    {
        $this->leave('2025-10-15 08:00:00', null);

        $data = $this->list('2025-10-15', '2025-10-15')->assertOk()->json('data');

        $this->assertNull($data[0]['return_time']);
        $this->assertNull($data[0]['minutes']);
    }

    public function test_solo_incluye_el_rango_y_las_salidas_de_la_persona(): void
    {
        $role = Role::firstWhere('name', Role::EMPLOYEE);
        $other = User::create(['external_identifier' => 'e2', 'name' => 'Ana', 'item' => 9, 'role_id' => $role->role_id]);
        $this->leave('2025-10-14 23:59:00', '2025-10-15 00:10:00'); // sale el 14: fuera del rango
        $this->leave('2025-10-15 23:50:00', '2025-10-16 00:20:00'); // sale el 15: dentro
        $this->leave('2025-10-16 00:00:00', '2025-10-16 00:05:00'); // 16: fuera
        $this->leave('2025-10-15 09:00:00', '2025-10-15 09:05:00', $other); // de otra persona

        $data = $this->list('2025-10-15', '2025-10-15')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertStringStartsWith('2025-10-15T23:50:00', $data[0]['leave_time']);
    }

    public function test_exige_fechas_validas(): void
    {
        $client = $this->signIn($this->employee, ClientPlatform::MOBILE);

        $client->getJson('/api/me/leaves')->assertStatus(422);
        $client->getJson('/api/me/leaves?from=2025-10-15&to=2025-10-01')->assertStatus(422);
    }
}
