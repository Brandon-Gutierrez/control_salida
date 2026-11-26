# API de control de salidas temporales

Backend Laravel 12 para registrar las salidas temporales y retornos del personal mediante el QR de cada predio. Lo consumen:

- `control_leaves_mobile`: app de los empleados (escaneo de QR, motivos, estado).
- `control_leaves_web`: panel de administración y pantalla de QR de los responsables de predio.

## Qué hace

- Inicia sesión con cookies (Sanctum stateful): las cuentas de empleados y administradores se validan contra el **sistema externo de personal**; los responsables de predio usan credenciales locales.
- Cada cuenta queda vinculada a **un único dispositivo por aplicación** (`web` / `mobile`) y tiene una sola sesión activa por aplicación.
- Valida que la persona esté a menos de **50 m** de un predio, con una ubicación reciente y sin GPS simulado ni VPN.
- Genera QR temporales por predio (Redis) y un comprobante corto (`leaveTicket`) que une el escaneo con la confirmación del motivo.
- Aplica un límite general de salidas por día, semana o mes.

## Requisitos

- PHP 8.2+, Composer, PostgreSQL y Redis (en tu equipo o en un servidor).
- Acceso al sistema externo de personal.

Lo que sigue es la instalación local. Para el servidor, ver [Despliegue con Docker](#despliegue-con-docker-producción).

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configure la base de datos, Redis y las variables del sistema externo (ver abajo)
php artisan migrate --seed
```

### Variables del sistema externo

Se leen desde `config/services.php` (`external_api`):

| Variable | Qué es |
|---|---|
| `KEY_SOFTWARE` | Clave enviada en la cabecera `keysoftware` |
| `API_LOGIN` | Autenticación de credenciales |
| `API_GETEMPLOYEE` | Datos del empleado (foto, cargo, área) |
| `API_GETREASONS` | Catálogo de motivos de salida |
| `API_GETCHECKOUT` | Salida registrada del día |
| `API_LISTOUTPUTBYITEM` | Historial para el detalle móvil. Si se deja vacío, usa `https://excnx.comteco.com.bo/apiAuthTest/index.php/api/listOutputByItem`. Laravel agrega el `item` de la sesión. |
| `API_REGISTERCHECKOUT` | Registro de salidas y retornos |

## Despliegue con Docker (producción)

Este repositorio despliega **solo la API**; el panel web se despliega aparte, desde su repositorio, y ninguno necesita los archivos del otro. Aquí corren dos contenedores: `app` (php-fpm y las tareas programadas) y `nginx` (servidor web de la API). **PostgreSQL y Redis no corren en Docker**: son servidores de Comteco y se configuran en `docker/.env`. No hay un modo de desarrollo en Docker.

```text
navegador ──► web (otro despliegue, 80/443) ──┐  red de Docker "control-salidas", HTTP
                                              ▼
app móvil ──── HTTPS 8443 ─────────────► nginx ──► app (php-fpm + crond) ──► PostgreSQL y Redis
```

El navegador ve una sola dirección, la de la web, que reenvía `/api` y `/sanctum` a este `nginx`. La app móvil puede apuntar a `https://<nombre>:8443`, sin depender de la web (ese puerto debe estar abierto en el cortafuegos), o a la dirección de la web.

Requisitos en el servidor: Docker con Compose v2.

### Red compartida con la web

Se crea **una sola vez** en el servidor, antes del primer despliegue de cualquiera de los dos:

```bash
docker network create --subnet 172.30.0.0/24 control-salidas
```

Si `172.30.0.0/24` choca con una red de Comteco, usa otro rango y ponlo en `TRUSTED_PROXY` de este `.env`: de ese rango se acepta la IP real que informa la web. Sin eso, todos los usuarios de la web parecerían tener la misma IP y compartirían el límite de intentos de inicio de sesión.

### Qué pedir para PostgreSQL y Redis

- IP o nombre y puerto de cada uno, y que permitan conexiones desde la IP del servidor de la aplicación (cortafuegos y `pg_hba.conf`).
- PostgreSQL: una base ya creada y un usuario que pueda crear tablas, funciones y *triggers* en el esquema `public` (por ejemplo, el dueño de la base). No necesita ser superusuario.
- Redis: contraseña o usuario si los exige, número de base y si usa TLS. La configuración actual es para **un solo servidor Redis**: Cluster o Sentinel requieren cambiar `config/database.php`.
- Si alguno exige TLS: `DB_SSLMODE=require` o `REDIS_SCHEME=tls`. Verificar el certificado del servidor contra una CA propia no está configurado.
- Si sus IP están en rangos `172.x`, avisar: pueden chocar con las redes internas de Docker.

### Primer despliegue

Con la red ya creada, desde la carpeta `docker/` de este repositorio:

```bash
cp .env.example .env    # completar: PUBLIC_HOST, APP_KEY, DB_*, REDIS_*, SUPERADMIN_*, KEY_SOFTWARE y API_*
docker compose build
docker compose run --rm app php artisan migrate --force    # crea las tablas
docker compose run --rm app php artisan db:seed --force    # roles y administrador inicial
docker compose up -d
curl -k https://localhost:8443/up -o /dev/null -w '%{http_code}\n'    # debe responder 200
```

`PUBLIC_HOST` es la dirección **de la web** (la misma que en el `.env` de la web), no la de la API: el inicio de sesión del navegador depende de ella.

**El arranque no ejecuta migraciones ni datos iniciales**: siempre se corren a mano. Antes de abrir php-fpm, `app` espera hasta 2 minutos a cada servidor; si uno no responde, se detiene, deja el motivo en `docker compose logs app` y Docker lo reintenta.

`db:seed` crea la cuenta ADMIN con `SUPERADMIN_ITEM`, que inicia sesión con sus credenciales de Comteco. Se puede repetir sin duplicar nada.

### Actualizar

Después de `git pull`, desde `docker/`. No toca la web:

```bash
docker compose build
docker compose run --rm app php artisan migrate --force
docker compose up -d
```

### Desde la instalación anterior (un solo compose)

Antes, un único proyecto de Docker llamado `control-salidas` levantaba todo. Sus contenedores siguen ocupando los puertos 80 y 443, así que hay que quitarlos **una vez**, cuando ya tengas los datos de PostgreSQL y Redis de Comteco y antes del primer despliegue nuevo. El sistema queda fuera de servicio hasta terminar. Ejecútalo desde una carpeta que no tenga un compose (por ejemplo, tu carpeta personal):

```bash
docker compose -p control-salidas down --remove-orphans
```

Sin `-v`, los volúmenes con los datos quedan en disco. Después completa de nuevo `docker/.env` a partir de `.env.example` en cada repositorio (conserva `APP_KEY`). Cuando todo funcione y **no necesites los datos antiguos**, se borran sin vuelta atrás con `docker volume rm control-salidas_pgdata control-salidas_redisdata`.

### Backend y web en servidores distintos

No hace falta cambiar archivos: en el `.env` de la web, `BACKEND_URL=https://IP-del-backend:8443`, y en el del backend, `TRUSTED_PROXY=IP-del-servidor-web/32`. El tráfico va cifrado, pero la web no verifica el certificado de la API. La red `control-salidas` se crea igualmente en los dos servidores.

### Tareas programadas (crontab)

El `crond` de Alpine, dentro de `app`, ejecuta `php artisan schedule:run` cada minuto como `www-data`. La línea está en [docker/crontab](docker/crontab); el `Dockerfile` la copia con `COPY` a `/etc/crontabs/www-data` y [docker/entrypoint.sh](docker/entrypoint.sh) inicia `crond`. Como queda dentro de la imagen, **cambiar el crontab exige reconstruirla**. El horario de cada tarea se define en [routes/console.php](routes/console.php): hoy, la sincronización de motivos con Comteco a las 00:00 de lunes a viernes (hora de Bolivia), con reintentos a las 00:15 y 00:30.

Cómo comprobar que se ejecuta:

1. Pasados 5 minutos del arranque, `docker compose ps` debe mostrar `app` como `healthy`. Si el planificador lleva más de 3 minutos sin terminar bien, pasa a `unhealthy` (Docker lo marca, no lo reinicia). Justo después de arrancar aparece `healthy` aunque el cron todavía no haya corrido.
2. `docker compose exec app stat -c %y /tmp/scheduler.heartbeat`: la hora avanza cada minuto.
3. `docker compose exec app php artisan schedule:list`: próximas ejecuciones.
4. Después de las 00:00 de un día hábil, `docker compose logs app` muestra `Running ["artisan" reasons:sync --scheduled]` y el resultado.

Para ejecutar una tarea al momento: `docker compose exec -u www-data app php artisan schedule:test`. **Sincroniza de verdad con Comteco.** Usa siempre `-u www-data`: como root dejaría en `storage/logs` un archivo que el cron no puede sobrescribir (se corrige reiniciando `app`).

### HTTPS y certificado

La API y la web usan cada una su carpeta `docker/certs/`: el certificado se copia **en los dos repositorios** (en el mismo servidor, los mismos archivos). El `nginx` de la API lee de ella:

| Archivo | Contenido |
|---|---|
| `fullchain.pem` | El certificado del servidor y, debajo, los certificados intermedios de la entidad emisora. Sin los intermedios, la app móvil puede rechazar la conexión aunque el navegador la acepte. |
| `privkey.pem` | La clave privada, sin contraseña. |

Si faltan, se genera uno **autofirmado** para poder probar (los navegadores y la app móvil no confían en él). Para poner el real, reemplaza los dos archivos (quizá con `sudo`) y ejecuta `docker compose restart nginx` aquí y `docker compose restart web` en la web. Antes, comprueba el nombre y el vencimiento, y que las dos últimas órdenes den el mismo resultado (la clave corresponde al certificado):

```bash
openssl x509 -in certs/fullchain.pem -noout -subject -issuer -enddate
openssl x509 -in certs/fullchain.pem -noout -pubkey | openssl sha256
openssl pkey -in certs/privkey.pem -pubout | openssl sha256
```

El certificado de Comteco informado es el comodín `*.comteco.com.bo` (emisor *SSL.com TLS Issuing RSA CA R1*), que **vence el 13/02/2027**.

- Un comodín cubre un solo nivel, como `salidas.comteco.com.bo`, y no una IP. Cuando Comteco asigne el nombre, ponlo en `PUBLIC_HOST` y entra por ese nombre, también desde la app móvil.
- Si Comteco pone **su propio proxy** delante de la web, indica su IP en `TRUSTED_PROXY` del `.env` **de la web** (la guía de la web explica el caso en que ese proxy hable por HTTP).

## Comandos

```bash
php artisan test                     # Pruebas (SQLite en memoria, servicio externo y Redis simulados)
php vendor/bin/pint                  # Formato de código
php artisan devices:reset {usuario}  # Desvincula el dispositivo de una cuenta (--platform=web|mobile|all)
```

## Estructura

```text
app/
├── Console/Commands      devices:reset
├── Exceptions            ApiException (errores esperados que se responden como JSON)
├── Http/
│   ├── Controllers       Delgados: validan con un Form Request y delegan en un servicio
│   ├── Middleware        EnsureActiveSession, EnsureDeviceIsBound, EnsureClientPlatform,
│   │                     EnsureUserHasRole, EnsurePremiseLocation
│   ├── Requests          Un Form Request por endpoint
│   └── Resources         PremiseResource
├── Models                Eloquent
├── Repositories          Consultas por entidad
├── Services              Reglas y casos de uso (Auth, Account, Premise, External, Leave, Qr)
└── Support               ClientPlatform, Geo
```

Más detalle en [docs/mapa-del-repositorio.md](docs/mapa-del-repositorio.md) y [docs/validacion-ubicacion.md](docs/validacion-ubicacion.md).

## API

Prefijo `/api`. Públicas: `GET /health` y `POST /auth/login`. Autenticadas (sesión + dispositivo): `/auth/*`, `/me/*`, `/qr/scan`, `/leaves`, `/premises/{nombre}/reasons` (app móvil), `/manager/qr-token` (responsable) y `/admin/*` (administración). Cada aplicación se identifica con las cabeceras `X-Client-Platform` y `DeviceId`.
