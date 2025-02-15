<?php

namespace App\Http\Controllers;

use App\Libraries\RouterosAPI;
use App\Models\AccionRealizada;
use Illuminate\Http\Request;
use App\Models\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RouterController extends Controller
{

    public function obtenerRouter(Request $request)
    {
        $id = $request->input('id_router'); // Obtener el ID del cuerpo de la solicitud

        $router = Router::find($id);

        if (!$router || $router->estado === 'E') {
            return response()->json([
                'success' => false,
                'message' => 'Router no encontrado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $router,
        ], 200);
    }

    // Listar todos los routers activos
    public function listarRouters()
    {
        $routers = Router::where('estado', 'A')->get();

        foreach ($routers as $router) {
            $api = new RouterosAPI();
            try {
                if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
                    // Obtener datos de system resource
                    $api->write('/system/resource/print', true);
                    $resourceResponse = $api->read(false);
                    $resourceData = $api->parseResponse($resourceResponse);

                    // Obtener datos de RouterBOARD
                    $api->write('/system/routerboard/print', true);
                    $routerboardResponse = $api->read(false);
                    $routerboardData = $api->parseResponse($routerboardResponse);

                    $router->modelo = $resourceData[0]['board-name'] ?? 'Desconocido';
                    $router->board_name = $routerboardData[0]['model'] ?? 'Sin información';
                    $router->architecture_name = $resourceData[0]['architecture-name'] ?? 'Desconocido';
                    $router->cpu = $resourceData[0]['cpu'] ?? 'Desconocido';
                    $router->firmware_version = $routerboardData[0]['current-firmware'] ?? 'Desconocido';
                    $router->uptime = $resourceData[0]['uptime'] ?? 'Desconocido';
                    $router->estado_router = 'Activo';

                    $api->disconnect();
                } else {
                    $router->modelo = 'Sin información';
                    $router->board_name = 'Sin información';
                    $router->architecture_name = 'Sin información';
                    $router->cpu = 'Sin información';
                    $router->firmware_version = 'Sin información';
                    $router->uptime = 'Sin información';
                    $router->estado_router = 'Desconectado';

                    $this->registrarAccionDesconectado($router);
                }
            } catch (\Exception $e) {
                $router->modelo = 'No disponible';
                $router->board_name = 'No disponible';
                $router->architecture_name = 'No disponible';
                $router->cpu = 'No disponible';
                $router->firmware_version = 'No disponible';
                $router->uptime = 'No disponible';
                $router->estado_router = 'Desconocido';

                $this->registrarAccionDesconectado($router);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $routers,
            'total' => $routers->count(),
        ], 200);
    }

    /**
     * Registrar una acción en la tabla `acciones_realizadas` cuando un router esté desconectado.
     */
    private function registrarAccionDesconectado($router)
    {
        try {
            AccionRealizada::create([
                'id_usuario' => Auth::id(),
                'descripcion' => "El Router {$router->nombre_router} se encuentra DESCONECTADO. Por favor, verificar la conexión.",
                'tipo_accion' => 'Router',
                'estado' => 'A',
                'fecha_creacion' => now(),
                'fecha_actualizacion' => now(),
            ]);
        } catch (\Exception $e) {
            // Log de error para fallos al registrar la acción
            Log::error("Error al registrar acción de desconexión para el router {$router->nombre_router}: {$e->getMessage()}");
        }
    }



    // Verificar conexión con el router
    public function VerificarConexionRouter(Request $request)
    {
        // Validar los datos de entrada
        $validated = $request->validate([
            'ip' => 'required|ip',
            'port' => 'required|integer|min:1|max:65535',
            'user' => 'required|string|max:100',
            'password' => 'required|string|max:100',
        ]);
    
        try {
            // Instanciar la API de RouterOS
            $api = new RouterosAPI();
            $api->debug = false; // Cambiar a true si necesitas más detalles en desarrollo
    
            // Intentar conectar
            if ($api->connect($validated['ip'], $validated['user'], $validated['password'], $validated['port'])) {
                // Ejemplo: Consulta de información básica del sistema
                $api->write('/system/resource/print', true);
                $response = $api->read(false);
                $parsedResponse = $api->parseResponse($response);
    
                $api->disconnect(); // Cerrar la conexión
    
                return response()->json([
                    'success' => true,
                    'message' => 'Conexión exitosa con el router.',
                    'data' => $parsedResponse, // Detalles adicionales del router
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo conectar al router. Verifica los datos proporcionados.',
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al conectar con el router.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    // Crear un nuevo router
    public function GuardarRouter(Request $request)
    {
        $validated = $request->validate([
            'nombre_router' => 'required|string|max:255',
            'descripcion_router' => 'nullable|string|max:255',
            'user' => 'required|string|max:100',
            'password' => 'required|string|max:100',
            'ip' => 'required|ip',
            'port' => 'required|integer|min:1|max:65535',
            'latitud' => 'nullable|numeric',
            'longitud' => 'nullable|numeric',
        ]);
    
        try {
            // Verificar conexión antes de guardar
            $api = new RouterosAPI();
    
            if ($api->connect($validated['ip'], $validated['user'], $validated['password'], $validated['port'])) {
                $api->disconnect();
    
                // Guardar en la base de datos
                $router = Router::create(array_merge($validated, [
                    'estado' => 'A',
                    'id_usuario_auditor' => Auth::id(),
                ]));
    
                // Registrar la acción realizada
                AccionRealizada::create([
                    'id_usuario' => Auth::id(),
                    'descripcion' => "Se ha creado el router {$router->nombre_router}.",
                    'tipo_accion' => 'Router',
                    'estado' => 'A',
                    'fecha_creacion' => now(),
                    'fecha_actualizacion' => now(),
                ]);
    
                return response()->json([
                    'success' => true,
                    'message' => 'Router creado exitosamente.',
                    'data' => $router,
                ], 201);
            } else {
                throw new \Exception("No se pudo conectar al router proporcionado.");
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el router.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Actualizar un router existente
    public function ActualizarRouter(Request $request, $id)
    {
        $router = Router::find($id);
    
        if (!$router || $router->estado === 'E') {
            return response()->json([
                'success' => false,
                'message' => 'Router no encontrado o desactivado.',
            ], 404);
        }
    
        $validated = $request->validate([
            'nombre_router' => 'required|string|max:255',
            'descripcion_router' => 'nullable|string|max:255',
            'user' => 'required|string|max:100',
            'password' => 'required|string|max:100',
            'ip' => 'required|ip',
            'port' => 'required|integer|min:1|max:65535',
            'latitud' => 'nullable|numeric',
            'longitud' => 'nullable|numeric',
        ]);
    
        try {
            // Verificar conexión antes de actualizar
            $api = new RouterosAPI();
    
            if ($api->connect($validated['ip'], $validated['user'], $validated['password'], $validated['port'])) {
                // Si la conexión es exitosa, proceder con la actualización
                $router->update(array_merge($validated, [
                    'id_usuario_auditor' => Auth::id(),
                ]));
    
                $api->disconnect(); // Cerrar la conexión
    
                // Registrar la acción realizada
                AccionRealizada::create([
                    'id_usuario' => Auth::id(),
                    'descripcion' => "Se ha actualizado el router {$router->nombre_router}.",
                    'tipo_accion' => 'Router',
                    'estado' => 'A',
                    'fecha_creacion' => now(),
                    'fecha_actualizacion' => now(),
                ]);
    
                return response()->json([
                    'success' => true,
                    'message' => 'Router actualizado exitosamente.',
                    'data' => $router,
                ], 200);
            } else {
                // Si no se puede conectar, devolver un error
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo conectar al router. Verifica los datos proporcionados.',
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al intentar conectar o actualizar el router.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    

    // Eliminar un router (cambiar estado a 'E')
    public function EliminarRouter($id)
    {
        $router = Router::find($id);

        if (!$router || $router->estado === 'E') {
            return response()->json([
                'success' => false,
                'message' => 'Router no encontrado.',
            ], 404);
        }

        try {
            // --- 1) Verificar si tiene relaciones
            // ipPools activos
            $ipPoolsActivos = $router->ipPools()->where('estado','A')->count();
            // planes activos
            $planesActivos  = $router->planes()->where('estado','A')->count();

            if ($ipPoolsActivos > 0 || $planesActivos > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar este Router porque tiene IP Pools o Planes activos asociados.',
                ], 400);
            }

            // --- 2) Soft-delete
            $router->update([
                'estado' => 'E',
                'id_usuario_auditor' => Auth::id(),
            ]);

            // --- 3) Registrar acción
            AccionRealizada::create([
                'id_usuario' => Auth::id(),
                'descripcion' => "Se ha eliminado el router {$router->nombre_router}.",
                'tipo_accion' => 'Router',
                'estado' => 'A',
                'fecha_creacion' => now(),
                'fecha_actualizacion' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Router eliminado lógicamente.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el router.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
