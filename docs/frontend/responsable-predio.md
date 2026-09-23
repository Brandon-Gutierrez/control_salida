# Responsable de predio: contrato para frontend

Este documento es la referencia compartida para implementar y ampliar en frontend el flujo del rol `PREMISE_MANAGER`. Actualizar la sección **Historial** cada vez que cambie el contrato.

## Objetivo

Al iniciar sesión, una cuenta responsable de predio debe aterrizar directamente en la pantalla de generación de QR del predio que tiene asignado. Esa cuenta no debe mostrar navegación hacia otras áreas ni controles de cierre de sesión.

## Inicio de sesión y redirección

- Usar el flujo existente `POST /api/auth/login` con `{ "username": "...", "password": "..." }`.
- La respuesta mantiene la forma existente `{ status, message, user }`. En `user.role.name` se identifica `PREMISE_MANAGER`; `user.premise` incluye `premise_id` y `name`.
- Cuando `user.role.name` sea `PREMISE_MANAGER`, navegar directamente a la pantalla de generación de QR y mostrar el nombre de `user.premise`.
- El login sigue utilizando el proveedor externo para empleados y administradores. Las cuentas responsables creadas en el sistema se autentican con las credenciales locales entregadas por el administrador.
- El backend mantiene la sesión autenticada y el control de sesión activa existentes.

## Generación del QR

- Solicitar `POST /api/manager/qr-token` usando la misma sesión/cookies que el resto de la API.
- Respuesta exitosa: `{ "status": 0, "token": "<predio>+<uuid>", "TTL": 300 }`.
- Renderizar el QR con el valor exacto de `token`. El token vence a los 300 segundos; solicitar uno nuevo al vencer o cuando se necesite renovar el QR.
- El backend deriva el predio del usuario autenticado. El frontend no envía ni elige `premise_id` para esta operación.
- Si no hay predio asignado o la sesión no autoriza la acción, mostrar el error recibido y volver al login si la sesión ya no es válida.

## Dispositivo autorizado y cambio por Recursos Humanos

- Antes del primer inicio de sesión, la aplicación crea un identificador aleatorio de instalación (UUID v4 o equivalente, al menos 16 caracteres), lo guarda en almacenamiento seguro del dispositivo y lo conserva aunque se cierre sesión.
- Enviar ese valor como encabezado `DeviceId` en `POST /api/auth/login` y en **todas** las llamadas autenticadas (`/api/auth/me`, logout, administración, QR, etc.). No generar uno nuevo al cerrar sesión ni en cada inicio.
- El primer inicio de sesión correcto vincula la cuenta a ese identificador. Otro identificador recibe `403` con código `DEVICE_CHANGE_REQUIRES_HR`; indicar al usuario que contacte a Recursos Humanos.
- Recursos Humanos puede revocar las sesiones y liberar el dispositivo anterior mediante `POST /api/admin/users/{user}/device/reset`. Solo un administrador puede llamar esta ruta y no puede restablecer su propia cuenta. Después, el usuario inicia sesión desde el nuevo dispositivo y queda vinculado automáticamente.
- `GET /api/admin/users` expone `device_bound_at` para que administración vea si hay un dispositivo vinculado y cuándo se vinculó. El identificador original no se devuelve.
- Cerrar sesión no libera el dispositivo vinculado.
- El identificador persistido bloquea cambios accidentales, pero el encabezado se puede copiar o falsificar. El cliente debe guardarlo en Keychain/Keystore; para una mayor resistencia se requiere atestación de plataforma. No debe presentarse como una identidad física imposible de clonar.

## Restricciones de interfaz y API

- No mostrar enlace, botón ni acción de cerrar sesión para `PREMISE_MANAGER`.
- No llamar a `POST /api/auth/logout` desde esta experiencia: el backend responde `403` para este rol.
- El rol puede consultar `GET /api/auth/me` para restaurar el estado de sesión. Después de confirmar `PREMISE_MANAGER`, regresar a la pantalla QR.
- Las APIs de empleado y administración no están disponibles para este rol. Un `403` debe conservar al usuario en la pantalla QR y mostrar un mensaje breve si corresponde.
- Si la sesión se pierde o recibe `401`, el frontend puede volver al login.

## Alta de cuentas (vista de administración)

El backend ofrece `POST /api/admin/users/premise-managers` para administradores. Campos: `name`, `username`, `premise_id` obligatorios; `password` opcional (mínimo 10 caracteres si se envía). Si se omite, la respuesta incluye `generated_password` una sola vez para que administración pueda entregarla de forma segura. La respuesta incluye la cuenta, el rol y el predio asignado.

## Límites de salidas por usuario (panel de administración)

- Consultar la configuración con `GET /api/admin/users/{user}/leave-policy`. Si `data` es `null`, no hay límites configurados.
- `GET /api/admin/users` también incluye el objeto `leave_policy` de cada usuario para mostrar el resumen junto a la lista.
- Guardar con `PUT /api/admin/users/{user}/leave-policy` enviando las dos cuotas y el período elegido:

