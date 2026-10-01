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

### Comandos, eventos y soporte

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `app/Console/Commands/ResetUserDevice.php` | Comando CLI para desvincular/reiniciar el dispositivo asociado a una cuenta; relacionado con `UserDevice` y `DeviceBindingService`. | Validar argumentos con mensajes claros, auditar quién/cuándo ejecutó el reset y cubrir autorización operativa. |
| `app/Events/QrScanned.php` | Evento de dominio/aplicación ligado al escaneo QR; puede conectar el flujo con listeners/broadcasting. | Aclarar si realmente se despacha y qué datos transporta; mantenerlo inmutable y sin datos sensibles innecesarios. |
| `app/Support/ClientPlatform.php` | Centraliza identificador de plataforma, roles compatibles y mensajes de acceso para web/móvil; usada por autenticación y middleware. | Convertir valores/mensajes a configuración o enums/objetos de valor, y mantener una sola política probada. |

### Controladores HTTP

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `app/Http/Controllers/Controller.php` | Clase base de controladores Laravel. | Conservarla ligera; evitar que se convierta en un contenedor de lógica compartida no relacionada. |
| `app/Http/Controllers/AuthController.php` | Inicio/cierre de sesión y consulta del usuario. Coordina autenticación externa/local, rol/plataforma, dispositivo vinculado y sesión activa. | Extraer login/logout a casos de uso; usar Form Requests, transacción para persistencia de sesión/vinculación y respuestas API uniformes. |
| `app/Http/Controllers/LeaveController.php` | Sincroniza motivos externos, lista motivos por predio y registra salidas usando repositorios y `LeaveQuotaService`. | Reducir responsabilidades; mover sincronización HTTP a cliente dedicado y registro de salida a caso de uso transaccional. |
| `app/Http/Controllers/PremiseController.php` | Endpoints administrativos de predios y asociación de motivos; usa `PremiseRepository` y modelos relacionados. | Form Requests, Policies y respuestas Resource; controlar concurrencia y evitar consultas/reglas complejas en controlador. |
| `app/Http/Controllers/QrController.php` | Emite/valida tokens QR para predios y responsables; asociado a settings, usuarios y flujo de salida. | Separar creación/verificación de tokens a servicio dedicado; definir caducidad, firma, rotación y límites de intentos como política explícita. |
| `app/Http/Controllers/SettingsController.php` | Lee y actualiza ajustes globales de QR desde el panel admin; usa `Setting`. | Validación tipada y caché invalidable; limitar qué claves se pueden modificar y registrar auditoría de cambios. |
| `app/Http/Controllers/UserController.php` | Administra usuarios, roles, asignaciones, políticas de salida, contraseñas y reset de dispositivo; también consulta estado del empleado. | Dividir API administrativa y consultas del usuario; usar Form Requests, Policies, Resources y casos de uso específicos. |

### Middleware

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `app/Http/Middleware/CheckActiveSession.php` | Exige que la sesión Laravel actual exista en `user_active_sessions` y actualiza su actividad. | Usar relación/servicio de sesión, índice apropiado y definir estrategia para evitar escrituras `touch()` en cada petición si la carga lo requiere. |
| `app/Http/Middleware/CheckAuthorization.php` | Verifica usuario y rol contra los roles autorizados de la ruta. | Preferir Policies/Gates para permisos por recurso; centralizar códigos y formato de errores. |
| `app/Http/Middleware/CheckDeviceId.php` | Verifica plataforma guardada en sesión, rol permitido y coincidencia del dispositivo a través de `DeviceBindingService`. | Hacer explícito el orden de middleware y el contrato del header; añadir pruebas de sesión antigua, dispositivo ausente y rol cambiado. |
| `app/Http/Middleware/CheckPlatform.php` | Restringe rutas a plataforma(s) web/móvil permitidas según sesión. | Usar enum/objeto de valor y política compartida con login para no duplicar reglas. |
| `app/Http/Middleware/CheckPremiseLocation.php` | Valida coordenadas, precisión, antigüedad y señales de spoofing; calcula distancia a predios antes de continuar. | Extraer geocálculo a servicio probado; buscar candidatos por bounding box/índice espacial; mantener límites configurables y reconocer que GPS/VPN del cliente no son prueba criptográfica de ubicación. |

