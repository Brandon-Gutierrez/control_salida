<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;
use App\Repositories\PremiseRepository;
use App\Repositories\ReasonPremiseRepository;
use App\Repositories\UserRepository;
use App\Services\External\ExternalAuthService;
use App\Services\Qr\QrScanService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Ingreso manual en el predio del responsable: la persona no escanea el QR,
 * sino que dicta su item y su carnet, que se validan en el sistema externo.
 * Luego sigue el mismo camino que el escaneo: elige un motivo y confirma la
 * salida, o se registra su retorno si ya estaba fuera.
 */
class ManualEntryService
{
    public function __construct(
        private ExternalAuthService $externalAuth,
        private UserRepository $users,
        private PremiseRepository $premises,
        private ReasonPremiseRepository $reasons,
        private QrScanService $scan,
        private LeaveRegistrationService $leaves,
    ) {}

    /**
     * Valida a la persona y devuelve el siguiente paso en el predio del responsable:
     * `showReasons` (con comprobante y motivos) o `showHome` (retorno ya registrado).
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function validate(User $manager, string $item, string $carnet): array
    {
        $premiseId = $this->premiseIdOf($manager);

        try {
            $identity = $this->externalAuth->authenticateByCarnet($item, $carnet);
        } catch (ConnectionException $exception) {
            Log::warning('Servicio de validación por carnet no disponible.', [
                'manager_id' => $manager->user_id,
                'exception' => $exception::class,
            ]);

            throw ApiException::temporaryFailure('MANUAL_ENTRY_SERVICE_UNAVAILABLE');
        }

        if ($identity === null || ! filled($identity['name'])) {
            throw ApiException::failure(
                422,
                'El item o el carnet no son correctos. Verifique los datos e intente nuevamente.',
                'MANUAL_ENTRY_INVALID',
                false,
            );
        }

        $person = $this->localUser((string) $identity['item'], (string) $identity['name'], $identity['external_identifier']);

        $step = $this->scan->scanAtPremise($person, $premiseId);

        $step['person'] = $person->name;
        $step['item'] = (string) $person->item;
        if (($step['action'] ?? null) === 'showReasons') {
            $step['premise'] = $this->premises->findNameById($premiseId);
            $step['reasons'] = $this->reasons->reasonNamesForPremise($premiseId)->values()->all();
        }

        return $step;
    }

    /**
     * Confirma la salida con el motivo elegido, usando el comprobante de `validate`.
     *
     * @throws ApiException
     */
    public function confirm(User $manager, string $item, string $leaveTicket, string $reasonName): void
    {
        $premiseId = $this->premiseIdOf($manager);
        $person = $this->users->findByItem((int) $item);

        if (! $person) {
            throw ApiException::failure(
                422,
                'No se encontró a la persona. Vuelva a ingresar su item y carnet.',
                'MANUAL_ENTRY_INVALID',
                false,
            );
        }

        $this->leaves->register($person, $leaveTicket, (string) $this->premises->findNameById($premiseId), $reasonName);
    }

    private function premiseIdOf(User $manager): int
    {
        if (! $manager->premise_id) {
            throw ApiException::failure(403, 'La cuenta no tiene un predio asignado. Contacte a administración.', 'PREMISE_NOT_ASSIGNED', false);
        }

        return (int) $manager->premise_id;
    }

    /** La persona ya registrada con ese item o, si nunca ingresó al sistema, una nueva. */
    private function localUser(string $item, string $name, mixed $externalIdentifier): User
    {
        $existing = $this->users->findByItem((int) $item);
        if ($existing) {
            return $existing;
        }

        $role = Role::firstOrCreate(['name' => Role::EMPLOYEE]);

        return $this->users->create([
            'external_identifier' => filled($externalIdentifier) ? (string) $externalIdentifier : 'manual-'.$item,
            'name' => $name,
            'item' => (int) $item,
            'role_id' => $role->role_id,
        ]);
    }
}
