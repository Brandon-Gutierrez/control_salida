<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| Requieren que algo llame a `schedule:run` cada minuto. En Docker lo hace el
| crontab del contenedor de la API (docker/crontab); fuera de Docker, un cron
| propio o `php artisan schedule:work`.
|
| Laravel descarta la salida de los comandos programados. Con `thenWithOutput`
| se reenvía (incluidos sus logs a stderr) a la salida de `schedule:run`, para
| que aparezca en `docker logs`.
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
        ->onOneServer()
        ->thenWithOutput(function (Stringable $output) {
            fwrite(STDOUT, PHP_EOL.$output);
        }, onlyIfOutputExists: true);
}
