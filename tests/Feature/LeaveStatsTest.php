<?php

namespace Tests\Feature;

use App\Models\Premise;
use App\Models\LeaveReason;
use App\Models\ReasonPremise;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Estadísticas de salidas (día / semana / mes calendario) y perfil de terceros. */
class LeaveStatsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private User $employee;
    private int $reasonPremiseId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('file')->flush();
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

    private function leave(string $from, ?string $to): void
    {
        Record::create([
            'user_id' => $this->employee->user_id,
            'reason_premise_id' => $this->reasonPremiseId,
            'leave_time' => $from,
            'return_time' => $to,
        ]);
    }

    public function test_cuenta_el_dia_la_semana_y_el_mes_del_calendario(): void
    {
        // Miércoles 15 de octubre de 2025, 14:00.
        $this->travelTo(now()->setDate(2025, 10, 15)->setTime(14, 0));

        $this->leave('2025-10-15 09:00:00', '2025-10-15 09:30:00'); // hoy: 30 min
        $this->leave('2025-10-13 10:00:00', '2025-10-13 11:00:00'); // lunes de esta semana: 60 min
        $this->leave('2025-10-02 08:00:00', '2025-10-02 08:10:00'); // este mes, otra semana: 10 min
        $this->leave('2025-09-30 08:00:00', '2025-09-30 09:00:00'); // mes anterior: no cuenta
        $this->leave('2025-10-12 08:00:00', '2025-10-12 09:00:00'); // domingo anterior: semana pasada

        $data = $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson('/api/me/leave-stats')->assertOk()->json('data');

        $this->assertSame([1, 30], [$data['day']['exits'], $data['day']['minutes']]);
        $this->assertSame([2, 90], [$data['week']['exits'], $data['week']['minutes']]);
        $this->assertSame([4, 160], [$data['month']['exits'], $data['month']['minutes']]);
        $this->assertSame('2025-10-13', $data['week']['from']);
        $this->assertSame('2025-10-01', $data['month']['from']);
    }

    public function test_una_salida_sin_retorno_cuenta_hasta_ahora(): void
    {
        $this->travelTo(now()->setDate(2025, 10, 15)->setTime(14, 0));
        $this->leave('2025-10-15 13:00:00', null);

        $data = $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson('/api/me/leave-stats')->json('data');

        $this->assertSame(60, $data['day']['minutes']);
    }

    public function test_solo_cuenta_las_salidas_propias(): void
    {
        $this->travelTo(now()->setDate(2025, 10, 15)->setTime(14, 0));
        $other = User::create(['external_identifier' => 'e2', 'name' => 'Otro', 'item' => 5, 'role_id' => $this->employee->role_id]);
        Record::create(['user_id' => $other->user_id, 'reason_premise_id' => $this->reasonPremiseId,
            'leave_time' => '2025-10-15 09:00:00', 'return_time' => '2025-10-15 10:00:00']);

        $data = $this->signIn($this->employee, ClientPlatform::MOBILE)
            ->getJson('/api/me/leave-stats')->json('data');

        $this->assertSame(0, $data['month']['exits']);
    }

    public function test_la_web_no_usa_la_ruta_de_estadisticas_movil(): void
    {
        $this->signIn($this->employee, ClientPlatform::WEB)->getJson('/api/me/leave-stats')->assertStatus(403);
    }

    public function test_me_incluye_foto_y_cargo_del_servicio_de_terceros(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 0, 'photo' => 'https://rh.example/foto.jpg', 'rolName' => 'Funcionario', 'area' => 'TI',
        ])]);

        $this->signIn($this->employee, ClientPlatform::MOBILE)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.photo_url', 'https://rh.example/foto.jpg')
            ->assertJsonPath('user.job_title', 'Funcionario')
            ->assertJsonPath('user.area', 'TI')
            ->assertJsonPath('user.role.name', 'EMPLOYEE');
    }

    public function test_si_el_servicio_de_terceros_falla_no_hay_foto_pero_todo_funciona(): void
    {
        Http::fake(['*' => Http::response('caído', 503)]);

        $this->signIn($this->employee, ClientPlatform::MOBILE)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.photo_url', null)
            ->assertJsonPath('user.job_title', null);
    }
    public function test_solo_se_aceptan_fotos_con_url_web(): void
    {
        Http::fake(['*' => Http::response(['status' => 0, 'photo' => 'javascript:alert(1)', 'rolName' => 'X'])]);

        $this->signIn($this->employee, ClientPlatform::MOBILE)->getJson('/api/auth/me')
            ->assertJsonPath('user.photo_url', null);
    }
}