### Modelos Eloquent

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `app/Models/Premise.php` | Representa `premises`; se relaciona con motivos mediante pivote y con usuarios responsables. | Declarar tipos de retorno de relaciones, casts de coordenadas y política clara de borrado/soft deletes. |
| `app/Models/ReasonLeave.php` | Catálogo de motivos en `reasons`, relacionado con predios por `ReasonPremise`. | Especificar unicidad del código y relaciones tipadas; definir comportamiento de sincronización/archivado. |
| `app/Models/ReasonPremise.php` | Pivote entre motivo y predio; también enlaza registros/usuarios según las relaciones actuales. | Revisar si debe ser modelo Eloquent normal en vez de Pivot al tener identidad/registros asociados; precisar cardinalidades. |
| `app/Models/Record.php` | Representa una salida y su retorno en `records`; pertenece a usuario y a la combinación motivo-predio. | Considerar modelo normal con casts de fechas, estados/invariantes, y transacción/índice que impida salidas abiertas duplicadas. |
| `app/Models/Role.php` | Catálogo de roles y constantes `EMPLOYEE`, `ADMIN`, `MANAGE_PREMISE`; tiene usuarios. | Preferir permisos/capacidades explícitas si los roles crecen; añadir relaciones tipadas y restricciones de datos. |
| `app/Models/Setting.php` | Ajustes globales clave-valor; métodos estáticos para leer/escribir. | Validar tipos/esquema de claves y encapsular cache; evitar que valores arbitrarios gobiernen seguridad. |
| `app/Models/User.php` | Usuario autenticable; relaciones con rol, predio, sesiones, dispositivos, registros y política de salidas. | Tipar todas las relaciones, separar identidad externa de autenticación local si crecen requisitos y proteger asignación masiva por caso de uso. |
| `app/Models/UserActiveSession.php` | Sesiones activas de usuario, plataforma y metadatos del cliente. | Minimizar retención de IP/User-Agent, añadir índices/únicos según reglas y política de expiración. |
| `app/Models/UserDevice.php` | Dispositivo autorizado por usuario/plataforma; guarda hash oculto y fecha de vínculo. | Asegurar hash con secreto/algoritmo adecuado, índice único `(user_id, platform)`, rotación/auditoría y política de revocación. |
| `app/Models/UserLeavePolicy.php` | Límite/cuota de salidas por usuario, periodo y posiblemente predio. | Validar invariantes y unicidad por usuario; definir unidad/ventana temporal con tipos y documentación. |

### Servicios y repositorios

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `app/Services/DeviceBindingService.php` | Valida formato del identificador, lo transforma/compara de forma segura y administra el dispositivo autorizado asociado a usuario/plataforma. | Añadir contrato de almacenamiento, transacciones/índices únicos, rotación del secreto y pruebas de carrera/filtración. |
| `app/Services/LeaveQuotaService.php` | Calcula y aplica límites de salidas según política/periodo; lo usa el registro de salida. | Precisar semántica del periodo y zona horaria; proteger frente a peticiones simultáneas con transacción/bloqueo o restricción. |
| `app/Services/ThirdPartyService.php` | Integra autenticación/datos de usuarios con servicio externo. | Usar `config/services.php` en lugar de `env()` directo, timeouts/reintentos controlados, DTO, logging redactado y pruebas con HTTP fake. |
| `app/Repositories/PremiseRepository.php` | Encapsula consultas y escrituras de predios usadas por controladores. | Evaluar si el repositorio agrega abstracción real sobre Eloquent; preferir consultas enfocadas/casos de uso y tipos de retorno. |
| `app/Repositories/ReasonLeaveRepository.php` | Consulta/sincroniza el catálogo de motivos. | Hacer sincronización idempotente, definir bajas/actualizaciones y trasladar integración externa a un cliente dedicado. |
| `app/Repositories/ReasonPremiseRepository.php` | Consulta/actualiza asociaciones motivo-predio. | Usar transacciones y validación de existencia; nombrar métodos según intención y especificar resultados/contratos. |
| `app/Repositories/RecordRepository.php` | Acceso a registros de salida/retorno. | Centralizar invariantes de estado y concurrencia; evitar consultas duplicadas con `UserRepository`. |
| `app/Repositories/UserActiveSessionRepository.php` | Crea, consulta y limita sesiones activas por usuario/plataforma. Lo usa autenticación/middleware. | Definir límites como política, agregar índices y ejecutar reemplazos en transacción. |
| `app/Repositories/UserRepository.php` | Registra/consulta usuarios y algunas operaciones de salida/retorno. | Evitar mezcla de persistencia de usuario y flujo de salidas; consolidar nombres/IDs y devolver tipos anulables correctos. |

