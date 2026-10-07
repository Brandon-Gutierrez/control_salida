# Mapa técnico del repositorio

Este documento describe los archivos versionados del proyecto, su responsabilidad y sus relaciones. Se basa en el código y la configuración actuales. Los archivos generados/locales (`vendor/`, `node_modules/`, `.env`, cachés y logs) no se versionan y, por tanto, no se catalogan individualmente.

## 1. Vista general

Aplicación Laravel con una API para autenticar usuarios y registrar salidas temporales de personal. También administra predios, motivos de salida, responsables, políticas/cuotas, dispositivos autorizados y parámetros del QR. El cliente web/móvil consume rutas API; Vite compila los recursos de frontend.

Flujo principal: `routes/api.php` aplica autenticación y middleware; los controladores validan/coordina casos de uso; servicios resuelven reglas de negocio o integraciones; repositorios encapsulan algunas consultas; modelos Eloquent representan tablas y relaciones; migraciones definen el esquema. `docs/` contiene requisitos operativos y pruebas en `tests/` documentan escenarios.

## 2. Raíz del proyecto

| Archivo | Qué hace y con qué se relaciona | Mejora recomendada |
|---|---|---|
| `.editorconfig` | Normaliza formato de archivos entre editores. | Añadir reglas por lenguaje y alinearlo con formatter/linter automatizados. |
| `.env.example` | Plantilla de variables de entorno para configurar Laravel e integraciones. | Documentar cada variable no obvia, usar valores seguros y validar variables requeridas al iniciar. Nunca copiar secretos reales. |
| `.gitattributes` | Configura atributos Git, habitualmente normalización de saltos de línea. | Declarar explícitamente reglas de fin de línea y tratar binarios si el repositorio incorpora más activos. |
| `.gitignore` | Excluye dependencias, secretos, cachés y archivos locales. | Revisar que excluya artefactos de build y nunca ignore archivos necesarios para reproducir despliegues. |
| `README.md` | Actualmente es el README de plantilla de Laravel; no describe el producto. | Sustituirlo por guía del sistema: propósito, requisitos, instalación, variables, comandos, API, arquitectura, operación y soporte. |
| `artisan` | Entrada CLI de Laravel para migraciones, colas, comandos y tareas de framework. | Mantener comandos propios pequeños y delegar lógica a servicios/casos de uso. |
| `composer.json` | Declara paquetes PHP, autoload y scripts Composer. | Fijar convenciones de calidad (Pint/PHPStan u otra herramienta), scripts CI y límites de versiones explícitos. |
| `composer.lock` | Fija versiones exactas de dependencias PHP para instalaciones reproducibles. | Actualizar con revisión de cambios y auditoría de vulnerabilidades en CI. No editar manualmente. |
| `package.json` | Declara herramientas/scripts JS, incluidos Vite y compilación de recursos. | Definir scripts consistentes de build/lint y mantener dependencias de desarrollo mínimas. |
| `package-lock.json` | Fija el árbol de dependencias npm. | Actualizar junto con `package.json` y validar build en CI. |
| `phpunit.xml` | Configura PHPUnit y el entorno de pruebas Laravel. | Mantener base de datos de pruebas aislada y añadir cobertura/reportes en CI. |
| `vite.config.js` | Configura Vite/Laravel Vite Plugin para CSS/JS. | Separar entradas por aplicación si crece el frontend y validar build en CI. |

## 3. Aplicación (`app/`)

Capas, de afuera hacia adentro: **middleware** (acceso) → **Form Request** (validación) → **controlador** (solo traduce HTTP) → **servicio** (reglas y casos de uso) → **repositorio** (consultas) → **modelo**. Los errores esperados (regla incumplida, servicio externo caído) se lanzan como `ApiException` y Laravel los responde en JSON; los controladores solo describen el camino feliz.