```json
{
  "period": "week",
  "max_exits": 5,
  "max_exits_per_premise": 2
}
```

- `period` acepta `day`, `week` o `month` y se comparte entre ambas cuotas. Los períodos usan el calendario local del servidor: día calendario, semana de lunes a domingo, o mes calendario.
- `max_exits` limita el total de salidas registradas en el período. `max_exits_per_premise` limita las salidas al mismo predio en ese período. Las cuotas son independientes y pueden configurarse juntas. Enviar `null` para desactivar una cuota; enviar ambas como `null` deja al usuario sin límites.
- Los dos campos deben estar presentes en cada actualización; usar `null` para el límite que no se quiera aplicar.
- Se cuentan las salidas confirmadas en el registro local, no los retornos. La cuota por predio se calcula usando el predio real asociado al QR.
- Cuando se alcanza un límite, `POST /api/leaves` responde `403` con `code: LEAVE_LIMIT_REACHED`, el límite alcanzado (`limit_type` en el backend), la cantidad permitida y la cantidad usada. Mostrar el mensaje y no repetir el registro hasta que comience el siguiente período o administración actualice la política.

## Ubicación del usuario para escaneo y salidas/retornos

No se requieren WebSockets. El backend valida la ubicación en cada `POST /api/qr/scan` y `POST /api/leaves`; la aplicación debe obtener una ubicación reciente y enviarla en ambas solicitudes:

```json
{
  "latitude": -16.5001,
  "longitude": -68.1502,
  "accuracy_m": 8.5,
  "location_timestamp": "2026-09-23T14:30:00Z"
}
```

- Conservar y enviar también los campos de negocio que ya exige cada ruta (`qrData` para escanear; `qrData`, `namePremise` y `nameReason` para registrar una salida).
- La API autoriza la operación solamente si la lectura es reciente (hasta 120 segundos), su precisión reportada permite comprobar el radio, y la posición está a 50 metros o menos de algún predio con coordenadas. La precisión (`accuracy_m`) se suma a la distancia, por lo que una lectura incierta puede requerir una nueva captura.
- La validación se aplica tanto al escaneo de QR (incluido el retorno) como al registro de salida. La validación existente que compara el predio del QR de retorno con el predio de salida sigue funcionando por separado.
- Si la API responde `422`, actualizar la lectura y volver a enviar los datos. Si responde `403`, mostrar el mensaje recibido y no completar el flujo.
- El permiso de ubicación del sistema debe solicitarse al usuario y la aplicación debe capturar una lectura nueva al escanear y al confirmar la salida.

### Ubicación de predios en el panel de administración

- `POST /api/admin/premises` ahora requiere `name`, `latitude` y `longitude`; `reasons` sigue siendo opcional.
- `PUT /api/admin/premises/{premise}` actualiza `name` y/o el par `latitude` + `longitude`. Las coordenadas admiten latitud entre `-90` y `90` y longitud entre `-180` y `180`.
- `GET /api/admin/premises` devuelve `latitude` y `longitude` junto a cada predio. En el formulario, incluir un mapa o selector que guarde las coordenadas elegidas.

### Límites frente a falsificación

La distancia se calcula en el servidor y no se confía en un `premise_id` enviado por la aplicación. Sin embargo, las coordenadas, la precisión y la marca de tiempo las entrega el dispositivo: un cliente modificado puede falsificarlas. La geolocalización web/móvil por sí sola no permite garantizar que no se use GPS simulado ni demostrar que el cliente no corre en una VPS. La detección de mock location y la atestación de la aplicación (por ejemplo, Play Integrity en Android o App Attest en iOS) requieren trabajo adicional en la aplicación y verificación de sus pruebas firmadas en backend; aun así elevan la dificultad, no dan una garantía absoluta. La verificación de IP tampoco confirma ubicación física.

## Historial

- 2026-09-23: contrato inicial. Cuenta local con rol `PREMISE_MANAGER`, un predio asignado, generación de QR limitada a ese predio y sin cierre de sesión desde la experiencia del responsable.
- 2026-09-23: panel admin captura coordenadas de cada predio; escaneo y registro de salida exigen ubicación reciente a 50 m o menos de algún predio. Sin WebSockets. La ubicación reportada no prueba por sí sola que no haya GPS simulado.

- 2026-09-23: cada cuenta queda vinculada al primer `DeviceId` enviado al iniciar sesión; cerrar sesión no libera el vínculo. Recursos Humanos revoca sesiones y habilita un dispositivo nuevo con `POST /api/admin/users/{user}/device/reset`.
- 2026-09-23: panel admin configura límites de salidas totales y/o al mismo predio por día, semana o mes mediante `/api/admin/users/{user}/leave-policy`; el backend los valida antes de registrar la salida.

### Plantilla para futuras actualizaciones

- `AAAA-MM-DD`: qué cambió, endpoints/campos afectados y cualquier ajuste requerido en la pantalla.
