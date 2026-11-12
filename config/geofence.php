<?php

// Reglas de ubicación para registrar salidas y retornos desde el teléfono.
return [

    // Distancia máxima (metros) entre la persona y el predio del QR o del comprobante.
    // La web de administración dibuja este mismo radio: lo recibe de la API.
    'radius_m' => (int) env('GEOFENCE_RADIUS_METERS', 50),

    // Peor precisión (metros) del GPS que se acepta; más allá la ubicación es demasiado incierta.
    'max_accuracy_m' => (int) env('GEOFENCE_MAX_ACCURACY_METERS', 30),

    // La captura de la ubicación no puede ser más vieja que esto ni estar en el futuro.
    'max_age_seconds' => 120,
    'max_future_seconds' => 15,

];