### Comandos, excepciones y soporte

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `app/Console/Commands/ResetUserDevice.php` | `php artisan devices:reset`: desvincula el dispositivo de una cuenta (para TI); usa `UserRepository` y `DeviceBindingService`. | Auditar quién/cuándo ejecutó el reset. |
| `app/Exceptions/ApiException.php` | Error esperado de la API con su cuerpo JSON y código HTTP. Dos formatos heredados de los clientes: numérico (`status: 1`) y textual (`status: "ERROR"`). Registrada en `bootstrap/app.php` para no reportarse como fallo. | Unificar ambos formatos en una versión nueva de la API. |
| `app/Support/ClientPlatform.php` | Plataformas (`web`, `mobile`), encabezado/clave de sesión, qué roles entran a cada una y sus mensajes. Usada por autenticación y middleware. | Convertir a enum. |
| `app/Support/Geo.php` | Distancia en metros entre dos coordenadas (haversine). La usa `EnsurePremiseLocation`. | — |

### Controladores HTTP (`app/Http/Controllers/`)

Todos son delgados: validan con un Form Request, llaman a un servicio/repositorio y arman la respuesta.

| Archivo | Qué hace y relaciones |
|---|---|
| `AuthController.php` | Login (`AuthService` + apertura de la sesión), `me` y logout. |
| `LeaveController.php` | `store`: confirma la salida (`LeaveRegistrationService`). |
| `LeaveStatusController.php` / `LeaveStatsController.php` | Estado de salida y estadísticas de la persona autenticada (invocables). |
| `LeaveLimitController.php` | Lee/actualiza el límite general de salidas (`LeaveLimitService`). |
| `QrController.php` | Genera el token del QR de un predio: administración (`store`) o el responsable (`storeForManager`). |
| `QrScanController.php` | Escaneo de un QR (`QrScanService`), invocable. |
| `QrSettingsController.php` | Lee/actualiza el tiempo de vida del QR (`QrSettingsService`). |
| `PremiseController.php` | Listado, alta y edición de predios (con responsable y motivos). |
| `PremiseReasonController.php` | Motivos de un predio: consulta (app móvil) y reemplazo (administración). |
| `PremiseManagerController.php` | Alta de cuentas locales de responsable de predio. |
| `ReasonController.php` | Catálogo de motivos y su sincronización con el sistema externo. |
| `RoleController.php` | Catálogo de roles. |
| `UserController.php` | Listado de cuentas, cambio de rol, predio y contraseña, y desvinculación de dispositivo. |

### Form Requests y Resources

`app/Http/Requests/` contiene una clase por endpoint con sus reglas de validación (`LoginRequest`, `ScanQrRequest`, `StoreLeaveRequest`, `StorePremiseRequest`, `UpdatePremiseRequest`, `UpdatePremiseReasonsRequest`, `UpdateUserRoleRequest`, `AssignUserPremiseRequest`, `ResetUserDeviceRequest`, `CreatePremiseManagerRequest`, `UpdateUserPasswordRequest`, `UpdateQrSettingsRequest`, `UpdateLeaveLimitsRequest`). `app/Http/Resources/PremiseResource.php` da formato al predio con su responsable y motivos.

### Middleware (`app/Http/Middleware/`)

| Archivo | Alias | Qué hace |
|---|---|---|
| `EnsureActiveSession.php` | `active.session` | La sesión debe seguir registrada como activa; actualiza su actividad. |
| `EnsureDeviceIsBound.php` | `device.bound` | Plataforma de la sesión, rol permitido en ella y dispositivo vinculado (`DeviceBindingService`). |
| `EnsureClientPlatform.php` | `platform:web` / `platform:mobile` | Restringe la ruta a una aplicación. |
| `EnsureUserHasRole.php` | `role:ADMIN,…` | Restringe la ruta a ciertos roles. |
| `EnsurePremiseLocation.php` | `premise.location` | Ubicación a menos de 50 m de un predio, reciente y sin GPS simulado/VPN (ver `docs/validacion-ubicacion.md`). |

### Modelos Eloquent (`app/Models/`)

