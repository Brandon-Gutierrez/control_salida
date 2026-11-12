# Docker — Control de salidas temporales

Dockeriza el **backend Laravel**, la **web Flutter** y **PostgreSQL** (más **Redis**, que el backend necesita para los QR y comprobantes). La app móvil queda fuera: solo consume la API.

```text
                       host :80 / :443
                              │
                     ┌────────▼─────────┐
                     │  web  (nginx)    │  /            → Flutter Web (estático)
                     │                  │  /api, /sanctum, /up → php-fpm
                     └────────┬─────────┘
                              │ fastcgi :9000
                     ┌────────▼─────────┐      ┌──────────────┐
                     │  app (Laravel)   ├─────►│ db (Postgres)│  volumen pgdata
                     │  php-fpm 8.4     │      └──────────────┘
                     │                  │      ┌──────────────┐
                     │                  ├─────►│ redis        │  volumen redisdata
                     └──────────────────┘      └──────────────┘
                     ┌──────────────────┐
                     │ scheduler        │  php artisan schedule:work (misma imagen que app)
                     └──────────────────┘
```

El servicio **`scheduler`** ejecuta las tareas programadas de Laravel (`routes/console.php`): la sincronización de motivos con Comteco a las **00:00 (hora de Bolivia) de lunes a viernes**, con reintentos a las 00:15 y 00:30 si falla. Debe haber **una sola instancia**. Sin él, los motivos solo se sincronizan con el botón manual del panel. Alternativa sin contenedor: un cron del servidor con `* * * * * docker compose exec -T app php artisan schedule:run`.

Web y API comparten dominio: la web se compila con `API_BASE_URL` vacío y llama a `/api` en su propio origen (sin CORS). Solo `web` publica puertos; PostgreSQL y Redis no son accesibles desde fuera de la red de Docker.

## Archivos

| Archivo | Para qué |
|---|---|
Esta carpeta (`docker/`) vive en el repositorio del **backend**; no hay un tercer repositorio. Todos los comandos se ejecutan desde aquí.

| Archivo | Para qué |
|---|---|
| `docker-compose.yml` | Producción (base). |
| `docker-compose.dev.yml` | Desarrollo (se suma a la base). |
| `.env` / `.env.example` | Variables. `.env` contiene secretos y no se versiona (ver `.gitignore` de esta carpeta). |
| `nginx/` | Puerta de entrada: `gateway.conf.template` (producción, HTTPS), `gateway.dev.conf.template` (desarrollo) y `fastcgi-laravel.conf` (reenvío a php-fpm). |
| `certs/` | Aquí van `fullchain.pem` y `privkey.pem`. |
| `entrypoint.sh` | Arranque de la API: espera la BD, migra y siembra. |

### Qué vive en cada repositorio

| Repositorio | Archivos de Docker |
|---|---|
| **Backend** (`control_salida`) | `Dockerfile` (php-fpm 8.4, targets `dev` y `prod`), `.dockerignore` y esta carpeta `docker/` completa (compose, nginx, entrypoint, guía). |
| **Web** (`control_leaves_web`) | `Dockerfile` (compila Flutter 3.47.0 y lo sirve con nginx), `docker/nginx/` (configuración por defecto y script de certificado autofirmado), `.dockerignore` y `.gitattributes`. La imagen funciona sola: sirve la web en el puerto 80. |

Cada imagen se construye desde su propio repositorio. El compose las une: monta en la imagen de la web la configuración que reenvía `/api` y `/sanctum` al backend y añade HTTPS.

## En el servidor

Clona los dos repositorios lado a lado:

```bash
mkdir /opt/control && cd /opt/control
git clone <repo-backend> backend
git clone <repo-web> web
cd backend/docker
cp .env.example .env     # BACKEND_PATH=.. y WEB_PATH=../../web ya vienen por defecto; añade los secretos
docker compose up -d --build
```

Para actualizar: `git pull` en `backend/` y/o `web/`, y desde `backend/docker` `docker compose up -d --build`.

## Primer arranque (producción)

Requisito: Docker con Compose v2.24 o superior.

1. Revisa `.env` (ya trae `APP_KEY`, `DB_PASSWORD` y `REDIS_PASSWORD` generados) y completa:
   - `PUBLIC_HOST`: nombre o IP con el que se entrará (si el HTTPS no va en el 443: `midominio.com:8443`).
   - `BACKEND_PATH` y `WEB_PATH`: ya apuntan al backend (`..`) y a la web clonada junto a él; cámbialos solo si tu disposición es distinta.
   - `SUPERADMIN_ITEM` y `SUPERADMIN_NAME`: tu item corporativo y nombre.
   - `KEY_SOFTWARE` y las `API_*` de Comteco.
