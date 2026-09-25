# Responsable de predio: contrato para frontend

Este documento es la referencia compartida para implementar y ampliar en frontend el flujo del rol `MANAGE_PREMISE`. Actualizar la sección **Historial** cada vez que cambie el contrato.

## Objetivo

Al iniciar sesión, una cuenta responsable de predio debe aterrizar directamente en la pantalla de generación de QR del predio que tiene asignado. Esa cuenta no debe mostrar navegación hacia otras áreas ni controles de cierre de sesión.

## Inicio de sesión y redirección

- Usar el flujo existente `POST /api/auth/login` con `{ "username": "...", "password": "..." }`.
- La respuesta mantiene la forma existente `{ status, message, user }`. En `user.role.name` se identifica `MANAGE_PREMISE`; `user.premise` incluye `premise_id` y `name`.
- Cuando `user.role.name` sea `MANAGE_PREMISE`, navegar directamente a la pantalla de generación de QR y mostrar el nombre de `user.premise`.
- El login sigue utilizando el proveedor externo para empleados y administradores. Las cuentas responsables creadas en el sistema se autentican con las credenciales locales entregadas por el administrador.
- El backend mantiene la sesión autenticada y el control de sesión activa existentes.

## Generación del QR

- Solicitar `POST /api/manager/qr-token` usando la misma sesión/cookies que el resto de la API.
- Respuesta exitosa: `{ "status": 0, "token": "<predio>+<uuid>", "TTL": <segundos_visibles_restantes>, "expires_at": "<ISO-8601>" }`.
- Renderizar el QR con el valor exacto de `token`. `TTL` y `expires_at` son el período visible de 300 segundos; usar `expires_at` para la cuenta regresiva y renovar el QR antes de esa hora. No calcular el vencimiento con el reloj local del navegador.
- El backend conserva el token 30 segundos adicionales después de `expires_at` para absorber retrasos de escaneo/red. Esta gracia es interna: no mostrarla en la interfaz ni extender la cuenta regresiva visible.
- El backend deriva el predio del usuario autenticado. El frontend no envía ni elige `premise_id` para esta operación.
- Si no hay predio asignado o la sesión no autoriza la acción, mostrar el error recibido y volver al login si la sesión ya no es válida.

## Dispositivo autorizado y cambio por Recursos Humanos

- Para las cuentas `EMPLOYEE` de la aplicación móvil, antes del primer inicio de sesión la aplicación crea un identificador aleatorio de instalación (UUID v4 o equivalente, al menos 16 caracteres), lo guarda en almacenamiento seguro del dispositivo y lo conserva aunque se cierre sesión.
- Enviar ese valor como encabezado `DeviceId` en el login móvil y en las llamadas autenticadas de la aplicación móvil. No generar uno nuevo al cerrar sesión ni en cada inicio. El panel web de `ADMIN` y `MANAGE_PREMISE` no debe enviar ni requiere `DeviceId`.
- El primer inicio de sesión móvil correcto vincula la cuenta `EMPLOYEE` a ese identificador. Otro identificador recibe `403` con código `DEVICE_CHANGE_REQUIRES_HR`; indicar al usuario que contacte a Recursos Humanos.
- Recursos Humanos puede revocar las sesiones y liberar el dispositivo anterior mediante `POST /api/admin/users/{user}/device/reset`. Solo un administrador puede llamar esta ruta y no puede restablecer su propia cuenta. Después, el usuario inicia sesión desde el nuevo dispositivo y queda vinculado automáticamente.
- `GET /api/admin/users` expone `device_bound_at` para que administración vea si una cuenta móvil tiene un dispositivo vinculado y cuándo se vinculó. El identificador original no se devuelve.
- Cerrar sesión no libera el dispositivo vinculado.
- El identificador persistido bloquea cambios accidentales, pero el encabezado se puede copiar o falsificar. El cliente debe guardarlo en Keychain/Keystore; para una mayor resistencia se requiere atestación de plataforma. No debe presentarse como una identidad física imposible de clonar.

## Restricciones de interfaz y API