| Archivo | Qué representa |
|---|---|
| `User.php` | Persona (empleado, administrador o responsable de predio). Scope `premiseManagers()`; relaciones con rol, predio y dispositivos. |
| `Role.php` | Rol y constantes `EMPLOYEE`, `ADMIN`, `MANAGE_PREMISE`. |
| `Premise.php` | Predio, con sus motivos (`reasons()`) y responsables (`managers()`). |
| `LeaveReason.php` | Motivo de salida del catálogo (tabla `reasons`). |
| `ReasonPremise.php` | Motivo habilitado en un predio (tabla pivote `reason_premise`). |
| `Record.php` | Salida de una persona y su retorno (`records`). |
| `Setting.php` | Configuración global clave-valor (tiempo de vida del QR, límites de salidas). |
| `UserDevice.php` | Dispositivo autorizado por cuenta y aplicación (guarda solo el hash). |
| `UserActiveSession.php` | Sesión activa de una cuenta en una aplicación. |

### Servicios (`app/Services/`)

| Carpeta / archivo | Responsabilidad |
|---|---|
| `Auth/AuthService.php` | Reglas de inicio de sesión: aplicación, dispositivo, credenciales locales o externas, rol, predio. |
| `Auth/DeviceBindingService.php` | Un dispositivo por cuenta y aplicación: vincular, verificar, desvincular. |
| `Account/UserRoleService.php` | Cambio de rol de una cuenta. |
| `Premise/PremiseManagerService.php` | Responsables de predio: asignar, mover, crear cuentas locales, cambiar contraseña. |
| `External/ExternalApiService.php` | Única puerta al sistema externo de personal (cabecera, URLs desde `config/services.php`, tiempos de espera). |
| `External/ExternalAuthService.php` | Autentica credenciales contra el sistema externo. |
| `External/EmployeeIdentityService.php` | Confirma que la cuenta es un empleado vigente. |
| `External/EmployeeProfileService.php` | Foto y cargo (informativos, con caché). |
| `Leave/LeaveRegistrationService.php` | Confirma una salida: comprobante, predio, motivo, límite, registro externo y local. |
| `Leave/LeaveTicketService.php` | Comprobante temporal (Redis) que une un escaneo con su confirmación. |
| `Leave/LeaveLimitService.php` | Límite general de salidas por período. |
| `Leave/LeaveStatsService.php` | Salidas y minutos fuera del día, la semana y el mes. |
| `Leave/LeaveStatusService.php` | Estado de salida para la pantalla principal de la app móvil. |
| `Leave/LeaveReasonSyncService.php` | Sincroniza el catálogo de motivos con el sistema externo. |
| `Qr/QrTokenService.php` | Emite los tokens temporales (Redis) del QR de cada predio. |
| `Qr/QrScanService.php` | Escaneo: inicia la salida o registra el retorno. |
| `Qr/QrSettingsService.php` | Tiempo de vida del QR configurable. |

### Repositorios (`app/Repositories/`)

`UserRepository`, `RoleRepository`, `PremiseRepository`, `LeaveReasonRepository`, `ReasonPremiseRepository`, `RecordRepository` y `UserActiveSessionRepository` encapsulan las consultas Eloquent; cada método vive en el repositorio de la entidad que consulta.

### Proveedores

| Archivo | Qué hace y relaciones |
|---|---|
| `app/Providers/AppServiceProvider.php` | Proveedor vacío (no hay bindings propios todavía). |