2. (Opcional) Copia tu certificado a `certs/fullchain.pem` y `certs/privkey.pem`. Si faltan, se genera uno **autofirmado** en el primer arranque: sirve para probar, pero los navegadores y la app móvil lo rechazarán.
3. Levanta todo:

   ```bash
   docker compose up -d --build
   ```

4. Entra a `https://<PUBLIC_HOST>` e inicia sesión con tus credenciales de Comteco (el item que pusiste en `SUPERADMIN_ITEM`).

El contenedor `app` ejecuta al arrancar `migrate` y `db:seed` (roles + administrador). Es idempotente: se puede reiniciar cuantas veces se quiera.

### Super administrador

El login de ADMIN y EMPLOYEE siempre se valida contra Comteco; no hay contraseña local para el administrador. El seeder crea (o promueve a ADMIN) la cuenta con ese `item`; al iniciar sesión por primera vez con Comteco, la cuenta se completa sin duplicarse. Si `SUPERADMIN_ITEM` está vacío no se siembra ningún administrador.

## Desarrollo

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

(O descomenta `COMPOSE_FILE=...` en `.env` y usa solo `docker compose up -d`.)

- API + web en `http://localhost:8080` (`DEV_HTTP_PORT`); sin TLS.
- El código del backend se monta desde `BACKEND_PATH`: los cambios se ven al instante. `vendor/` y `storage/` viven en volúmenes de Docker; el `.env` de Laragon no se usa.
- PostgreSQL y Redis solo en `127.0.0.1:15432` y `127.0.0.1:16379`.
- Web con recarga en caliente: `flutter run -d chrome --web-port 65085 --dart-define=API_BASE_URL=http://localhost:8080` en tu equipo. Este modo ya incluye `localhost:65085` en CORS y Sanctum.
- Las pruebas del backend: `docker compose exec app php artisan test`.
- Para reflejar cambios de la web en el contenedor: `docker compose ... up -d --build web`.

## Datos y persistencia

| Volumen | Contenido |
|---|---|
| `control-salidas_pgdata` | Base de datos (usuarios, administrador, predios, registros). |
| `control-salidas_redisdata` | Redis con AOF. |

Sobreviven a `docker compose down`, a reinicios y a reconstruir imágenes. **`docker compose down -v` los borra.**

```bash
# Respaldo
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > respaldo.sql
# Restauración (en una base vacía)
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"' < respaldo.sql
```

## Respaldos

El procedimiento de respaldo, restauración y prueba mensual está en [`RESPALDOS.md`](RESPALDOS.md). El historial de salidas no se puede borrar (claves foráneas `RESTRICT` y un trigger de PostgreSQL), pero eso **no sustituye** al respaldo.

## Puertos

| Puerto host | Servicio | Variable |
|---|---|---|
| 80 | HTTP → redirige a HTTPS | `HTTP_PORT` |
| 443 | HTTPS (web + API) | `HTTPS_PORT` |
| 8080 | HTTP (solo desarrollo) | `DEV_HTTP_PORT` |

No se usan 5432, 6379 ni 8000. Si cambias `HTTPS_PORT`, actualiza `PUBLIC_HOST` con el puerto.

## App móvil

Apunta su `.env` (`API=...`) a `https://<PUBLIC_HOST>`. Con el certificado autofirmado no podrá conectar: usa un certificado válido.

## Notas

- `config/cors.php` ahora lee `CORS_ALLOWED_ORIGINS` (por defecto sigue siendo `http://localhost:65085`) y `config/services.php` lee `SUPERADMIN_ITEM`/`SUPERADMIN_NAME`; se añadió `SuperAdminSeeder` con su prueba. Son los únicos cambios de código del backend; el resto son los archivos de Docker.
- La API confía en las cabeceras `X-Forwarded-For`/`-Proto` del gateway (`trustProxies('*')`): la IP real del cliente se usa en el límite de intentos de inicio de sesión (5 fallos por cuenta+IP y 20 por IP cada minuto) y se guarda en la sesión. El gateway debe reenviar esas cabeceras y la API **no debe publicarse directamente** (solo por el gateway).
- Las migraciones se aplican solas al arrancar `app`. La de noviembre de 2026 cambia claves foráneas a `RESTRICT` y crea triggers; si la tabla `reason_premise` tuviera un mismo motivo repetido en un predio, la migración se detiene con un mensaje claro para corregirlo antes.
- Cambiar `API_BASE_URL`, la versión de Flutter o el código de la web requiere reconstruir la imagen `web`.
