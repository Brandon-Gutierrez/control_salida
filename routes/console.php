<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| Requieren un ejecutor del planificador: `php artisan schedule:work` (servicio
| `scheduler` del docker-compose) o `schedule:run` cada minuto desde el cron.
|
*/

// Motivos de salida: se sincronizan con Comteco a las 00:00 (hora de Bolivia) de
// lunes a viernes. Si falla, se reintenta a las 00:15 y a las 00:30: esas
// ejecuciones no hacen nada si la de las 00:00 (o una anterior) ya funcionó.
foreach (['00:00', '00:15', '00:30'] as $time) {
    Schedule::command('reasons:sync --scheduled')
        ->weekdays()
        ->at($time)
        ->timezone('America/La_Paz')
        ->withoutOverlapping()
        ->onOneServer();
}
