<?php

// Lo ejecuta entrypoint.sh antes de levantar la API. PostgreSQL y Redis son
// servidores externos: espera a que respondan usando la misma configuración que
// la API (host, puerto, credenciales y TLS). Solo conecta; no escribe nada.
//
//   php docker/wait-for-services.php [segundos de espera por servicio]

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$waitSeconds = (int) ($argv[1] ?? 120);

$services = [
    'PostgreSQL' => [
        'target' => config('database.connections.pgsql.host').':'.config('database.connections.pgsql.port'),
        'check' => function () {
            DB::purge();
            DB::connection()->getPdo();
        },
    ],
    'Redis' => [
        'target' => config('database.redis.default.host').':'.config('database.redis.default.port'),
        'check' => function () {
            Redis::purge();
            Redis::connection()->ping();
        },
    ],
];

foreach ($services as $name => $service) {
    echo "[entrypoint] Esperando a {$name} en {$service['target']}...".PHP_EOL;
    $deadline = time() + $waitSeconds;

    while (true) {
        try {
            $service['check']();
            break;
        } catch (Throwable $exception) {
            if (time() >= $deadline) {
                fwrite(STDERR, "[entrypoint] {$name} no respondió a tiempo: {$exception->getMessage()}".PHP_EOL);
                exit(1);
            }
            sleep(2);
        }
    }
}
