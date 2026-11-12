<?php

namespace Tests\Feature;

use App\Models\LeaveReason;
use App\Models\Premise;
use App\Models\ReasonPremise;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Repositories\PremiseRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** El historial de salidas no se pierde por borrados ni por editar los motivos de un predio. */
class HistoryProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private Premise $premise;
    private LeaveReason $lunch;
    private LeaveReason $medical;
    private Record $record;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => Role::EMPLOYEE]);
        $this->employee = User::create(['external_identifier' => 'e', 'name' => 'Juan', 'item' => 2, 'role_id' => $role->role_id]);
        $this->premise = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        $this->lunch = LeaveReason::create(['name' => 'Almuerzo', 'code' => 'A']);
        $this->medical = LeaveReason::create(['name' => 'Médico', 'code' => 'M']);

        app(PremiseRepository::class)->syncReasonsByName($this->premise, ['Almuerzo', 'Médico']);

        $relationId = ReasonPremise::where('reason_id', $this->lunch->reason_id)->value('id');
        $this->record = Record::create([
            'user_id' => $this->employee->user_id, 'reason_premise_id' => $relationId,
            'leave_time' => now()->subHour(), 'return_time' => now(),
        ]);
    }

    private function sync(array $names): void
    {
        app(PremiseRepository::class)->syncReasonsByName($this->premise, $names);
    }

    private function names(): array
    {
        return $this->premise->fresh()->reasons()->orderBy('name')->pluck('name')->all();
    }

    // ------------------------------------------------ motivos de un predio

    public function test_retirar_un_motivo_con_salidas_no_borra_la_relacion_ni_el_historial(): void
    {
        $relationId = $this->record->reason_premise_id;

        $this->sync(['Médico']);

        $this->assertSame(['Médico'], $this->names());
        $this->assertDatabaseCount('records', 1);
        // La fila sigue existiendo, marcada como retirada.
        $this->assertSoftDeleted('reason_premise', ['id' => $relationId]);
        $this->assertSame($relationId, (int) $this->record->fresh()->reason_premise_id);
    }

    public function test_el_historial_sigue_mostrando_el_motivo_retirado(): void
    {
        $this->sync(['Médico']);

        $record = Record::with('reasonPremise.reason')->find($this->record->record_id);

        $this->assertSame('Almuerzo', $record->reasonPremise->reason->name);
    }

    public function test_volver_a_habilitar_el_motivo_restaura_la_misma_fila(): void
    {
        $relationId = $this->record->reason_premise_id;
        $this->sync(['Médico']);

        $this->sync(['Almuerzo', 'Médico']);

        $this->assertSame(['Almuerzo', 'Médico'], $this->names());
        $this->assertSame(
            1,
            ReasonPremise::withTrashed()->where('premise_id', $this->premise->premise_id)
                ->where('reason_id', $this->lunch->reason_id)->count(),
            'no debe duplicar la relación',
        );
        $this->assertNull(ReasonPremise::find($relationId)->deleted_at);
    }

    public function test_el_motivo_retirado_no_se_ofrece_pero_el_activo_si(): void
    {
        $this->sync(['Médico']);

        $repo = app(\App\Repositories\ReasonPremiseRepository::class);
        $this->assertSame(['Médico'], $repo->reasonNamesForPremise($this->premise->premise_id)->all());
        $this->assertNull($repo->findId($this->premise->premise_id, $this->lunch->reason_id));
        $this->assertNotNull($repo->findId($this->premise->premise_id, $this->medical->reason_id));
    }

    public function test_un_motivo_desactivado_se_oculta_de_las_listas_pero_no_del_historial(): void
    {
        $this->lunch->delete();

        $this->assertNotContains('Almuerzo', $this->names());
        $this->assertNotContains('Almuerzo', app(\App\Repositories\LeaveReasonRepository::class)->allNames()->all());
        $record = Record::with('reasonPremise.reason')->find($this->record->record_id);
        $this->assertSame('Almuerzo', $record->reasonPremise->reason->name);
    }

    public function test_cada_motivo_se_habilita_una_sola_vez_por_predio(): void
    {
        $this->expectException(QueryException::class);

        DB::table('reason_premise')->insert([
            'reason_id' => $this->lunch->reason_id, 'premise_id' => $this->premise->premise_id,
        ]);
    }

    // ------------------------------------------------- borrados prohibidos

    public function test_una_salida_no_se_puede_borrar(): void
    {
        $this->expectException(LogicException::class);

        $this->record->delete();
    }

    public function test_una_relacion_motivo_predio_no_se_puede_borrar_de_verdad(): void
    {
        $this->expectException(LogicException::class);

        ReasonPremise::find($this->record->reason_premise_id)->forceDelete();
    }

    public function test_no_se_puede_borrar_con_sql_nada_que_tenga_historial(): void
    {
        foreach ([
            fn () => DB::table('users')->where('user_id', $this->employee->user_id)->delete(),
            fn () => DB::table('reason_premise')->where('id', $this->record->reason_premise_id)->delete(),
            fn () => DB::table('reasons')->where('reason_id', $this->lunch->reason_id)->delete(),
            fn () => DB::table('premises')->where('premise_id', $this->premise->premise_id)->delete(),
            fn () => DB::table('roles')->where('role_id', $this->employee->role_id)->delete(),
        ] as $delete) {
            try {
                DB::transaction($delete);
                $this->fail('El borrado debía fallar por la clave foránea.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_borrar_logicamente_un_usuario_conserva_su_historial(): void
    {
        $this->employee->delete();

        $this->assertSoftDeleted('users', ['user_id' => $this->employee->user_id]);
        $this->assertDatabaseCount('records', 1);
        $this->assertSame('Juan', Record::find($this->record->record_id)->user->name);
    }

    public function test_borrar_logicamente_un_predio_conserva_su_historial(): void
    {
        $this->premise->delete();

        $this->assertSoftDeleted('premises', ['premise_id' => $this->premise->premise_id]);
        $relation = ReasonPremise::find($this->record->reason_premise_id);
        $this->assertSame('Prado', $relation->premise->name);
    }
}
