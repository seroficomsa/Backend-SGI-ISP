<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\AccionRealizada;

class HandleTokenExpiration
{
    public function handle($request, Closure $next)
    {
        try {
            // Verificar el token y autenticar al usuario
            $user = JWTAuth::parseToken()->authenticate();

            // Continuar si el token es válido
            return $next($request);
        } catch (TokenExpiredException $e) {
            // Obtener el usuario del token
            $payload = JWTAuth::manager()->getPayloadFactory()->buildClaimsCollection()->toPlainArray();
            $userId = $payload['sub'] ?? null;

            // Registrar la acción en la base de datos
            if ($userId) {
                AccionRealizada::create([
                    'id_usuario' => $userId,
                    'descripcion' => 'Cierre de sesión por expiración del token.',
                    'tipo_accion' => 'logout',
                    'estado' => 'A',
                ]);
            }

            Log::warning('Token expirado.', ['user_id' => $userId]);

            // Responder con un error de token expirado
            return response()->json([
                'status' => 401,
                'message' => 'El token ha expirado. Por favor, inicia sesión nuevamente.',
            ], 401);
        } catch (TokenInvalidException $e) {
            Log::error('Token inválido.');

            return response()->json([
                'status' => 401,
                'message' => 'El token es inválido.',
            ], 401);
        } catch (Exception $e) {
            Log::error('Error al procesar el token.', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 500,
                'message' => 'Error al procesar el token.',
            ], 500);
        }
    }
}
