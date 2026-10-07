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

- PHP 8.2+, Composer, PostgreSQL y Redis.
- Acceso al sistema externo de personal.

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
| `API_REGISTERCHECKOUT` | Registro de salidas y retornos |

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
