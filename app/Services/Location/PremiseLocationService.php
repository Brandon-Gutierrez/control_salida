<?php

namespace App\Services\Location;

use App\Exceptions\ApiException;
use App\Models\Premise;
use App\Support\Geo;

/**
 * La persona debe estar cerca del predio al que corresponde el QR escaneado
 * (o el comprobante de la salida): a no más de `geofence.radius_m` metros y con
 * un GPS de precisión suficiente (`geofence.max_accuracy_m`).
 */
class PremiseLocationService
{
    public function radiusMeters(): int
    {
        return (int) config('geofence.radius_m');
    }

    public function maxAccuracyMeters(): int
    {
        return (int) config('geofence.max_accuracy_m');
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy_m: float}  $location  Prueba validada por EnsureLocationProof.
     *
     * @throws ApiException si el predio no tiene ubicación, el GPS es impreciso o está fuera del radio.
     */
    public function assertWithinPremise(array $location, int $premiseId): void
    {
        $premise = Premise::find($premiseId, ['premise_id', 'name', 'latitude', 'longitude']);

        if (! $premise || $premise->latitude === null || $premise->longitude === null) {
            throw ApiException::failure(
                403,
                'El predio no tiene ubicación configurada. Contacte a administración.',
                'PREMISE_WITHOUT_LOCATION',
                false,
            );
        }

        $accuracy = (float) $location['accuracy_m'];
        if ($accuracy > $this->maxAccuracyMeters()) {
            throw ApiException::failure(
                403,
                sprintf(
                    'La precisión de su ubicación es insuficiente (%d m; máximo %d m). Salga a un lugar abierto e intente nuevamente.',
                    (int) round($accuracy),
                    $this->maxAccuracyMeters(),
                ),
                'LOCATION_ACCURACY_TOO_LOW',
                true,
            );
        }

        $distance = Geo::distanceInMeters(
            (float) $location['latitude'],
            (float) $location['longitude'],
            (float) $premise->latitude,
            (float) $premise->longitude,
        );

        if ($distance > $this->radiusMeters()) {
            throw ApiException::failure(
                403,
                sprintf(
                    'Debe estar a %d m o menos del predio %s para realizar esta acción (está a unos %d m).',
                    $this->radiusMeters(),
                    $premise->name,
                    (int) round($distance),
                ),
                'OUTSIDE_PREMISE_RADIUS',
                false,
            );
        }
    }
}