## 4. Arranque y configuración

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `bootstrap/app.php` | Construye la aplicación Laravel y registra rutas, middleware y manejo de excepciones. | Mantener alias/orden de middleware documentados y probar respuestas JSON de errores. |
| `bootstrap/providers.php` | Lista proveedores de servicios cargados al arrancar. | Añadir proveedores propios solo si organizan bindings/eventos relevantes. |
| `bootstrap/cache/.gitignore` | Evita versionar cachés de bootstrap. | Conservar; generar cachés durante despliegue reproducible. |
| `config/app.php` | Nombre, entorno, zona horaria, locale, cifrado y parámetros generales. | Configurar zona horaria/locale de negocio conscientemente y no almacenar secretos fuera del entorno. |
| `config/auth.php` | Guards, proveedores y modelo de autenticación. Se relaciona con `User` y Sanctum. | Revisar guard usado por rutas de sesión y documentar el modelo/clave primaria no convencional. |
| `config/broadcasting.php` | Conexiones para difusión de eventos. Hoy ningún código emite eventos ni usa Reverb. | Retirar junto con `config/reverb.php` y el paquete `laravel/reverb` (`composer remove laravel/reverb`) si no habrá tiempo real. |
| `config/cache.php` | Almacenes y prefijo de caché Laravel. | Elegir backend por entorno y planificar invalidación/aislamiento entre despliegues. |
| `config/cors.php` | Orígenes y métodos permitidos para clientes web. | Restringir orígenes de producción al mínimo y documentar dominios por ambiente. |
| `config/database.php` | Conexiones y opciones de base de datos. | Usar índices/constraints en migraciones y parámetros seguros de producción; monitorear conexiones. |
| `config/filesystems.php` | Discos locales y remotos para archivos. | Definir permisos/visibilidad y almacenamiento privado por defecto para datos sensibles. |
| `config/logging.php` | Canales y formato de logs. | Estructurar contexto y redactar credenciales, tokens, coordenadas e identificadores sensibles. |
| `config/mail.php` | Transporte y remitente de correo. | Configurar por entorno y probar fallos/entrega si se habilitan notificaciones. |
| `config/queue.php` | Conexiones y comportamiento de colas. | Usar trabajos para sincronizaciones externas lentas y definir reintentos/idempotencia si aplica. |
| `config/reverb.php` | Configuración del servidor de broadcasting Reverb. | Documentar su uso real, autenticación de canales y secretos por ambiente. |
| `config/sanctum.php` | Dominios stateful, autenticación por cookies y expiración Sanctum. | Alinear dominios/cookies con frontend y validar CSRF/sesiones en despliegue. |
| `config/services.php` | Credenciales y endpoints del sistema externo de personal (`external_api`), leídos con `config()` desde `ExternalApiService`. | Mantener aquí toda configuración de integraciones; nunca `env()` fuera de `config/`. |
| `config/session.php` | Driver, duración, cookie y almacenamiento de sesiones. | Usar cookie segura/HTTP-only/SameSite correcto en producción y alinear expiración con sesiones activas. |

## 5. Base de datos (`database/`)

### Fábricas y seeders

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `database/.gitignore` | Mantiene archivos locales de base de datos fuera del control de versiones. | Conservar y no incluir bases con datos reales. |
| `database/factories/UserFactory.php` | Genera usuarios de prueba para tests/seeding. | Completar estados (roles, predio, credenciales externas) reutilizables y mantener datos ficticios. |
| `database/seeders/DatabaseSeeder.php` | Punto de entrada para poblar datos iniciales/de desarrollo. | Separar catálogos de datos demo y asegurar idempotencia; no crear credenciales de producción predecibles. |

### Migraciones

Las migraciones son la historia versionada del esquema; deben ser aditivas y conservarse después de aplicarse en ambientes compartidos.

