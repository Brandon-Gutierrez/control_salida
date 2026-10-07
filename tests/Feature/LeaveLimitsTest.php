<?php

namespace Tests\Feature;

use App\Models\Premise;
use App\Models\LeaveReason;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\LeaveLimitService;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** El límite de salidas es uno solo, para todas las personas. */
class LeaveLimitsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private User $admin;
    private User $employee;
    private int $premiseId;
    private int $reasonPremiseId;

    protected function setUp(): void
    {
        parent::setUp();
        $emp = Role::create(['name' => Role::EMPLOYEE]);
        $adm = Role::create(['name' => Role::ADMIN]);
        $this->admin = User::create(['external_identifier' => 'a', 'name' => 'Ana', 'item' => 1, 'role_id' => $adm->role_id]);
        $this->employee = User::create(['external_identifier' => 'e', 'name' => 'Juan', 'item' => 2, 'role_id' => $emp->role_id]);
        $premise = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        $this->premiseId = $premise->premise_id;
        $reason = LeaveReason::create(['name' => 'Trámite', 'code' => 'T']);
        $this->reasonPremiseId = DB::table('reason_premise')->insertGetId([
            'reason_id' => $reason->reason_id, 'premise_id' => $premise->premise_id,
        ]);
    }

    private function leaveToday(User $user, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            Record::create(['user_id' => $user->user_id, 'reason_premise_id' => $this->reasonPremiseId,
                'leave_time' => now()->startOfDay()->addHours($i + 1), 'return_time' => now()->startOfDay()->addHours($i + 1)->addMinutes(5)]);
        }
    }

    public function test_el_admin_define_el_limite_general(): void
    {
        $this->signIn($this->admin, ClientPlatform::WEB)
            ->putJson('/api/admin/settings/leave-limits', ['period' => 'week', 'max_exits' => 5, 'max_exits_per_premise' => null])
            ->assertOk()->assertJsonPath('data.period', 'week')->assertJsonPath('data.max_exits', 5);

        $this->assertSame(['period' => 'week', 'max_exits' => 5, 'max_exits_per_premise' => null], app(LeaveLimitService::class)->policy());
    }

    public function test_el_limite_aplica_a_todas_las_personas(): void
    {
        app(LeaveLimitService::class)->savePolicy('day', 2, null);
        $other = User::create(['external_identifier' => 'o', 'name' => 'Otra', 'item' => 3, 'role_id' => $this->employee->role_id]);
        $this->leaveToday($this->employee, 2);
        $this->leaveToday($other, 1);

        $service = app(LeaveLimitService::class);
        $this->assertSame('total', $service->check($this->employee, $this->premiseId)['limit_type']);
        $this->assertNull($service->check($other, $this->premiseId));
    }

    public function test_sin_limite_configurado_no_se_restringe_a_nadie(): void
    {
        $this->leaveToday($this->employee, 5);

        $this->assertNull(app(LeaveLimitService::class)->check($this->employee, $this->premiseId));
    }

    public function test_limite_por_predio(): void
    {
        app(LeaveLimitService::class)->savePolicy('day', null, 1);
        $this->leaveToday($this->employee, 1);

        $this->assertSame('premise', app(LeaveLimitService::class)->check($this->employee, $this->premiseId)['limit_type']);
    }

    public function test_valida_los_datos_y_solo_el_admin_puede(): void
    {
        $this->signIn($this->admin, ClientPlatform::WEB)
            ->putJson('/api/admin/settings/leave-limits', ['period' => 'year', 'max_exits' => 0, 'max_exits_per_premise' => null])
            ->assertStatus(422);
    }

    public function test_un_empleado_no_puede_ver_ni_cambiar_el_limite(): void
    {
        $this->signIn($this->employee, ClientPlatform::MOBILE)->getJson('/api/admin/settings/leave-limits')->assertStatus(403);
    }
}