### Proveedores

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `app/Providers/AppServiceProvider.php` | Punto de registro de servicios de la aplicación en el contenedor Laravel. | Registrar bindings/interfaces aquí solo cuando exista una necesidad clara; mantener configuración fuera de lógica de negocio. |

## 4. Arranque y configuración

| Archivo | Qué hace y relaciones | Mejora recomendada |
|---|---|---|
| `bootstrap/app.php` | Construye la aplicación Laravel y registra rutas, middleware y manejo de excepciones. | Mantener alias/orden de middleware documentados y probar respuestas JSON de errores. |
| `bootstrap/providers.php` | Lista proveedores de servicios cargados al arrancar. | Añadir proveedores propios solo si organizan bindings/eventos relevantes. |
| `bootstrap/cache/.gitignore` | Evita versionar cachés de bootstrap. | Conservar; generar cachés durante despliegue reproducible. |
| `config/app.php` | Nombre, entorno, zona horaria, locale, cifrado y parámetros generales. | Configurar zona horaria/locale de negocio conscientemente y no almacenar secretos fuera del entorno. |
| `config/auth.php` | Guards, proveedores y modelo de autenticación. Se relaciona con `User` y Sanctum. | Revisar guard usado por rutas de sesión y documentar el modelo/clave primaria no convencional. |
| `config/broadcasting.php` | Conexiones para difusión de eventos. Se relaciona potencialmente con `QrScanned` y Reverb. | Eliminar conexiones no usadas y documentar configuración de tiempo real si forma parte del producto. |
| `config/cache.php` | Almacenes y prefijo de caché Laravel. | Elegir backend por entorno y planificar invalidación/aislamiento entre despliegues. |
| `config/cors.php` | Orígenes y métodos permitidos para clientes web. | Restringir orígenes de producción al mínimo y documentar dominios por ambiente. |
| `config/database.php` | Conexiones y opciones de base de datos. | Usar índices/constraints en migraciones y parámetros seguros de producción; monitorear conexiones. |
| `config/filesystems.php` | Discos locales y remotos para archivos. | Definir permisos/visibilidad y almacenamiento privado por defecto para datos sensibles. |
| `config/logging.php` | Canales y formato de logs. | Estructurar contexto y redactar credenciales, tokens, coordenadas e identificadores sensibles. |
| `config/mail.php` | Transporte y remitente de correo. | Configurar por entorno y probar fallos/entrega si se habilitan notificaciones. |
| `config/queue.php` | Conexiones y comportamiento de colas. | Usar trabajos para sincronizaciones externas lentas y definir reintentos/idempotencia si aplica. |
| `config/reverb.php` | Configuración del servidor de broadcasting Reverb. | Documentar su uso real, autenticación de canales y secretos por ambiente. |
| `config/sanctum.php` | Dominios stateful, autenticación por cookies y expiración Sanctum. | Alinear dominios/cookies con frontend y validar CSRF/sesiones en despliegue. |
| `config/services.php` | Credenciales y endpoints para servicios externos. | Colocar aquí toda configuración de integraciones y consumirla vía `config()`, no `env()` desde clases. |
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
| `routes/api.php` | Define health, login/logout, flujo móvil de salida, responsable de predio y administración; asigna middleware y nombres de ruta. | Versionar API, documentar OpenAPI, agrupar por contexto/controlador y revisar consistencia de códigos HTTP/respuestas. |
| `routes/web.php` | Define la ruta web `/` que muestra `welcome`. | Retirar imports no usados y agregar rutas web solo si existe interfaz servida por Laravel. |
| `routes/console.php` | Lugar para comandos/tareas definidos con API de rutas de consola Laravel. | Documentar programación de tareas y mantener lógica en clases dedicadas. |
| `routes/channels.php` | Define autorización de canales de broadcasting. | Si se usa Reverb, proteger cada canal por usuario/rol; eliminar canal por defecto no utilizado. |
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
| `docs/validacion-ubicacion.md` | Requisitos/criterios de validación de ubicación usados por `CheckPremiseLocation`. | Mantener umbrales sincronizados con configuración/código y documentar límites de confianza del GPS. |
| `docs/frontend/responsable-predio.md` | Contrato o guía frontend para la experiencia del responsable de predio y su QR; relacionado con endpoints manager. | Añadir ejemplos de requests/responses, estados de error y versión del contrato. |
| `docs/mapa-del-repositorio.md` | Este mapa: inventario versionado, relaciones y evolución arquitectónica recomendada. | Actualizar en cada cambio estructural importante; idealmente automatizar el inventario y revisar manualmente semántica. |