| Archivo | Cambio de esquema | Relación/mejora |
|---|---|---|
| `2026_08_03_081435_create_rol_table.php` | Crea el catálogo de roles (`roles`). | Base para autorización; agregar unicidad del nombre y seed controlado de roles. |
| `2026_08_18_122355_create_users_table.php` | Crea usuarios e identidad/atributos principales. | Referenciada por sesiones, dispositivos, registros y políticas; reforzar índices/constraints según consultas e identidad. |
| `2026_08_18_123233_create_reasons_table.php` | Crea catálogo de motivos de salida. | Relacionado con `reason_premise`; garantizar unicidad apropiada de códigos externos. |
| `2026_08_28_092843_create_premises_table.php` | Crea predios. | Punto geográfico y asociación a responsables; definir precisión/rangos y estrategia espacial si crece el volumen. |
| `2026_08_28_093522_create_reason_premise_table.php` | Crea pivote entre motivos y predios. | Evitar duplicados con clave única compuesta y mantener claves foráneas. |
| `2026_08_28_094032_create_record_table.php` | Crea registros de salida/retorno enlazados a combinación motivo-predio y usuario. | Añadir índices para salidas abiertas e invariantes de integridad/transacciones. |
| `2026_09_11_090806_create_personal_access_tokens_table.php` | Crea tokens personales de Sanctum. | Aplicación usa autenticación stateful en partes del flujo; proteger expiración y limpieza. |
| `2026_09_11_100921_create_user_active_sessions_table.php` | Crea registro de sesiones activas y metadatos. | Añadir índices por usuario, plataforma y sesión; limitar retención. |
| `2026_09_15_101429_create_sessions_table.php` | Crea almacenamiento de sesiones Laravel en base de datos. | Complementa autenticación por cookie; alinear limpieza y expiración con `user_active_sessions`. |
| `2026_09_23_120000_add_premise_manager_accounts.php` | Agrega soporte para cuentas responsables y asignación a predio/credenciales locales. | Asegurar constraints e historial de cambios de rol/asignación. |
| `2026_09_23_130000_add_coordinates_to_premises.php` | Añade coordenadas para validar proximidad a predios. | Validar precisión/rangos y considerar índice espacial según escala. |
| `2026_09_23_140000_add_persistent_device_binding_to_users.php` | Introduce campos de vinculación persistente de dispositivo en usuarios. | Evolucionó con la tabla `user_devices`; documentar compatibilidad y plan de retirar columnas obsoletas si existen. |
| `2026_09_23_150000_create_user_leave_policies_table.php` | Crea políticas/cuotas de salida por usuario. | Definir unicidad, checks de límites e índices por usuario/periodo. |
| `2026_09_24_100000_rename_premise_manager_role_to_manage_premise.php` | Renombra el rol responsable al identificador `MANAGE_PREMISE`. | Mantener constantes, datos iniciales y pruebas sincronizados. |
| `2026_09_28_090000_create_settings_table.php` | Crea configuración global clave-valor. | Limitar claves válidas y registrar auditoría cuando afecten operación/seguridad. |
| `2026_09_30_090000_create_user_devices_table.php` | Crea tabla de dispositivos autorizados por usuario/plataforma. | Restricción única por usuario/plataforma e índices; verificar transición desde campos previos. |

## 6. Rutas y recursos públicos

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `routes/api.php` | Define health, login/logout, flujo móvil de salida, responsable de predio y administración; asigna middleware (`active.session`, `device.bound`, `platform`, `role`, `premise.location`) y nombres de ruta. | Versionar API, documentar OpenAPI y revisar consistencia de códigos HTTP/respuestas. |
| `routes/web.php` | Define la ruta web `/` que muestra `welcome`. | Agregar rutas web solo si existe interfaz servida por Laravel. |
| `public/index.php` | Front controller HTTP: carga bootstrap y despacha la petición. | Conservar como archivo de framework; configuración del servidor debe apuntar a `public/`. |
| `public/.htaccess` | Reglas Apache para reescritura hacia el front controller y protección de directorios. | Mantener sincronizado con configuración de producción; forzar HTTPS a nivel de proxy/servidor. |
| `public/robots.txt` | Directivas de indexación para robots. | Confirmar que el entorno no expone contenido privado; no usarlo como control de acceso. |
| `public/favicon.ico` | Icono del sitio. | Mantener formato/tamaño optimizados y actualizar junto a identidad visual. |
| `resources/views/welcome.blade.php` | Vista de bienvenida predeterminada Laravel para `/`. | Sustituir por portada/estado del producto o eliminar si la raíz no debe servir una vista. |

## 7. Frontend (`resources/`)

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `resources/css/app.css` | Hoja de estilos de entrada del frontend compilada por Vite. | Definir tokens y convenciones; eliminar estilos no usados y aplicar accesibilidad visual. |
| `resources/js/app.js` | Entrada principal JavaScript del cliente. | Organizar por módulos/funcionalidades y definir manejo centralizado de errores de API. |
| `resources/js/bootstrap.js` | Inicialización común de librerías/configuración JS. | Mantener solo setup compartido y evitar efectos secundarios implícitos. |
| `resources/js/echo.js` | Configura Laravel Echo/broadcasting para eventos en tiempo real. | Activar solo cuando necesario; validar autenticación, reconexión y manejo de errores. |

