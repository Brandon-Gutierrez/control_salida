# Validación de ubicación en escaneos

Los endpoints `POST /api/qr/scan` y `POST /api/leaves` (rol EMPLOYEE) pasan por el middleware
`premise.location` (`app/Http/Middleware/EnsurePremiseLocation.php`).

## Campos obligatorios que envía la app móvil

| Campo | Regla |
|---|---|
| `latitude`, `longitude` | rango válido |
| `accuracy_m` | mayor que 0 y máximo 50 |
| `location_timestamp` | máximo 120 s de antigüedad y 15 s en el futuro |
| `is_mocked` | booleano; `true` = GPS falso (Android `Position.isMocked`) → 403 `LOCATION_SPOOFING_DETECTED` |
| `vpn_detected` | booleano; `true` = VPN activa (`connectivity_plus`) → 403 `LOCATION_SPOOFING_DETECTED` |

La posición se acepta solo si `distancia_al_predio + accuracy_m <= 50 m` respecto de algún predio
con coordenadas. Un predio sin coordenadas no acepta escaneos. La validación "predio de retorno =
predio de salida" sigue vigente y es independiente.

## Límite de la detección

`is_mocked` y `vpn_detected` los reporta el dispositivo. Detectan el GPS falso y la VPN comunes,
pero un teléfono con root o una app modificada podría mentir. El servidor complementa con precisión
> 0, vigencia de la marca de tiempo y el radio de 50 m.

## Administración de predios (panel web)

`POST /api/admin/premises` y `PUT /api/admin/premises/{id}` aceptan `name`, `latitude`, `longitude`,
`reasons[]` y `manager_user_id` (usuario MANAGE_PREMISE o `null`). El responsable anterior queda sin
predio y sus sesiones se cierran. La respuesta incluye `latitude`, `longitude` y `manager`.

El mapa usa Google Maps: reemplaza `TU_API_KEY` en `control_leaves_web/web/index.html`. Posición por
defecto: El Prado, Cochabamba (-17.3935, -66.1570).
