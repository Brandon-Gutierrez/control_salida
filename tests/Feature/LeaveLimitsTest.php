<?php

namespace Tests\Feature;

use App\Models\Premise;
use App\Models\LeaveReason;
use App\Models\Record;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Leave\LeaveLimitService;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Los límites de salidas (por persona y por motivo) son los mismos para todas las personas. */
class LeaveLimitsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private User $admin;
    private User $employee;
    private int $premiseId;
    private LeaveReason $lunch;
    private LeaveReason $medical;
    private int $lunchPremiseId;
    private int $medicalPremiseId;

    protected function setUp(): void
    {
        parent::setUp();
        $emp = Role::create(['name' => Role::EMPLOYEE]);
        $adm = Role::create(['name' => Role::ADMIN]);
        $this->admin = User::create(['external_identifier' => 'a', 'name' => 'Ana', 'item' => 1, 'role_id' => $adm->role_id]);
        $this->employee = User::create(['external_identifier' => 'e', 'name' => 'Juan', 'item' => 2, 'role_id' => $emp->role_id]);
        $premise = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        $this->premiseId = $premise->premise_id;

        $this->lunch = LeaveReason::create(['name' => 'Almuerzo', 'code' => 'A']);
        $this->medical = LeaveReason::create(['name' => 'Médico', 'code' => 'M']);
        $this->lunchPremiseId = DB::table('reason_premise')->insertGetId([
            'reason_id' => $this->lunch->reason_id, 'premise_id' => $premise->premise_id,
        ]);
        $this->medicalPremiseId = DB::table('reason_premise')->insertGetId([
            'reason_id' => $this->medical->reason_id, 'premise_id' => $premise->premise_id,
        ]);
    }

    private function leave(User $user, int $reasonPremiseId, int $times = 1, ?\Carbon\Carbon $day = null): void
    {
        $day ??= now();
        for ($i = 0; $i < $times; $i++) {
            $at = $day->copy()->startOfDay()->addHours($i + 1);
            Record::create(['user_id' => $user->user_id, 'reason_premise_id' => $reasonPremiseId,
                'leave_time' => $at, 'return_time' => $at->copy()->addMinutes(5)]);
        }
    }

    private function limits(): LeaveLimitService
    {
        return app(LeaveLimitService::class);
    }

    private function blockedNames(?User $user = null): array
    {
        return array_column($this->limits()->blockedReasons($user ?? $this->employee, ['Almuerzo', 'Médico']), 'name');
    }

    // ------------------------------------------------------ límite por persona

    public function test_el_admin_define_los_limites(): void
    {
        $this->signIn($this->admin, ClientPlatform::WEB)
            ->putJson('/api/admin/settings/leave-limits', [
                'period' => 'week',
                'max_exits' => 5,
                'reason_limit' => [
                    'period' => 'month',
                    'mode' => 'custom',
                    'general' => null,
                    'custom' => [
                        ['reason_id' => $this->lunch->reason_id, 'max' => 1],
                        ['reason_id' => $this->medical->reason_id, 'max' => null],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.period', 'week')
            ->assertJsonPath('data.max_exits', 5)
            ->assertJsonPath('data.reason_limit.period', 'month')
            ->assertJsonPath('data.reason_limit.mode', 'custom')
            ->assertJsonPath('data.reason_limit.custom.0.name', 'Almuerzo')
            ->assertJsonPath('data.reason_limit.custom.0.max', 1)
            ->assertJsonPath('data.reason_limit.custom.1.name', 'Médico')
            ->assertJsonPath('data.reason_limit.custom.1.max', null);
    }

    public function test_el_limite_por_persona_aplica_a_todas_las_personas(): void
    {
        $this->limits()->savePolicy('day', 2);
        $other = User::create(['external_identifier' => 'o', 'name' => 'Otra', 'item' => 3, 'role_id' => $this->employee->role_id]);
        $this->leave($this->employee, $this->lunchPremiseId, 2);
        $this->leave($other, $this->lunchPremiseId, 1);

        $this->assertSame('total', $this->limits()->check($this->employee)['limit_type']);
        $this->assertNull($this->limits()->check($other));
    }

    public function test_sin_limite_configurado_no_se_restringe_a_nadie(): void
    {
        $this->leave($this->employee, $this->lunchPremiseId, 5);

        $this->assertNull($this->limits()->check($this->employee));
        $this->assertSame([], $this->limits()->blockedReasons($this->employee, ['Almuerzo', 'Médico']));
    }

    // -------------------------------------------------------- límite por motivo

    public function test_limite_general_aplica_a_cada_motivo_por_separado(): void
    {
        $this->limits()->savePolicy('day', null, 'day', 'general', 1);
        $this->leave($this->employee, $this->lunchPremiseId, 1);

        $blocked = $this->limits()->blockedReasons($this->employee, ['Almuerzo', 'Médico']);

        // Solo el motivo ya usado llega al tope; el otro sigue disponible.
        $this->assertSame(['Almuerzo'], array_column($blocked, 'name'));
        $this->assertSame(1, $blocked[0]['limit']);
        $this->assertSame(1, $blocked[0]['used']);
        $this->assertSame('day', $blocked[0]['period']);
    }

    public function test_limite_general_permite_varias_veces_hasta_el_tope(): void
    {
        $this->limits()->savePolicy('day', null, 'day', 'general', 2);
        $this->leave($this->employee, $this->lunchPremiseId, 1);
        $this->assertSame([], $this->blockedNames());

        $this->leave($this->employee, $this->lunchPremiseId, 2);
        $this->assertSame(['Almuerzo'], $this->blockedNames());
    }

    public function test_limite_personalizado_solo_afecta_a_los_motivos_con_tope(): void
    {
        // Una salida de almuerzo al día; médico sin tope.
        $this->limits()->savePolicy('day', null, 'day', 'custom', null, [
            $this->lunch->reason_id => 1,
            $this->medical->reason_id => null,
        ]);
        $this->leave($this->employee, $this->lunchPremiseId, 1);
        $this->leave($this->employee, $this->medicalPremiseId, 4);

        $this->assertSame(['Almuerzo'], $this->blockedNames());
    }

    public function test_limite_personalizado_ignora_el_tope_general(): void
    {
        $this->limits()->savePolicy('day', null, 'day', 'custom', 1, [$this->medical->reason_id => 3]);
        $this->leave($this->employee, $this->lunchPremiseId, 5);

        $this->assertSame([], $this->blockedNames());
    }

    public function test_el_limite_por_motivo_es_por_persona(): void
    {
        $this->limits()->savePolicy('day', null, 'day', 'general', 1);
        $other = User::create(['external_identifier' => 'o', 'name' => 'Otra', 'item' => 3, 'role_id' => $this->employee->role_id]);
        $this->leave($this->employee, $this->lunchPremiseId, 1);

        $this->assertSame(['Almuerzo'], $this->blockedNames($this->employee));
        $this->assertSame([], $this->blockedNames($other));
    }

    public function test_el_motivo_cuenta_aunque_se_haya_usado_en_otro_predio(): void
    {
        $this->limits()->savePolicy('day', null, 'day', 'general', 1);
        $other = Premise::create(['name' => 'Norte', 'latitude' => -17.4, 'longitude' => -66.2]);
        $inOther = DB::table('reason_premise')->insertGetId([
            'reason_id' => $this->lunch->reason_id, 'premise_id' => $other->premise_id,
        ]);
        $this->leave($this->employee, $inOther, 1);

        $this->assertSame(['Almuerzo'], $this->blockedNames());
    }

    public function test_el_periodo_del_limite_por_motivo_es_independiente_del_de_la_persona(): void
    {
        // Persona: por día. Motivo: por semana.
        $this->limits()->savePolicy('day', 10, 'week', 'general', 1);
        $this->leave($this->employee, $this->lunchPremiseId, 1, now()->startOfWeek()->subDays(7));
        $this->assertSame([], $this->blockedNames(), 'una salida de la semana pasada no cuenta');

        $this->leave($this->employee, $this->lunchPremiseId, 1, now()->startOfWeek());
        $this->assertSame(['Almuerzo'], $this->blockedNames());
    }

    public function test_el_conteo_de_un_motivo_se_reinicia_con_el_periodo(): void
    {
        $this->limits()->savePolicy('day', null, 'day', 'general', 1);
        $this->leave($this->employee, $this->lunchPremiseId, 1, now()->subDay());

        $this->assertSame([], $this->blockedNames(), 'ayer no cuenta para el período diario');
    }

    public function test_cuenta_las_salidas_sin_retorno_del_periodo(): void
    {
        $this->limits()->savePolicy('day', null, 'day', 'general', 1);
        Record::create(['user_id' => $this->employee->user_id, 'reason_premise_id' => $this->lunchPremiseId,
            'leave_time' => now()->startOfDay()->addHour(), 'return_time' => null]);

        $this->assertSame(['Almuerzo'], $this->blockedNames());
    }

    public function test_modo_general_sin_tope_no_restringe(): void
    {
        $this->limits()->savePolicy('day', null, 'day', 'general', null);
        $this->leave($this->employee, $this->lunchPremiseId, 6);

        $this->assertSame([], $this->blockedNames());
    }

    public function test_los_topes_viejos_o_corruptos_no_rompen_la_configuracion(): void
    {
        Setting::set('reason_limit_custom', 'esto no es json');
        Setting::set('reason_limit_mode', 'raro');
        Setting::set('reason_limit_period', 'year');

        $reasonLimit = $this->limits()->policy()['reason_limit'];

        $this->assertSame('general', $reasonLimit['mode']);
        $this->assertSame('day', $reasonLimit['period']);
        $this->assertSame([null, null], array_column($reasonLimit['custom'], 'max'));
    }

    // ---------------------------------------------------------------- endpoint

    public function test_la_consulta_lista_todos_los_motivos_con_su_tope(): void
    {
        $this->limits()->savePolicy('day', null, 'day', 'custom', null, [$this->medical->reason_id => 2]);

        $this->signIn($this->admin, ClientPlatform::WEB)
            ->getJson('/api/admin/settings/leave-limits')
            ->assertOk()
            ->assertJsonPath('data.reason_limit.mode', 'custom')
            ->assertJsonPath('data.reason_limit.custom.0.name', 'Almuerzo')
            ->assertJsonPath('data.reason_limit.custom.0.max', null)
            ->assertJsonPath('data.reason_limit.custom.1.name', 'Médico')
            ->assertJsonPath('data.reason_limit.custom.1.max', 2);
    }

    public function test_valida_los_datos(): void
    {
        $valid = [
            'period' => 'day',
            'max_exits' => null,
            'reason_limit' => ['period' => 'day', 'mode' => 'general', 'general' => null, 'custom' => []],
        ];
        $this->signIn($this->admin, ClientPlatform::WEB);

        $this->putJson('/api/admin/settings/leave-limits', [...$valid, 'period' => 'year', 'max_exits' => 0])
            ->assertStatus(422);

        foreach ([
            ['period' => 'year'],
            ['mode' => 'todos'],
            ['general' => 0],
            ['custom' => [['reason_id' => 9999, 'max' => 1]]],
            ['custom' => [['reason_id' => $this->lunch->reason_id, 'max' => 0]]],
            ['custom' => [
                ['reason_id' => $this->lunch->reason_id, 'max' => 1],
                ['reason_id' => $this->lunch->reason_id, 'max' => 2],
            ]],
        ] as $change) {
            $this->putJson('/api/admin/settings/leave-limits', [
                ...$valid, 'reason_limit' => [...$valid['reason_limit'], ...$change],
            ])->assertStatus(422);
        }

        $this->putJson('/api/admin/settings/leave-limits', ['period' => 'day', 'max_exits' => null])
            ->assertStatus(422);
    }

    public function test_un_empleado_no_puede_ver_ni_cambiar_el_limite(): void
    {
        $this->signIn($this->employee, ClientPlatform::MOBILE)->getJson('/api/admin/settings/leave-limits')->assertStatus(403);
    }
}