## 8. Archivos de control de directorios de runtime

Estos archivos versionados son marcadores Git; no contienen lógica de aplicación. Sus directorios se rellenan durante la ejecución y deben permanecer escribibles por el usuario del proceso.

| Archivo | Qué hace | Mejora recomendada |
|---|---|---|
| `storage/app/.gitignore` | Evita versionar contenido local del disco de aplicación. | Mantener datos de usuario fuera del repositorio y definir política de respaldo/retención por disco. |
| `storage/app/private/.gitignore` | Mantiene vacío/versionable el directorio privado sin incluir archivos reales. | Servir archivos privados mediante autorización, nunca como contenido público directo. |
| `storage/app/public/.gitignore` | Mantiene el directorio público de almacenamiento en Git sin incluir archivos runtime. | Exponer solo archivos destinados al público y validar tipo/tamaño al subirlos. |
| `storage/framework/.gitignore` | Excluye artefactos temporales generales del framework. | Limpiar artefactos de forma segura en despliegue y garantizar permisos adecuados. |
| `storage/framework/cache/.gitignore` | Excluye archivos de caché runtime. | Elegir un almacén compartido en producción multi-instancia. |
| `storage/framework/cache/data/.gitignore` | Excluye los datos de caché en disco. | Evitar depender de caché local si se requiere coherencia entre instancias. |
| `storage/framework/sessions/.gitignore` | Excluye sesiones almacenadas en archivos. | Usar driver consistente con la escala y los requisitos de invalidación de sesión. |
| `storage/framework/testing/.gitignore` | Excluye archivos temporales del framework de pruebas. | No almacenar datos personales ni fixtures sensibles aquí. |
| `storage/framework/views/.gitignore` | Excluye vistas Blade compiladas. | Limpiar/regenerar en despliegues cuando cambien plantillas. |
| `storage/logs/.gitignore` | Evita versionar logs de ejecución. | En producción enviar logs a un destino centralizado con retención y redacción. |

## 9. Documentación (`docs/`)

| Archivo | Qué explica y relaciones | Mejora recomendada |
|---|---|---|
| `docs/validacion-ubicacion.md` | Requisitos/criterios de validación de ubicación usados por `EnsurePremiseLocation`. | Mantener umbrales sincronizados con configuración/código y documentar límites de confianza del GPS. |
| `docs/frontend/responsable-predio.md` | Contrato o guía frontend para la experiencia del responsable de predio y su QR; relacionado con endpoints manager. | Añadir ejemplos de requests/responses, estados de error y versión del contrato. |
| `docs/mapa-del-repositorio.md` | Este mapa: inventario versionado, relaciones y evolución arquitectónica recomendada. | Actualizar en cada cambio estructural importante; idealmente automatizar el inventario y revisar manualmente semántica. |

## 10. Pruebas (`tests/`)

