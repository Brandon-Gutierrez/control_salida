<?php

namespace App\Support;

use App\Models\Role;

/**
 * Reglas de acceso por aplicación cliente.
 *
 * - web (panel de administración): ADMIN y MANAGE_PREMISE.
 * - mobile (app de salidas): ADMIN y EMPLOYEE.
 *
 * En ambas cada cuenta queda vinculada a un único dispositivo por aplicación.
 */
final class ClientPlatform
{
    public const WEB = 'web';
    public const MOBILE = 'mobile';
    public const ALL = [self::WEB, self::MOBILE];

    /** Encabezado con el que cada aplicación se identifica al iniciar sesión. */
    public const HEADER = 'X-Client-Platform';

    /** Clave de la sesión donde se guarda la aplicación con la que se inició sesión. */
    public const SESSION_KEY = 'client_platform';

    private const ALLOWED_ROLES = [
        self::WEB => [Role::ADMIN, Role::MANAGE_PREMISE],
        self::MOBILE => [Role::ADMIN, Role::EMPLOYEE],
    ];

    public static function isValid(?string $platform): bool
    {
        return in_array($platform, self::ALL, true);
    }

    public static function allows(string $platform, ?string $roleName): bool
    {
        return in_array(strtoupper($roleName ?? ''), self::ALLOWED_ROLES[$platform] ?? [], true);
    }

    /** Aplicaciones que puede usar un rol. */
    public static function forRole(?string $roleName): array
    {
        return array_values(array_filter(self::ALL, fn ($p) => self::allows($p, $roleName)));
    }

    public static function roleNotAllowedMessage(string $platform, ?string $roleName): string
    {
        return match (true) {
            $platform === self::WEB && strtoupper($roleName ?? '') === Role::EMPLOYEE
                => 'Los empleados solo pueden ingresar desde la aplicación móvil.',
            $platform === self::MOBILE && strtoupper($roleName ?? '') === Role::MANAGE_PREMISE
                => 'Las cuentas de responsable de predio solo pueden ingresar desde la versión web.',
            default => 'Esta cuenta no tiene acceso desde esta aplicación.',
        };
    }

    public static function deviceNotAuthorizedMessage(string $platform): string
    {
        return $platform === self::WEB
            ? 'Esta cuenta ya está vinculada a otro navegador o equipo. Contacte a TI para que eliminen el dispositivo anterior.'
            : 'Esta cuenta ya está vinculada a otro teléfono. Contacte a Recursos Humanos para que deshabiliten el dispositivo anterior.';
    }
}
