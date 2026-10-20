<?php

namespace Tests\Feature;

use App\Models\LeaveReason;
use App\Models\Premise;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Ingreso manual (item + carnet) desde la pantalla del responsable. Todo el servicio externo se simula. */
class ManualEntryTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private User $manager;

    private Premise $premise;

    private int $reasonPremiseId;

    /** @var array<string, callable> */
    private array $external = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.external_api' => [
            'key' => 'k',
            'login_url' => 'https://ext.test/login',
            'login_carnet_url' => 'https://ext.test/login-carnet',
            'employee_url' => 'https://ext.test/employee',
            'reasons_url' => 'https://ext.test/reasons',
            'checkout_url' => 'https://ext.test/checkout',
            'register_checkout_url' => 'https://ext.test/register',
        ]]);

        $employee = Role::create(['name' => Role::EMPLOYEE]);
        $managerRole = Role::create(['name' => Role::MANAGE_PREMISE]);
        $this->premise = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        $this->manager = User::create([
            'external_identifier' => 'm', 'name' => 'Marta', 'item' => 9, 'role_id' => $managerRole->role_id,
            'username' => 'marta', 'password' => bcrypt('x'), 'premise_id' => $this->premise->premise_id,
        ]);
        $reason = LeaveReason::create(['name' => 'Trámite', 'code' => 'T']);
        $this->reasonPremiseId = DB::table('reason_premise')->insertGetId([
            'reason_id' => $reason->reason_id, 'premise_id' => $this->premise->premise_id,
        ]);
        unset($employee);

        $this->fakeExternal();
    }

    private function fakeExternal(array $overrides = []): void
    {
        $this->external = array_merge([
            'carnet' => fn () => Http::response(['error' => 0, 'message' => 'OK', 'data' => ['item' => '2057', 'documento' => '1234567', 'nombre' => 'Juan Pérez']]),
            'employee' => fn () => Http::response(['status' => 0, 'name' => 'Juan Pérez', 'item' => 2057]),
            'checkout' => fn () => Http::response(['error' => -1, 'data' => []]),
            'register' => fn () => Http::response(['status' => 0, 'fecha_registro' => '2026-03-02 08:15:30']),
        ], $overrides);

        $routes = [
            'carnet' => 'https://ext.test/login-carnet',
            'employee' => 'https://ext.test/employee',
            'checkout' => 'https://ext.test/checkout',
            'register' => 'https://ext.test/register',
        ];

        Http::fake(function (HttpRequest $request) use ($routes) {
            foreach ($routes as $key => $url) {
                if (str_starts_with($request->url(), $url)) {
                    return ($this->external[$key])($request);
                }
            }

            return Http::response(['status' => 1]);
        });
    }

    private function asManager()
    {
        return $this->signIn($this->manager, ClientPlatform::WEB);
    }

    private function validateEntry(array $body = [])
    {
        return $this->asManager()->postJson('/api/manager/manual-entry/validate', $body + ['item' => '2057', 'carnet' => '1234567']);
    }

    public function test_valida_item_y_carnet_y_entrega_los_motivos_del_predio(): void
    {
        Redis::shouldReceive('setex')->once();
        Redis::shouldReceive('ttl')->andReturn(40);

        $this->validateEntry()->assertOk()
            ->assertJsonPath('action', 'showReasons')
            ->assertJsonPath('person', 'Juan Pérez')
            ->assertJsonPath('premise', 'Prado')
            ->assertJsonPath('reasons', ['Trámite'])
            ->assertJsonStructure(['leaveTicket', 'leaveTicketTTL']);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://ext.test/login-carnet'
            && $r['in_item'] === '2057' && $r['in_documento'] === '1234567');
        // La persona se crea localmente con lo que devolvió el sistema externo.
        $this->assertDatabaseHas('users', ['item' => 2057, 'name' => 'Juan Pérez', 'external_identifier' => 'manual-2057']);
    }

    public function test_item_o_carnet_incorrectos_se_rechazan(): void
    {
        $this->fakeExternal(['carnet' => fn () => Http::response(['error' => 1, 'message' => 'Verifique Nro ITEM y/o Nro Documento'])]);

        $this->validateEntry()->assertStatus(422)->assertJsonPath('code', 'MANUAL_ENTRY_INVALID');
        $this->assertDatabaseMissing('users', ['item' => 2057]);
    }

    public function test_quien_entro_manualmente_puede_luego_iniciar_sesion_sin_duplicarse(): void
    {
        $role = Role::firstWhere('name', Role::EMPLOYEE);
        $manual = User::create(['external_identifier' => 'manual-2057', 'name' => 'Juan Pérez', 'item' => 2057, 'role_id' => $role->role_id]);

        $user = app(UserRepository::class)->upsertFromExternal('tok-real', 'Juan Pérez', '2057');

        $this->assertSame($manual->user_id, $user->user_id);
        $this->assertSame('tok-real', $user->fresh()->external_identifier);
        $this->assertSame(1, User::where('item', 2057)->count());
    }

    public function test_servicio_externo_caido_devuelve_error_temporal(): void
    {
        $this->fakeExternal(['carnet' => fn () => throw new ConnectionException('x')]);

        $this->validateEntry()->assertStatus(502)->assertJsonPath('code', 'MANUAL_ENTRY_SERVICE_UNAVAILABLE');
    }

    public function test_si_ya_esta_fuera_registra_su_retorno(): void
    {
        $role = Role::firstWhere('name', Role::EMPLOYEE);
        $person = User::create(['external_identifier' => 'tok-2057', 'name' => 'Juan Pérez', 'item' => 2057, 'role_id' => $role->role_id]);
        $record = Record::create([
            'user_id' => $person->user_id, 'reason_premise_id' => $this->reasonPremiseId,
            'leave_time' => now()->subHour(), 'return_time' => null,
        ]);
        $this->fakeExternal(['checkout' => fn () => Http::response([
            'error' => 0, 'data' => [['id_solicitud' => 77]],
        ])]);

        $this->validateEntry()->assertOk()->assertJsonPath('action', 'showHome');

        $this->assertSame('2026-03-02 08:15:30', $record->fresh()->return_time);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://ext.test/register'
            && $r['in_id_solicitud'] == 77 && $r['in_predio'] === 'PRADO');
    }

    public function test_confirmar_registra_la_salida_con_el_motivo_elegido(): void
    {
        $role = Role::firstWhere('name', Role::EMPLOYEE);
        $person = User::create(['external_identifier' => 'tok-2057', 'name' => 'Juan Pérez', 'item' => 2057, 'role_id' => $role->role_id]);
        Redis::shouldReceive('get')->with('leave-ticket:tk-1')->andReturn(json_encode([
            'user_id' => $person->user_id, 'premise_id' => $this->premise->premise_id,
        ]));
        Redis::shouldReceive('del')->once()->with('leave-ticket:tk-1');

        $this->asManager()->postJson('/api/manager/manual-entry/confirm', [
            'item' => '2057', 'leaveTicket' => 'tk-1', 'reason' => 'Trámite',
        ])->assertOk()->assertJsonPath('status', 0);

        $this->assertDatabaseHas('records', ['user_id' => $person->user_id, 'return_time' => null]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://ext.test/register'
            && $r['in_motivo'] === 'T' && $r['in_predio'] === 'PRADO');
    }

    public function test_el_comprobante_de_otra_persona_no_sirve(): void
    {
        $role = Role::firstWhere('name', Role::EMPLOYEE);
        User::create(['external_identifier' => 'tok-2057', 'name' => 'Juan Pérez', 'item' => 2057, 'role_id' => $role->role_id]);
        $other = User::create(['external_identifier' => 'o', 'name' => 'Otro', 'item' => 5, 'role_id' => $role->role_id]);
        Redis::shouldReceive('get')->with('leave-ticket:tk-1')->andReturn(json_encode([
            'user_id' => $other->user_id, 'premise_id' => $this->premise->premise_id,
        ]));

        $this->asManager()->postJson('/api/manager/manual-entry/confirm', [
            'item' => '2057', 'leaveTicket' => 'tk-1', 'reason' => 'Trámite',
        ])->assertStatus(403);

        $this->assertDatabaseCount('records', 0);
    }

    public function test_solo_el_responsable_en_la_web_puede_usarlo(): void
    {
        $role = Role::firstWhere('name', Role::EMPLOYEE);
        $employee = User::create(['external_identifier' => 'e', 'name' => 'E', 'item' => 3, 'role_id' => $role->role_id]);

        $this->signIn($employee, ClientPlatform::MOBILE)
            ->postJson('/api/manager/manual-entry/validate', ['item' => '2057', 'carnet' => '1'])
            ->assertStatus(403);
    }

    public function test_valida_el_formato_del_item(): void
    {
        $this->validateEntry(['item' => 'abc'])->assertStatus(422);
        $this->validateEntry(['carnet' => ''])->assertStatus(422);
    }
}
