<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Premise;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditLogger;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Bitácora de cambios administrativos: quién, a quién, qué cambió; nunca contraseñas. */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private Role $employeeRole;

    private Role $adminRole;

    private Role $managerRole;

    private User $admin;

    private Premise $prado;

    private Premise $norte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employeeRole = Role::create(['name' => Role::EMPLOYEE]);
        $this->adminRole = Role::create(['name' => Role::ADMIN]);
        $this->managerRole = Role::where('name', Role::MANAGE_PREMISE)->firstOrFail();
        $this->admin = User::create(['external_identifier' => 'ad', 'name' => 'Ana Admin', 'item' => 1, 'role_id' => $this->adminRole->role_id]);
        $this->prado = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        $this->norte = Premise::create(['name' => 'Norte', 'latitude' => -16.4, 'longitude' => -68.2]);
    }

    private function asAdmin(): static
    {
        return $this->signIn($this->admin, ClientPlatform::WEB);
    }

    private function manager(string $username, ?Premise $premise = null): User
    {
        return User::create([
            'external_identifier' => "premise-manager:$username", 'name' => "Gestor $username",
            'item' => random_int(10_000, 99_999), 'role_id' => $this->managerRole->role_id,
            'username' => $username, 'password' => bcrypt('clave-vieja-123'), 'premise_id' => $premise?->premise_id,
        ]);
    }

    private function employee(): User
    {
        return User::create(['external_identifier' => 'emp', 'name' => 'Juan', 'item' => 2, 'role_id' => $this->employeeRole->role_id]);
    }

    private function last(string $event): AuditLog
    {
        return AuditLog::where('event', $event)->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ roles

    public function test_el_cambio_de_rol_queda_registrado(): void
    {
        $employee = $this->employee();

        $this->asAdmin()->putJson("/api/admin/users/{$employee->user_id}/role", ['role_id' => $this->adminRole->role_id])->assertOk();

        $entry = $this->last('role.changed');
        $this->assertSame($this->admin->user_id, $entry->actor_user_id);
        $this->assertSame('Ana Admin', $entry->actor_name);
        $this->assertSame('user', $entry->subject_type);
        $this->assertSame($employee->user_id, $entry->subject_id);
        $this->assertSame('Juan', $entry->subject_name);
        $this->assertSame('EMPLOYEE', $entry->details['from']);
        $this->assertSame('ADMIN', $entry->details['to']);
        $this->assertNotNull($entry->created_at);
    }

    public function test_hacer_responsable_a_alguien_anota_el_predio(): void
    {
        $employee = $this->employee();

        $this->asAdmin()->putJson("/api/admin/users/{$employee->user_id}/role", [
            'role_id' => $this->managerRole->role_id, 'premise_id' => $this->prado->premise_id,
        ])->assertOk();

        $details = $this->last('role.changed')->details;
        $this->assertSame('MANAGE_PREMISE', $details['to']);
        $this->assertSame('Prado', $details['premise_to']);
        $this->assertNull($details['premise_from']);
    }

    public function test_un_cambio_rechazado_no_deja_entrada(): void
    {
        // Un admin no puede cambiarse su propio rol.
        $this->asAdmin()->putJson("/api/admin/users/{$this->admin->user_id}/role", ['role_id' => $this->employeeRole->role_id])->assertStatus(422);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // ------------------------------------------------------------ responsables

    public function test_crear_un_responsable_queda_registrado_sin_la_contrasena(): void
    {
        $response = $this->asAdmin()->postJson('/api/admin/users/premise-managers', [
            'name' => 'Marta', 'username' => 'marta', 'premise_id' => $this->prado->premise_id,
        ])->assertCreated();

        $generated = $response->json('generated_password');
        $this->assertNotEmpty($generated);
        $entry = $this->last('manager.created');
        $this->assertSame($this->admin->user_id, $entry->actor_user_id);
        $this->assertSame('Marta', $entry->subject_name);
        $this->assertSame('marta', $entry->details['username']);
        $this->assertSame('Prado', $entry->details['premise']);
        $this->assertTrue($entry->details['password_generated']);
        $this->assertStringNotContainsString($generated, json_encode($entry->toArray()));
    }

    public function test_mover_un_responsable_de_predio_queda_registrado(): void
    {
        $manager = $this->manager('marta', $this->prado);

        $this->asAdmin()->putJson("/api/admin/users/{$manager->user_id}/premise", ['premise_id' => $this->norte->premise_id])->assertOk();

        $entry = $this->last('manager.premise_changed');
        $this->assertSame($manager->user_id, $entry->subject_id);
        $this->assertSame('Prado', $entry->details['premise_from']);
        $this->assertSame('Norte', $entry->details['premise_to']);
    }

    public function test_cambiar_el_responsable_desde_el_predio_anota_al_que_sale_y_al_que_entra(): void
    {
        $saliente = $this->manager('vieja', $this->prado);
        $entrante = $this->manager('nuevo', $this->norte);
        // El entrante deja de serlo de Norte para pasar a Prado.
        $this->asAdmin()->putJson("/api/admin/premises/{$this->prado->premise_id}", ['manager_user_id' => $entrante->user_id])->assertOk();

        $entries = AuditLog::where('event', 'manager.premise_changed')->get()->keyBy('subject_id');
        $this->assertCount(2, $entries);
        $this->assertSame(['premise_from' => 'Prado', 'premise_to' => null], $entries[$saliente->user_id]->details);
        $this->assertSame(['premise_from' => 'Norte', 'premise_to' => 'Prado'], $entries[$entrante->user_id]->details);
        $this->assertSame($this->admin->user_id, $entries[$entrante->user_id]->actor_user_id);
    }

    public function test_crear_un_predio_con_responsable_lo_registra(): void
    {
        $manager = $this->manager('marta');

        $this->asAdmin()->postJson('/api/admin/premises', [
            'name' => 'Sur', 'latitude' => -17.4, 'longitude' => -66.2, 'manager_user_id' => $manager->user_id,
        ])->assertCreated();

        $details = $this->last('manager.premise_changed')->details;
        $this->assertNull($details['premise_from']);
        $this->assertSame('Sur', $details['premise_to']);
    }

    // ------------------------------------------------------------- contraseñas

    public function test_cambiar_la_contrasena_se_registra_sin_guardarla(): void
    {
        $manager = $this->manager('marta', $this->prado);

        $this->asAdmin()->putJson("/api/admin/users/{$manager->user_id}/password", ['password' => 'clave-escrita-a-mano-123'])->assertOk();

        $entry = $this->last('manager.password_changed');
        $this->assertSame($manager->user_id, $entry->subject_id);
        $this->assertFalse($entry->details['generated']);
        $this->assertStringNotContainsString('clave-escrita-a-mano-123', json_encode($entry->toArray()));
    }

    public function test_regenerar_la_contrasena_se_registra_como_generada(): void
    {
        $manager = $this->manager('marta', $this->prado);

        $response = $this->asAdmin()->putJson("/api/admin/users/{$manager->user_id}/password", [])->assertOk();

        $entry = $this->last('manager.password_changed');
        $this->assertTrue($entry->details['generated']);
        $this->assertStringNotContainsString($response->json('generated_password'), json_encode($entry->toArray()));
    }

    // ------------------------------------------------------------- dispositivos

    public function test_desvincular_un_dispositivo_desde_el_panel_queda_registrado(): void
    {
        $employee = $this->employee();
        UserDevice::create(['user_id' => $employee->user_id, 'platform' => 'mobile', 'device_hash' => str_repeat('a', 64), 'bound_at' => now()]);

        $this->asAdmin()->postJson("/api/admin/users/{$employee->user_id}/device/reset", ['platform' => 'mobile'])->assertOk();

        $entry = $this->last('device.reset');
        $this->assertSame($this->admin->user_id, $entry->actor_user_id);
        $this->assertSame($employee->user_id, $entry->subject_id);
        $this->assertSame(['platform' => 'mobile', 'had_device' => true, 'via' => 'panel'], $entry->details);
    }

    public function test_desvincular_desde_la_consola_queda_sin_actor(): void
    {
        $employee = $this->employee();
        UserDevice::create(['user_id' => $employee->user_id, 'platform' => 'mobile', 'device_hash' => str_repeat('a', 64), 'bound_at' => now()]);

        $this->artisan('devices:reset', ['user' => (string) $employee->item, '--platform' => 'mobile'])->assertSuccessful();

        $entry = $this->last('device.reset');
        $this->assertNull($entry->actor_user_id);
        $this->assertSame('console', $entry->details['via']);
        $this->assertNull($entry->ip_address);
    }

    // ----------------------------------------------------- la bitácora en sí

    public function test_guarda_la_ip_de_quien_hizo_el_cambio(): void
    {
        $employee = $this->employee();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])->asAdmin()
            ->putJson("/api/admin/users/{$employee->user_id}/role", ['role_id' => $this->adminRole->role_id])->assertOk();

        $this->assertSame('198.51.100.4', $this->last('role.changed')->ip_address);
    }

    public function test_una_entrada_no_se_puede_modificar_ni_borrar(): void
    {
        app(AuditLogger::class)->record(AuditLogger::ROLE_CHANGED, $this->admin);
        $entry = AuditLog::firstOrFail();

        foreach ([
            fn () => $entry->update(['event' => 'otro']),
            fn () => $entry->delete(),
        ] as $change) {
            try {
                $change();
                $this->fail('La bitácora debía rechazar el cambio.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('role.changed', $entry->fresh()->event);
    }

    public function test_nunca_guarda_claves_ni_carnets_aunque_se_los_pasen(): void
    {
        app(AuditLogger::class)->record('prueba', $this->admin, null, [
            'password' => 'secreta', 'Carnet' => '1234567', 'documento' => '7654321', 'token' => 'abc',
            'anidado' => ['clave' => 'otra', 'ok' => 'visible'],
            'visible' => 'si',
        ]);

        $details = AuditLog::firstOrFail()->details;
        $this->assertSame(['anidado' => ['ok' => 'visible'], 'visible' => 'si'], $details);
    }

    public function test_si_la_bitacora_falla_la_operacion_continua(): void
    {
        Schema::drop('audit_logs');
        $employee = $this->employee();

        $this->asAdmin()->putJson("/api/admin/users/{$employee->user_id}/role", ['role_id' => $this->adminRole->role_id])->assertOk();

        $this->assertSame($this->adminRole->role_id, $employee->fresh()->role_id);
    }
}
