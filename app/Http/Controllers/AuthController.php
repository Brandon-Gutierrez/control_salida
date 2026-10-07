<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Repositories\UserActiveSessionRepository;
use App\Services\Auth\AuthService;
use App\Services\External\EmployeeProfileService;
use App\Support\ClientPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $auth,
        private UserActiveSessionRepository $sessions,
        private EmployeeProfileService $profiles,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $platform = strtolower((string) $request->header(ClientPlatform::HEADER));

        $user = $this->auth->authenticate(
            $request->validated('username'),
            $request->validated('password'),
            $platform,
            $request->header('DeviceId'),
        );

        $this->openSession($request, $user, $platform);

        return response()->json([
            'status' => 'SUCCESS',
            'message' => 'Inicio de sesión exitoso.',
            'user' => $this->userPayload($user),
        ]);
    }

    /** Usuario autenticado de la sesión actual. */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'SUCCESS',
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /** Cierra la sesión actual (solo este dispositivo; no afecta otras sesiones del admin). */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // El responsable de predio deja la pantalla de QR abierta de forma
        // permanente: su sesión solo la puede cerrar administración.
        if ($user->isPremiseManager()) {
            throw ApiException::authFailure(
                403,
                'El responsable de predio no puede cerrar sesión desde esta cuenta.',
                'LOGOUT_NOT_ALLOWED',
            );
        }

        $this->sessions->delete($user->user_id, $request->session()->getId());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'status' => 'SUCCESS',
            'message' => 'Sesión cerrada.',
        ]);
    }

    /**
     * Abre la sesión de Laravel y la registra como la única activa de la
     * cuenta en esa aplicación: la nueva reemplaza a la anterior (p. ej. si
     * se borraron las cookies en el mismo dispositivo).
     */
    private function openSession(Request $request, User $user, string $platform): void
    {
        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->put(ClientPlatform::SESSION_KEY, $platform);

        $sessionId = $request->session()->getId();
        $this->sessions->create(
            $user->user_id,
            $sessionId,
            $platform,
            (string) $request->header('User-Agent'),
            (string) $request->ip(),
            (string) $request->userAgent(),
        );
        $this->sessions->keepOnly($user->user_id, $platform, $sessionId);
    }

    /** Usuario con su rol y predio, más foto y cargo del sistema externo. */
    private function userPayload(User $user): array
    {
        $profile = $this->profiles->forUser($user);

        return $user->load(['role', 'premise'])->toArray() + [
            'photo_url' => $profile['photo_url'],
            'job_title' => $profile['job_title'],
            'area' => $profile['area'],
        ];
    }
}
