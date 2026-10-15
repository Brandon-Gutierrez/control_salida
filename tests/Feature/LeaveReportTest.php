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

/** Reporte de salidas del administrador: tiempo, destino, motivo y no retornos. */
class LeaveReportTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private User $admin;
    private User $juan;
    private User $ana;
    private int $pradoPivot;
    private int $centralPivot;
    private int $pradoId;

    protected function setUp(): void
    {
        parent::setUp();
        $employee = Role::create(['name' => Role::EMPLOYEE]);
        $adminRole = Role::create(['name' => Role::ADMIN]);
        $this->admin = User::create(['external_identifier' => 'a', 'name' => 'Admin', 'item' => 1, 'role_id' => $adminRole->role_id]);
        $this->juan = User::create(['external_identifier' => 'j', 'name' => 'Juan Pérez', 'item' => 2057, 'role_id' => $employee->role_id]);
        $this->ana = User::create(['external_identifier' => 'n', 'name' => 'Ana Rojas', 'item' => 3001, 'role_id' => $employee->role_id]);

        $prado = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        $central = Premise::create(['name' => 'Central', 'latitude' => -17.4, 'longitude' => -66.16]);
        $this->pradoId = $prado->premise_id;
        $tramite = LeaveReason::create(['name' => 'Trámite', 'code' => 'T']);
        $medico = LeaveReason::create(['name' => 'Médico', 'code' => 'M']);

        $this->pradoPivot = DB::table('reason_premise')->insertGetId(['reason_id' => $tramite->reason_id, 'premise_id' => $prado->premise_id]);
        $this->centralPivot = DB::table('reason_premise')->insertGetId(['reason_id' => $medico->reason_id, 'premise_id' => $central->premise_id]);
    }

    private function leave(User $user, int $pivot, string $from, ?string $to): void
    {
        Record::create(['user_id' => $user->user_id, 'reason_premise_id' => $pivot, 'leave_time' => $from, 'return_time' => $to]);
    }

    private function report(string $query = ''): array
    {
        return $this->signIn($this->admin, ClientPlatform::WEB)
            ->getJson('/api/admin/reports/leaves'.$query)->assertOk()->json('data');
    }

    public function test_resume_tiempo_destino_motivo_y_persona(): void
    {
        $this->travelTo(now()->setDate(2025, 10, 15)->setTime(14, 0));

        $this->leave($this->juan, $this->pradoPivot, '2025-10-14 09:00:00', '2025-10-14 10:00:00'); // 60
        $this->leave($this->juan, $this->pradoPivot, '2025-10-15 09:00:00', '2025-10-15 09:30:00'); // 30
        $this->leave($this->ana, $this->centralPivot, '2025-10-15 11:00:00', '2025-10-15 11:20:00'); // 20

        $data = $this->report('?from=2025-10-01&to=2025-10-15');

        $this->assertSame([3, 110, 37, 2], [
            $data['summary']['exits'], $data['summary']['minutes'],
            $data['summary']['average_minutes'], $data['summary']['people'],
        ]);
        $this->assertSame(['Prado', 2, 90], [$data['by_premise'][0]['name'], $data['by_premise'][0]['exits'], $data['by_premise'][0]['minutes']]);
        $this->assertSame(['Trámite', 'Médico'], array_column($data['by_reason'], 'name'));
        $this->assertSame(['Juan Pérez', 90], [$data['by_user'][0]['name'], $data['by_user'][0]['minutes']]);
    }

    public function test_advierte_de_salidas_sin_retorno_y_no_las_suma(): void
    {
        $this->travelTo(now()->setDate(2025, 10, 15)->setTime(14, 0));

        $this->leave($this->juan, $this->pradoPivot, '2025-10-10 09:00:00', null); // sin retorno, otro día
        $this->leave($this->ana, $this->centralPivot, '2025-10-15 13:00:00', null); // fuera ahora

        $data = $this->report('?from=2025-10-01&to=2025-10-15');

        $this->assertSame(1, $data['summary']['unreturned']);
        $this->assertSame(1, $data['summary']['outside_now']);
        $this->assertSame(1, $data['summary']['exits']); // solo la de hoy cuenta
        $this->assertSame('Juan Pérez', $data['unreturned'][0]['name']);
        $this->assertSame('Prado', $data['unreturned'][0]['premise']);
        $this->assertSame('Ana Rojas', $data['outside_now'][0]['name']);
        $this->assertSame(60, $data['outside_now'][0]['minutes']);
        $juan = collect($data['by_user'])->firstWhere('name', 'Juan Pérez');
        $this->assertSame([0, 1], [$juan['exits'], $juan['unreturned']]);
    }

    public function test_filtra_por_predio_motivo_y_persona(): void
    {
        $this->travelTo(now()->setDate(2025, 10, 15)->setTime(14, 0));
        $this->leave($this->juan, $this->pradoPivot, '2025-10-15 09:00:00', '2025-10-15 09:30:00');
        $this->leave($this->ana, $this->centralPivot, '2025-10-15 11:00:00', '2025-10-15 11:20:00');

        $byPremise = $this->report('?from=2025-10-15&to=2025-10-15&premise_id='.$this->pradoId);
        $this->assertSame(1, $byPremise['summary']['exits']);

        $byReason = $this->report('?from=2025-10-15&to=2025-10-15&reason='.urlencode('Médico'));
        $this->assertSame(['Central'], array_column($byReason['by_premise'], 'name'));

        $bySearch =$this->report('?from=2025-10-15&to=2025-10-15&search=ana');
        $this->assertSame(['Ana Rojas'], array_column($bySearch['by_user'], 'name'));

        $byItem = $this->report('?from=2025-10-15&to=2025-10-15&search=2057');
        $this->assertSame(['Juan Pérez'], array_column($byItem['by_user'], 'name'));
    }

    public function test_solo_el_admin_en_la_web_puede_verlo(): void
    {
        $this->signIn($this->juan, ClientPlatform::MOBILE)->getJson('/api/admin/reports/leaves')->assertStatus(403);
    }

    public function test_rechaza_un_rango_invalido(): void
    {
        $this->signIn($this->admin, ClientPlatform::WEB)
            ->getJson('/api/admin/reports/leaves?from=2025-10-15&to=2025-10-01')->assertStatus(422);
    }
}
