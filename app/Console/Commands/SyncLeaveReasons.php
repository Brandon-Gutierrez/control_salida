<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Services\Leave\LeaveReasonSyncService;
use Illuminate\Console\Command;

/**
 * Sincroniza el catálogo de motivos con Comteco. El planificador la ejecuta a
 * las 00:00 (hora de Bolivia) de lunes a viernes; también sirve para correrla a mano.
 */
class SyncLeaveReasons extends Command
{
    protected $signature = 'reasons:sync
        {--scheduled : Ejecución del planificador: si hoy ya hubo una sincronización correcta, no hace nada (así los reintentos de 00:15 y 00:30 solo corren si falló la de 00:00)}
        {--force : Permite desactivar más de la mitad de los motivos activos (solo si Comteco realmente los retiró)}';

    protected $description = 'Sincroniza los motivos de salida con Comteco';

    public function handle(LeaveReasonSyncService $sync): int
    {
        $scheduled = (bool) $this->option('scheduled');

        if ($scheduled && $sync->hasSucceededToday()) {
            $this->info('Los motivos ya se sincronizaron hoy.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->sync(
                $scheduled ? LeaveReasonSyncService::SOURCE_SCHEDULED : LeaveReasonSyncService::SOURCE_CONSOLE,
                null,
                (bool) $this->option('force'),
            );
        } catch (ApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Motivos sincronizados: %d nuevos, %d actualizados, %d reactivados, %d desactivados.',
            $result['added'],
            $result['updated'],
            $result['reactivated'],
            $result['deactivated'],
        ));

        return self::SUCCESS;
    }
}
