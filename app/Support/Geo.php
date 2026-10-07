<?php

namespace App\Support;

/** Cálculos geográficos. */
final class Geo
{
    private const EARTH_RADIUS_METERS = 6_371_000;

    /** Distancia en metros entre dos coordenadas (fórmula de haversine). */
    public static function distanceInMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);

        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB))
            * sin($longitudeDelta / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
