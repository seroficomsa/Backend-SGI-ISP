<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Models\Usuario;
use App\Models\AccionRealizada;
use Dotenv\Exception\ValidationException;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            // Definir mensajes en español
            $messages = [
                'correo_electronico.required' => 'El correo electrónico es obligatorio.',
                'correo_electronico.email' => 'El formato del correo electrónico no es válido.',
                'password.required' => 'La contraseña es obligatoria.',
                'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            ];
    
            // Validar la solicitud con mensajes personalizados en español
            $validator = Validator::make($request->all(), [
                'correo_electronico' => 'required|email',
                'password' => 'required|string|min:4',
            ], $messages);
    
            // Si la validación falla, devolver el mensaje en español
            if ($validator->fails()) {
                return response()->json([
                    'status' => 400,
                    'message' => $validator->errors()->first(), // Mensaje de error en español
                ], 400);
            }
    
            $credentials = $request->only('correo_electronico', 'password');
    
            // Verificar si el correo existe
            $user = Usuario::where('correo_electronico', $request->correo_electronico)->first();
    
            if (!$user) {
                Log::warning('Intento de inicio de sesión con correo no registrado.', [
                    'correo_electronico' => $request->correo_electronico,
                ]);
    
                return response()->json([
                    'status' => 404,
                    'message' => 'El usuario no está registrado.',
                ], 404);
            }
    
            // Verificar si las credenciales son correctas
            if (!$token = auth('api')->attempt($credentials)) {
                Log::warning('Intento de inicio de sesión fallido: contraseña incorrecta.', [
                    'correo_electronico' => $request->correo_electronico,
                ]);
    
                return response()->json([
                    'status' => 401,
                    'message' => 'Credenciales inválidas. Por favor, verifica tu correo y contraseña.',
                ], 401);
            }
    
            // Autenticación exitosa
            $user = auth('api')->user();
    
            Log::info('Inicio de sesión exitoso.', [
                'id_usuario' => $user->id_usuario,
            ]);
    
            return response()->json([
                'status' => 200,
                'message' => 'Inicio de sesión exitoso.',
                'data' => [
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => auth('api')->factory()->getTTL() * 60,
                    'user' => [
                        'id_usuario' => $user->id_usuario,
                        'nombres' => $user->nombres,
                        'apellidos' => $user->apellidos,
                        'correo_electronico' => $user->correo_electronico,
                        'rol' => $user->rol->nombre_rol,
                        'prefix_rol' => $user->rol->prefix_rol,
                    ],
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en el login.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return response()->json([
                'status' => 500,
                'message' => 'Ocurrió un problema al intentar iniciar sesión. Por favor, intenta de nuevo más tarde.',
            ], 500);
        }
    }
    
    
    
    public function logout()
    {
        try {
            $user = auth('api')->user();

            auth('api')->logout();

            // Registrar la acción
            AccionRealizada::create([
                'id_usuario' => $user->id_usuario,
                'descripcion' => "Cierre de sesión exitoso por usuario: {$user->nombres} {$user->apellidos}.",
                'tipo_accion' => 'Logout',
                'estado' => 'A',
            ]);

            Log::info('Usuario cerró sesión correctamente.', [
                'id_usuario' => $user->id_usuario,
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Sesión cerrada correctamente.',
            ], 200);
        } catch (Exception $e) {
            Log::error('Error al cerrar sesión.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 500,
                'message' => 'Ocurrió un problema al intentar cerrar sesión. Por favor, intenta de nuevo más tarde.',
            ], 500);
        }
    }

    public function me()
    {
        try {
            $user = auth('api')->user();
    
            if (!$user) {
                Log::warning('Intento de obtener datos del usuario sin autenticación.');
                return response()->json([
                    'status' => 401,
                    'message' => 'No estás autenticado. Por favor, inicia sesión.',
                ], 401);
            }
    
            Log::info('Datos del usuario obtenidos correctamente.', [
                'id_usuario' => $user->id_usuario,
            ]);
    
            return response()->json([
                'status' => 200,
                'message' => 'Datos del usuario obtenidos correctamente.',
                'data' => [
                    'id_usuario' => $user->id_usuario,
                    'nombres' => $user->nombres,
                    'apellidos' => $user->apellidos,
                    'correo_electronico' => $user->correo_electronico,
                    'rol' => $user->rol->nombre_rol,
                    'prefix_rol' => $user->rol->prefix_rol, // Incluye el prefijo del rol
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Error al obtener datos del usuario.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return response()->json([
                'status' => 500,
                'message' => 'Ocurrió un problema al obtener los datos del usuario. Por favor, intenta de nuevo más tarde.',
            ], 500);
        }
    }
    
}
