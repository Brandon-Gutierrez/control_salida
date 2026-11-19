<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\ClientPlatform;
use App\Support\DeviceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Código corto del dispositivo: lo ve la persona y lo busca administración. */
class DeviceCodeTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private const PHONE = 'telefono-android-00000000000000001';

    private Role $employeeRole;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employeeRole = Role::create(['name' => Role::EMPLOYEE]);
        $this->adminRole = Role::create(['name' => Role::ADMIN]);
    }

    private function employee(string $name, int $item): User
    {
        return User::create([
            'external_identifier' => "ext-$name", 'name' => $name, 'item' => $item, 'role_id' => $this->employeeRole->role_id,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'external_identifier' => 'ext-admin', 'name' => 'Admin', 'item' => 1, 'role_id' => $this->adminRole->role_id,
        ]);
    }

    private function bindPhone(User $user, string $deviceId = self::PHONE): void
    {
        UserDevice::create([
            'user_id' => $user->user_id, 'platform' => ClientPlatform::MOBILE,
            'device_hash' => hash('sha256', $deviceId), 'bound_at' => now(),
        ]);
    }

    public function test_el_codigo_tiene_formato_dictable_y_se_decodifica_al_mismo_prefijo(): void
    {
        $hash = hash('sha256', self::PHONE);
        $code = DeviceCode::fromHash($hash);

        $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/', $code);
        $this->assertSame(substr($hash, 0, 10), DeviceCode::hashPrefix($code));
        // Tolerante: minúsculas y sin guion.
        $this->assertSame(substr($hash, 0, 10), DeviceCode::hashPrefix(strtolower(str_replace('-', ' ', $code))));
        $this->assertNull(DeviceCode::hashPrefix('ABC'));
        $this->assertNull(DeviceCode::hashPrefix('0000-1111')); // 0 y 1 no existen en el alfabeto
    }

    public function test_sin_sesion_devuelve_el_codigo_y_si_el_telefono_esta_vinculado(): void
    {
        $headers = ['DeviceId' => self::PHONE, ClientPlatform::HEADER => ClientPlatform::MOBILE];
        $expected = DeviceCode::fromDeviceId(self::PHONE);

        $this->getJson('/api/device/code', $headers)->assertOk()
            ->assertJsonPath('code', $expected)
            ->assertJsonPath('linked', false);

        $this->bindPhone($this->employee('Beto', 20));

        $this->getJson('/api/device/code', $headers)->assertOk()
            ->assertJsonPath('code', $expected)
            ->assertJsonPath('linked', true);
    }

    public function test_exige_un_identificador_de_dispositivo_valido(): void
    {
        $this->getJson('/api/device/code')->assertStatus(422)->assertJsonPath('code', 'DEVICE_ID_REQUIRED');
    }

    public function test_el_admin_encuentra_a_quien_esta_vinculado_el_codigo(): void
    {
        $beto = $this->employee('Beto', 20);
        $this->bindPhone($beto);
        $this->bindPhone($this->employee('Ana', 30), 'otro-telefono-0000000000000000009');
        $code = DeviceCode::fromDeviceId(self::PHONE);

        $found = $this->signIn($this->admin(), ClientPlatform::WEB)
            ->getJson('/api/admin/devices?code='.urlencode(strtolower($code)))
            ->assertOk()->json('data');

        $this->assertCount(1, $found);
        $this->assertSame($beto->user_id, $found[0]['user_id']);
        $this->assertSame('Beto', $found[0]['name']);
        $this->assertSame(20, $found[0]['item']);
        $this->assertSame('mobile', $found[0]['platform']);
        $this->assertSame($code, $found[0]['code']);
    }

    public function test_un_codigo_sin_cuenta_devuelve_lista_vacia_y_uno_invalido_se_rechaza(): void
    {
        $admin = $this->signIn($this->admin(), ClientPlatform::WEB);

        $admin->getJson('/api/admin/devices?code=ABCD-2345')->assertOk()->assertJsonPath('data', []);
        $admin->getJson('/api/admin/devices?code=XYZ')->assertStatus(422)->assertJsonPath('code', 'DEVICE_CODE_INVALID');
        $admin->getJson('/api/admin/devices')->assertStatus(422);
    }

    public function test_solo_el_admin_puede_buscar_por_codigo(): void
    {
        $employee = $this->employee('Beto', 20);

        $this->signIn($employee, ClientPlatform::MOBILE)
            ->getJson('/api/admin/devices?code=ABCD-2345')
            ->assertStatus(403);
    }
}
