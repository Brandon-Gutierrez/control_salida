# Respaldos y restauración de la base de datos

Este documento define **qué se respalda, cada cuánto, cómo se restaura y cómo se comprueba que el respaldo sirve**. Los comandos están pensados para el `docker-compose.yml` de esta carpeta; si PostgreSQL corre fuera de Docker, los mismos `pg_dump`/`pg_restore` se ejecutan directamente.

## Historial permanente ≠ respaldo

Son dos protecciones distintas y se necesitan las dos:

| | Qué protege | Cómo |
|---|---|---|
| **Historial permanente** | Que nadie borre salidas por error o por una edición de datos. | Las salidas (`records`) y las relaciones motivo-predio **no se borran**: claves foráneas `RESTRICT`, borrado lógico y un trigger de PostgreSQL que bloquea `DELETE`/`TRUNCATE`. |
| **Respaldo** | Pérdida del disco, corrupción, un error humano grave (`DROP`, volumen borrado con `down -v`), ransomware o un servidor perdido. | Copias de la base **fuera** del servidor, con las pruebas de restauración de más abajo. |

El historial permanente no sustituye al respaldo: si el disco falla, el trigger no devuelve los datos.

## Qué respaldar

| Qué | Dónde está | Respaldo |
|---|---|---|
| **Base de datos PostgreSQL** (usuarios, predios, motivos, **salidas**, bitácora, ajustes y sesiones) | Volumen `control-salidas_pgdata` | **Sí, es lo esencial.** |
| `.env` del compose (claves, `APP_KEY`, contraseñas) | Servidor | Sí, **cifrado y aparte** de la base: `APP_KEY` y los secretos no se pueden recuperar de un volcado. |
| Certificados HTTPS | `docker/certs` | Sí (o se reemiten). |
| Redis (`control-salidas_redisdata`) | Volumen | **No hace falta:** solo guarda el QR vigente y los comprobantes de segundos de vida. Las sesiones están en PostgreSQL. |
| Código y imágenes | Repositorios | Ya están en el control de versiones. |

## Cada cuánto y cuánto tiempo se conserva (recomendación)

- **Diario**, fuera del horario de uso (p. ej. 02:00, hora de Bolivia, después de la sincronización de motivos de las 00:00).
- Conservar **30 copias diarias** y **12 mensuales** (la del primer día de cada mes).
- **Copiar fuera del servidor** (otro equipo, almacenamiento de la empresa) y, si es posible, una copia sin conexión.
- Ajustar la frecuencia a cuánta pérdida se tolera: con copias diarias se pueden perder hasta 24 h de salidas. Si no es aceptable, activar el archivado de WAL de PostgreSQL (recuperación a un punto en el tiempo).

## Quién puede acceder

El volcado contiene **datos personales** (nombres, items, hashes de contraseñas de responsables, hashes de dispositivos y todo el historial). Por eso:

- Guardarlo **cifrado** (p. ej. `gpg`/`age` o el cifrado del almacenamiento) y con permisos solo para el responsable de TI.
- No enviarlo por correo ni dejarlo en carpetas compartidas.
- Registrar quién lo descargó cuando se use para una restauración.

## Hacer un respaldo

Desde la carpeta `docker`:

```bash
# Formato "custom": comprimido y restaurable por partes con pg_restore.
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > respaldo_$(date +%F).dump
```

Ejemplo de script diario con rotación (`/opt/control-salidas/respaldar.sh`, ejecutado por `cron` a las 02:00):

```bash
#!/bin/sh
set -eu
cd /opt/control-salidas/docker                      # carpeta del docker-compose
DEST=/var/respaldos/control-salidas                  # carpeta con permisos restringidos (chmod 700)
mkdir -p "$DEST/diarios" "$DEST/mensuales"
FILE="$DEST/diarios/respaldo_$(date +%F).dump"

docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > "$FILE.tmp"
# Un volcado vacío o truncado no es un respaldo: se comprueba que pg_restore lo pueda leer.
docker compose exec -T db pg_restore --list < "$FILE.tmp" > /dev/null
mv "$FILE.tmp" "$FILE"

# El primer día del mes se guarda además como copia mensual.
[ "$(date +%d)" = "01" ] && cp "$FILE" "$DEST/mensuales/"

# Rotación: 30 diarios y 12 mensuales.
find "$DEST/diarios"   -name 'respaldo_*.dump' -mtime +30  -delete
find "$DEST/mensuales" -name 'respaldo_*.dump' -mtime +365 -delete

# Aquí: cifrar y copiar fuera del servidor (rsync, almacenamiento de la empresa, etc.).
```

## Restaurar

**Nunca sobre la base en uso para "probar".** Se restaura en una base vacía y aparte; solo si se está recuperando de un desastre se restaura en la definitiva (con la aplicación detenida: `docker compose stop app scheduler web`).

```bash
# 1. Base vacía y temporal (para una prueba) — para un desastre real use la base definitiva.
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE restauracion_prueba"'

# 2. Restaurar el volcado en esa base.
docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d restauracion_prueba --no-owner' < respaldo_2026-11-11.dump

# 3. (Al terminar la prueba) borrarla.
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE restauracion_prueba"'
```

Notas:

- El volcado incluye las funciones y los **triggers** de protección: la base restaurada conserva la protección del historial. Por eso se restaura **en una base vacía**; no se debe intentar "limpiar" tablas dentro de una base con datos (`DELETE`/`TRUNCATE` sobre `records` están bloqueados a propósito).
- Si hay que reemplazar la base definitiva: `DROP DATABASE` y `CREATE DATABASE` (no se bloquean) y luego `pg_restore`.
- Después de restaurar en producción, volver a levantar los servicios (`docker compose up -d`). El entrypoint de la API vuelve a ejecutar las migraciones pendientes y el sembrado, que son idempotentes.

## Prueba de restauración (hacerla periódicamente)

Un respaldo que nunca se probó no es un respaldo. **Cada mes** (y siempre después de cambiar la versión de PostgreSQL o el esquema) restaurar el último volcado en una base temporal y comprobar:

```sql
-- Ejecutar en la base restaurada (restauracion_prueba):

-- 1. Hay datos y el último movimiento es reciente (compárelo con producción).
SELECT count(*) AS salidas, max(leave_time) AS ultima_salida FROM records;

-- 2. El folio sigue sin huecos: debe dar 0.
SELECT max(record_id) - count(*) AS huecos FROM records;

-- 3. Las demás tablas tienen filas.
SELECT (SELECT count(*) FROM users)       AS usuarios,
       (SELECT count(*) FROM premises)    AS predios,
       (SELECT count(*) FROM reasons)     AS motivos,
       (SELECT count(*) FROM audit_logs)  AS bitacora;

-- 4. La protección del historial viajó con el respaldo: debe dar 3 filas (2 en records, 1 en reason_premise).
SELECT tgname FROM pg_trigger
WHERE tgname IN ('records_no_delete', 'records_no_truncate', 'reason_premise_no_delete');
```

Registrar cada prueba (fecha, archivo usado, resultado, cuánto tardó la restauración). Ese tiempo es el que tardaría una recuperación real.

## Responsabilidades

| Tarea | Responsable | Frecuencia |
|---|---|---|
| Ejecutar el respaldo y la rotación | TI | Diaria (automática) |
| Copia fuera del servidor y cifrado | TI | Diaria |
| Comprobar que el respaldo de ayer existe y no está vacío | TI | Diaria (alerta si falta) |
| Prueba de restauración con las verificaciones de arriba | TI | Mensual |
| Revisar quién tiene acceso a los respaldos | TI + administración | Trimestral |