- No mostrar enlace, botón ni acción de cerrar sesión para `MANAGE_PREMISE`.
- No llamar a `POST /api/auth/logout` desde esta experiencia: el backend responde `403` para este rol.
- El rol puede consultar `GET /api/auth/me` para restaurar el estado de sesión. Después de confirmar `MANAGE_PREMISE`, regresar a la pantalla QR.
- Las APIs de empleado y administración no están disponibles para este rol. Un `403` debe conservar al usuario en la pantalla QR y mostrar un mensaje breve si corresponde.
- Si la sesión se pierde o recibe `401`, el frontend puede volver al login.

## Alta de cuentas (vista de administración)

El backend ofrece `POST /api/admin/users/premise-managers` para administradores. Campos: `name`, `username`, `premise_id` obligatorios; `password` opcional (mínimo 10 caracteres si se envía). Si se omite, la respuesta incluye `generated_password` una sola vez para que administración pueda entregarla de forma segura. La respuesta incluye la cuenta, el rol y el predio asignado.

### Asignar o quitar el rol MANAGE_PREMISE a una cuenta existente

- `PUT /api/admin/users/{user}/role` acepta `{ "role_id": <id>, "premise_id": <id opcional> }`. Para el rol `MANAGE_PREMISE` el predio es obligatorio (se envía aquí o ya debe estar asignado); enviar rol y predio en **una sola** petición. Al salir de ese rol, el predio se desasigna.
- `PUT /api/admin/users/{user}/premise` con `{ "premise_id": <id> }` cambia el predio de un responsable existente. No cierra su sesión: el QR siempre se genera con el predio que el servidor tiene asignado.
- Cambiar el rol de una cuenta **cierra todas sus sesiones** para que vuelva a entrar con los permisos nuevos.
- Un administrador no puede cambiar su propio rol (`422`). Las cuentas locales de responsable (creadas con usuario y contraseña) solo pueden tener el rol `MANAGE_PREMISE` (`422` si se intenta otro).
- `GET /api/admin/users` incluye `premise` (`premise_id`, `name`) de cada usuario.
- Un usuario con rol `MANAGE_PREMISE` **sin predio** no puede iniciar sesión: `POST /api/auth/login` responde `403` con `code: PREMISE_NOT_ASSIGNED`.

## Bloqueo de la experiencia web del responsable

- La raíz de la app (`MaterialApp.builder`) muestra solo la pantalla de QR mientras la sesión sea `MANAGE_PREMISE`: no hay navegación, cierre de sesión ni acceso por ruta/enlace, y el botón "atrás" del navegador no lo saca de la página. Ningún cambio de ruta o de hash cambia lo que ve.
- Si `GET /api/auth/me` o el QR responden `401`, la sesión se limpia y vuelve el inicio de sesión. Un `5xx`/error de red en el QR se reintenta solo cada 10 s.
- `POST /api/manager/qr-token` devuelve también `premise` (`premise_id`, `name`); la pantalla usa ese nombre, así que refleja una reasignación sin recargar.
- Un sitio web no puede impedir cerrar la pestaña ni escribir otra dirección en la barra del navegador: para un puesto fijo usar el modo quiosco del navegador (p. ej. `chrome --kiosk`). El servidor es quien garantiza el aislamiento: con este rol solo responden `/api/auth/me` y `/api/manager/qr-token` (todo lo demás `403`; `POST /api/auth/logout` → `403 LOGOUT_NOT_ALLOWED`).

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

## Confirmación y errores del QR

- Al escanear y recibir `action: "showReasons"`, guardar `leaveTicket` y enviarlo en `POST /api/leaves` junto con `namePremise` y `nameReason`. El campo de respuesta `qrData` se conserva como alias temporal para clientes anteriores; ambos contienen el ticket de confirmación, no el QR original.
- El ticket se crea al validar el QR y tiene su propia expiración de 300 segundos desde el escaneo. Esto permite terminar la selección del motivo aunque el QR original expire durante ese paso. Si vence el ticket, volver a escanear un QR vigente.
- `410` con `QR_EXPIRED_OR_INVALID` significa que el QR ya venció al iniciar el escaneo; solicitar/mostrar un QR vigente y escanear nuevamente. `410` con `LEAVE_TICKET_EXPIRED` significa que venció el ticket de confirmación; escanear de nuevo.
- `502` o `503` con `retryable: true` indica una falla temporal de un servicio externo o Redis; mostrar que es temporal y permitir reintentar. No mostrarlo como “QR vencido”.
- Si la respuesta indica `retryable: false` al registrar una salida/retorno, el servicio externo pudo haber procesado la solicitud sin que el backend recibiera confirmación. Consultar el estado antes de volver a registrar para evitar duplicados.
- `403` con `LEAVE_LIMIT_REACHED` corresponde a una cuota de salida alcanzada, no a un error del QR.
- Los errores incluyen `code` estable para que la interfaz use mensajes/acciones adecuados; el texto `message` puede mostrarse directamente.