| Archivo | Qué cubre y relaciones | Mejora recomendada |
|---|---|---|
| `tests/TestCase.php` | Clase base para pruebas Laravel. | Añadir helpers comunes mínimos y configuración de entorno de prueba predecible. |
| `tests/Concerns/SignsInWithDevice.php` | Trait para iniciar sesión de prueba incluyendo encabezado/plataforma/dispositivo. | Reutilizar fixtures coherentes y no ocultar preparación importante de escenarios. |
| `tests/Feature/AdminCatalogTest.php` | Panel de administración: catálogo y motivos de predios, listado de predios y tiempo de vida del QR. | Añadir alta/edición de predios con responsable. |
| `tests/Feature/LeaveFlowTest.php` | Flujo móvil completo con el sistema externo y Redis simulados: escaneo (salida/retorno), confirmación de salida, estado, sincronización de motivos y generación del QR. | Cubrir la concurrencia de dos escaneos simultáneos. |
| `tests/Feature/LeaveLimitsTest.php` | Límite general de salidas por período y por predio. | Probar los límites de semana y mes. |
| `tests/Feature/LeaveStatsTest.php` | Estadísticas de salidas y perfil (foto/cargo) con el servicio externo simulado. | — |
| `tests/Feature/DeviceBindingTest.php` | Escenarios de vinculación y autorización por dispositivo. | Cubrir carreras, revocación, hash no expuesto y ambos clientes/roles. |
| `tests/Feature/PremiseLocationTest.php` | Escenarios de ubicación/precisión/distancia y middleware. | Probar límites exactos, timestamps futuros/vencidos, predios sin coordenadas y varios predios. |
| `tests/Feature/PremiseManagerTest.php` | Acceso y operaciones de responsable de predio. | Cubrir separación entre predios, plataforma incorrecta y cambios de asignación. |
| `tests/Feature/ExampleTest.php` | Prueba de ejemplo generada por Laravel. | Reemplazar o borrar cuando las pruebas propias cubran la ruta de bienvenida. |
| `tests/Unit/ExampleTest.php` | Prueba unitaria de ejemplo generada por Laravel. | Reemplazar por pruebas de reglas puras (cuotas, plataforma, distancia/token) conforme se extraigan servicios. |

## 11. Dependencias de dominio

```text
User ── Role
  ├── Premise (asignación de responsable)
  ├── UserDevice (web/móvil)
  ├── UserActiveSession ── sessions (Laravel)
  └── Record ── ReasonPremise ── Premise
                           └──── LeaveReason

API routes → middleware de autenticación/sesión/dispositivo/plataforma/rol/ubicación
           → Form Request → controller → services → repositories → modelos Eloquent → base de datos
                                            └─────→ ExternalApiService → sistema externo / Redis
```

## 12. Mejoras de arquitectura por prioridad

1. **Contratos y documentación:** renovar `README.md`; publicar especificación OpenAPI con autenticación, headers requeridos (`ClientPlatform`, `DeviceId`), payloads y errores.
2. ~~**Bordes HTTP delgados:** Form Requests, API Resources y excepciones de dominio.~~ Hecho (`app/Http/Requests`, `PremiseResource`, `ApiException`); falta Resource para el resto de respuestas y unificar los dos formatos de cuerpo (`status` numérico / textual).
3. ~~**Casos de uso explícitos:** iniciar sesión, registrar salida/retorno, sincronizar motivos, administrar predios.~~ Hecho en `app/Services`. Falta envolver en transacción los cambios que deben ser atómicos.
4. **Integraciones resilientes:** el cliente dedicado (`ExternalApiService`, configuración en `config/services.php`, tiempos de espera) ya existe; falta reintento acotado, idempotencia y observabilidad redactada; trabajos en cola cuando sea apropiado.
5. **Integridad y concurrencia:** claves foráneas, índices compuestos, restricciones únicas/check y bloqueo/transacción donde dos solicitudes simultáneas puedan exceder cuotas o abrir salidas duplicadas.
6. **Seguridad y privacidad:** políticas de autorización por recurso, rotación/revocación de dispositivo, gestión de secretos, límites de intentos, auditoría administrativa y retención mínima de IP/ubicación/User-Agent.
7. **Consistencia de dominio:** normalizar códigos de rol/plataforma, zona horaria y estados de salida; tipar relaciones y resultados, y evitar métodos de repositorio ambiguos o duplicados.
8. **Calidad automatizada:** CI que ejecute suite existente, formatter, análisis estático y auditoría de dependencias; añadir pruebas unitarias a reglas puras y pruebas de integración a persistencia/API. Mantener pruebas rápidas y deterministas.
9. **Observabilidad y operación:** logs estructurados con correlation/request ID, métricas de integraciones y errores, health/readiness checks útiles, backups y procedimiento de recuperación.

Estas mejoras son una hoja de ruta. Conviene introducirlas de forma incremental, preservando el contrato móvil/web y midiendo complejidad y riesgos antes de adoptar capas o patrones que no aporten una necesidad concreta.
