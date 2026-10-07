<?php

namespace Tests\Feature;

use App\Models\LeaveReason;
use App\Models\Premise;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInWithDevice;
use Tests\TestCase;

/** Panel de administración: predios, catálogo de motivos y configuración del QR. */
class AdminCatalogTest extends TestCase
{
    use RefreshDatabase;
    use SignsInWithDevice;

    private User $admin;

    private Premise $premise;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Role::create(['name' => Role::ADMIN]);
        Role::create(['name' => Role::EMPLOYEE]);
        $this->admin = User::create([
            'external_identifier' => 'ad', 'name' => 'Ana', 'item' => 1, 'role_id' => $admin->role_id,
        ]);
        $this->premise = Premise::create(['name' => 'Prado', 'latitude' => -17.39, 'longitude' => -66.15]);
        LeaveReason::create(['name' => 'Trámite', 'code' => 'T']);
        LeaveReason::create(['name' => 'Almuerzo', 'code' => 'A']);
    }

    private function asAdmin(): static
    {
        return $this->signIn($this->admin, ClientPlatform::WEB);
    }

    public function test_health_es_publico(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'OK']);
    }

    public function test_el_catalogo_de_motivos_se_entrega_ordenado(): void
    {
        $this->asAdmin()->getJson('/api/admin/reasons')
            ->assertOk()->assertExactJson(['status' => 0, 'reasons' => ['Almuerzo', 'Trámite']]);
    }

    public function test_el_admin_reemplaza_los_motivos_de_un_predio(): void
    {
        $url = '/api/admin/premises/'.$this->premise->premise_id.'/reasons';

        $this->asAdmin()->putJson($url, ['reasons' => ['Trámite', 'Almuerzo']])
            ->assertOk()
            ->assertJsonPath('data.name', 'Prado')
            ->assertJsonPath('data.reason_names', ['Almuerzo', 'Trámite']);

        $this->asAdmin()->putJson($url, ['reasons' => ['Trámite']])
            ->assertOk()->assertJsonPath('data.reason_names', ['Trámite']);

        $this->asAdmin()->putJson($url, ['reasons' => ['Inventado']])->assertStatus(422);
        $this->asAdmin()->putJson($url, [])->assertStatus(422);
    }

    public function test_el_listado_de_predios_incluye_motivos_y_responsable(): void
    {
        $this->asAdmin()->putJson(
            '/api/admin/premises/'.$this->premise->premise_id.'/reasons',
            ['reasons' => ['Trámite']],
        )->assertOk();

        $this->asAdmin()->getJson('/api/admin/premises')
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.0.name', 'Prado')
            ->assertJsonPath('data.0.manager', null)
            ->assertJsonPath('data.0.reason_names', ['Trámite']);
    }

    public function test_el_admin_consulta_y_cambia_el_tiempo_de_vida_del_qr(): void
    {
        $this->asAdmin()->getJson('/api/admin/settings/qr')->assertOk()->assertExactJson([
            'status' => 0,
            'data' => ['qr_ttl_seconds' => 300, 'qr_ttl_seconds_min' => 30, 'qr_ttl_seconds_max' => 3600],
        ]);

        $this->asAdmin()->putJson('/api/admin/settings/qr', ['qr_ttl_seconds' => 120])
            ->assertOk()->assertExactJson([
                'status' => 0,
                'message' => 'Tiempo de vida del QR actualizado.',
                'data' => ['qr_ttl_seconds' => 120],
            ]);

        $this->asAdmin()->getJson('/api/admin/settings/qr')->assertJsonPath('data.qr_ttl_seconds', 120);
    }

    public function test_el_tiempo_de_vida_del_qr_tiene_limites(): void
    {
        foreach ([29, 3601, 'abc', null] as $invalid) {
            $this->asAdmin()->putJson('/api/admin/settings/qr', ['qr_ttl_seconds' => $invalid])->assertStatus(422);
        }
    }
}