### Ubicación de predios en el panel de administración

- `POST /api/admin/premises` ahora requiere `name`, `latitude` y `longitude`; `reasons` sigue siendo opcional.
- `PUT /api/admin/premises/{premise}` actualiza `name` y/o el par `latitude` + `longitude`. Las coordenadas admiten latitud entre `-90` y `90` y longitud entre `-180` y `180`.
- `GET /api/admin/premises` devuelve `latitude` y `longitude` junto a cada predio. En el formulario, incluir un mapa o selector que guarde las coordenadas elegidas.

### Límites frente a falsificación

La distancia se calcula en el servidor y no se confía en un `premise_id` enviado por la aplicación. Sin embargo, las coordenadas, la precisión y la marca de tiempo las entrega el dispositivo: un cliente modificado puede falsificarlas. La geolocalización web/móvil por sí sola no permite garantizar que no se use GPS simulado ni demostrar que el cliente no corre en una VPS. La detección de mock location y la atestación de la aplicación (por ejemplo, Play Integrity en Android o App Attest en iOS) requieren trabajo adicional en la aplicación y verificación de sus pruebas firmadas en backend; aun así elevan la dificultad, no dan una garantía absoluta. La verificación de IP tampoco confirma ubicación física.

## Historial

- 2026-09-23: contrato inicial. Cuenta local con rol `MANAGE_PREMISE`, un predio asignado, generación de QR limitada a ese predio y sin cierre de sesión desde la experiencia del responsable.
- 2026-09-23: panel admin captura coordenadas de cada predio; escaneo y registro de salida exigen ubicación reciente a 50 m o menos de algún predio. Sin WebSockets. La ubicación reportada no prueba por sí sola que no haya GPS simulado.

- 2026-09-23: cada cuenta `EMPLOYEE` móvil queda vinculada al primer `DeviceId` enviado al iniciar sesión; cerrar sesión no libera el vínculo. Recursos Humanos revoca sesiones y habilita un dispositivo nuevo con `POST /api/admin/users/{user}/device/reset`.
- 2026-09-24: el bloqueo `DeviceId` solo aplica al rol móvil `EMPLOYEE`; `ADMIN` y `MANAGE_PREMISE` pueden iniciar sesión desde la versión web sin ese encabezado.
- 2026-09-23: panel admin configura límites de salidas totales y/o al mismo predio por día, semana o mes mediante `/api/admin/users/{user}/leave-policy`; el backend los valida antes de registrar la salida.
- 2026-09-23: expiración QR devuelta desde Redis con `expires_at`; errores de QR, Redis y APIs externas ahora tienen códigos separados. El escaneo genera un ticket de confirmación de salida de 300 segundos para completar el motivo después del escaneo.
- 2026-09-24: el QR conserva una gracia interna de 30 segundos después de su expiración visible; `TTL` y `expires_at` siguen mostrando únicamente el período visible.

- 2026-09-24: el rol pasa a llamarse `MANAGE_PREMISE` (migración `2026_09_24_100000`; antes `PREMISE_MANAGER`). Nuevo `PUT /api/admin/users/{user}/premise`; `PUT /users/{user}/role` acepta `premise_id` y lo exige para este rol; `GET /api/admin/users` incluye `premise`; login sin predio → `403 PREMISE_NOT_ASSIGNED`; cambiar el rol cierra las sesiones; un admin no puede cambiar su propio rol; `POST /api/manager/qr-token` devuelve `premise`. Web: alta de responsables desde el panel de administración, y la experiencia del responsable queda bloqueada a su pantalla de QR (sin navegación, cierre de sesión ni botón "atrás").

### Plantilla para futuras actualizaciones

- `AAAA-MM-DD`: qué cambió, endpoints/campos afectados y cualquier ajuste requerido en la pantalla.
