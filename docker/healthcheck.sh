#!/bin/sh
# Salud del contenedor de la API: arranque terminado, php-fpm aceptando
# conexiones y el planificador ejecutándose. crond renueva el latido cada minuto
# en que `schedule:run` termina bien (ver docker/crontab).

if [ ! -f /tmp/ready ]; then
    echo "El arranque todavía no terminó."
    exit 1
fi

if ! php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'; then
    echo "php-fpm no acepta conexiones."
    exit 1
fi

if [ -z "$(find /tmp/scheduler.heartbeat -mmin -3 2>/dev/null)" ]; then
    echo "El planificador lleva 3 minutos sin ejecutarse bien (crond o schedule:run)."
    exit 1
fi