## 10. Pruebas (`tests/`)

| Archivo | Qué cubre y relaciones | Mejora recomendada |
|---|---|---|
| `tests/TestCase.php` | Clase base para pruebas Laravel. | Añadir helpers comunes mínimos y configuración de entorno de prueba predecible. |
| `tests/Concerns/SignsInWithDevice.php` | Trait para iniciar sesión de prueba incluyendo encabezado/plataforma/dispositivo. | Reutilizar fixtures coherentes y no ocultar preparación importante de escenarios. |
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
  ├── UserLeavePolicy
  └── Record ── ReasonPremise ── Premise
                           └──── ReasonLeave

API routes → middleware de autenticación/sesión/dispositivo/plataforma/rol/ubicación
           → controllers → services / repositories → modelos Eloquent → base de datos
                                         └────────→ servicio externo
```

## 12. Mejoras de arquitectura por prioridad

1. **Contratos y documentación:** renovar `README.md`; publicar especificación OpenAPI con autenticación, headers requeridos (`ClientPlatform`, `DeviceId`), payloads y errores.
2. **Bordes HTTP delgados:** crear Form Requests, API Resources y excepciones de dominio para sacar validación, formato de respuesta y reglas de los controladores.
3. **Casos de uso explícitos:** encapsular iniciar sesión, registrar salida/retorno, sincronizar motivos, resetear dispositivo y administrar predios. Mantener transacciones alrededor de cambios que deben ser atómicos.
4. **Integraciones resilientes:** cliente dedicado para servicio externo con configuración tipada, timeout, reintento acotado, idempotencia y observabilidad redactada; trabajos en cola cuando sea apropiado.
5. **Integridad y concurrencia:** claves foráneas, índices compuestos, restricciones únicas/check y bloqueo/transacción donde dos solicitudes simultáneas puedan exceder cuotas o abrir salidas duplicadas.
6. **Seguridad y privacidad:** políticas de autorización por recurso, rotación/revocación de dispositivo, gestión de secretos, límites de intentos, auditoría administrativa y retención mínima de IP/ubicación/User-Agent.
7. **Consistencia de dominio:** normalizar códigos de rol/plataforma, zona horaria y estados de salida; tipar relaciones y resultados, y evitar métodos de repositorio ambiguos o duplicados.
8. **Calidad automatizada:** CI que ejecute suite existente, formatter, análisis estático y auditoría de dependencias; añadir pruebas unitarias a reglas puras y pruebas de integración a persistencia/API. Mantener pruebas rápidas y deterministas.
9. **Observabilidad y operación:** logs estructurados con correlation/request ID, métricas de integraciones y errores, health/readiness checks útiles, backups y procedimiento de recuperación.

Estas mejoras son una hoja de ruta. Conviene introducirlas de forma incremental, preservando el contrato móvil/web y midiendo complejidad y riesgos antes de adoptar capas o patrones que no aporten una necesidad concreta.
