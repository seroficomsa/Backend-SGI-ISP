<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Router;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Libraries\RouterosAPI;
use App\Models\AccionRealizada;
use App\Models\IpPool;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    // Listar planes
    public function listarPlanes()
    {
        $planes = Plan::with(['router', 'ippool'])->where('estado', 'A')->get();
    
        foreach ($planes as $plan) {
            $router = $plan->router;
            $ippool = $plan->ippool;
    
            if (!$router || $router->estado !== 'A') {
                $plan->estado_mikrotik = 'Desconocido';
                continue;
            }
    
            $api = new RouterosAPI();
            try {
                if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
                    // Verificar si el perfil existe en MikroTik
                    $api->write('/ppp/profile/print', false);
                    $api->write('?name=' . $plan->nombre_plan, true);
                    $response = $api->read();
                    $existingProfile = $response[0] ?? null;
    
                    $plan->estado_mikrotik = $existingProfile ? 'Activo' : 'No Existe';
    
                    // Agregar información del IP Pool
                    $plan->nombre_pool = $ippool ? $ippool->nombre_pool : "Sin IP Pool";
    
                    $api->disconnect();
                } else {
                    $plan->estado_mikrotik = 'No conectado';
                }
            } catch (\Exception $e) {
                $plan->estado_mikrotik = 'Error';
            }
        }
    
        return response()->json([
            'success' => true,
            'data' => $planes,
        ]);
    }
    

    public function repararPlanMikroTik($id)
    {
        $plan = Plan::find($id);

        if (!$plan || $plan->estado === 'E') {
            return response()->json([
                'success' => false,
                'message' => 'Plan no encontrado.',
            ], 404);
        }

        $router = Router::find($plan->id_router);
        if (!$router || $router->estado !== 'A') {
            return response()->json([
                'success' => false,
                'message' => 'Router no encontrado o inactivo.',
            ], 404);
        }

        $api = new RouterosAPI();
        try {
            if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
                // Crear el perfil en MikroTik
                $api->write('/ppp/profile/add', false);
                $api->write('=name=' . $plan->nombre_plan, false);
                $api->write('=rate-limit=' . $plan->mb_subida . 'M/' . $plan->mb_bajada . 'M', true);

                $response = $api->read();
                $api->disconnect();

                return response()->json([
                    'success' => true,
                    'message' => 'Perfil reparado y agregado al MikroTik.',
                    'mikrotik_response' => $response,
                ]);
            } else {
                throw new \Exception('No se pudo conectar al MikroTik del router.');
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al reparar el perfil en MikroTik: ' . $e->getMessage(),
            ], 500);
        }
    }


    // Crear un nuevo plan
    public function crearPlan(Request $request)
    {
        $validated = $request->validate([
            'id_router' => 'required|exists:routers,id_router',
            'id_ippool' => 'required|exists:ip_pools,id_ippool',
            'nombre_plan' => 'required|string|max:100',
            'descripcion_plan' => 'nullable|string',
            'mb_subida' => 'required|integer|min:1',
            'mb_bajada' => 'required|integer|min:1',
            'precio' => 'required|numeric|min:0',
        ]);
    
        $router = Router::find($validated['id_router']);
        $ipPool = IpPool::find($validated['id_ippool']);
    
        if (!$router || $router->estado !== 'A' || !$ipPool) {
            return response()->json([
                'success' => false,
                'message' => 'Router o IP Pool no encontrado o inactivo.',
            ], 404);
        }
    
        $plan = Plan::create([
            'id_router' => $validated['id_router'],
            'id_ippool' => $validated['id_ippool'],
            'nombre_plan' => $validated['nombre_plan'],
            'descripcion_plan' => $validated['descripcion_plan'],
            'mb_subida' => $validated['mb_subida'],
            'mb_bajada' => $validated['mb_bajada'],
            'precio' => $validated['precio'],
            'estado' => 'A',
            'id_usuario_auditor' => Auth::id(),
        ]);
    
        // Extraer la IP sin la máscara, por ejemplo "192.168.1.0" en lugar de "192.168.1.0/24"
        $localAddress = explode('/', $ipPool->subnet)[0];
    
        $api = new RouterosAPI();
        try {
            if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
                $api->write('/ppp/profile/add', false);
                $api->write('=name=' . $validated['nombre_plan'], false);
                $api->write('=rate-limit=' . $validated['mb_subida'] . 'M/' . $validated['mb_bajada'] . 'M', false);
                $api->write('=local-address=' . $localAddress, false); // Usar la IP sin máscara
                $api->write('=remote-address=' . $ipPool->nombre_pool, false);
                $api->write('=comment=Plan creado el ' . now(), true);
    
                $response = $api->read();
                $api->disconnect();
    
                return response()->json([
                    'success' => true,
                    'message' => 'Plan creado y perfil agregado al MikroTik con IP Pool.',
                    'data' => $plan,
                    'mikrotik_response' => $response,
                ]);
            } else {
                throw new \Exception('No se pudo conectar al MikroTik.');
            }
        } catch (\Exception $e) {
            $plan->delete();
    
            return response()->json([
                'success' => false,
                'message' => 'Error en MikroTik: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    
    // Editar un plan
    public function actualizarPlan(Request $request, $id) 
    {
        $plan = Plan::find($id);
    
        if (!$plan || $plan->estado === 'E') {
            return response()->json([
                'success' => false,
                'message' => 'Plan no encontrado.',
            ], 404);
        }
    
        $validated = $request->validate([
            'id_router' => 'required|exists:routers,id_router',
            'id_ippool' => 'required|exists:ip_pools,id_ippool',
            'nombre_plan' => 'required|string|max:100',
            'descripcion_plan' => 'nullable|string',
            'mb_subida' => 'required|integer|min:1',
            'mb_bajada' => 'required|integer|min:1',
            'precio' => 'required|numeric|min:0', // Agregar validación para el precio
        ]);
    
        $router = Router::find($validated['id_router']);
        $ipPool = IpPool::find($validated['id_ippool']);
    
        if (!$router || $router->estado !== 'A' || !$ipPool) {
            return response()->json([
                'success' => false,
                'message' => 'Router o IP Pool no encontrado o inactivo.',
            ], 404);
        }
    
        // **Eliminar la máscara de red `/x` del `subnet`**
        $subnet = explode('/', $ipPool->subnet)[0];
    
        // **Conectar a MikroTik**
        $api = new RouterosAPI();
        try {
            if (!$api->connect($router->ip, $router->user, $router->password, $router->port)) {
                throw new \Exception('No se pudo conectar al MikroTik.');
            }
    
            // **Verificar si el pool de IP realmente existe en MikroTik**
            $api->write('/ip/pool/print', true);
            $pools = $api->read();
            
            $poolExists = false;
            foreach ($pools as $pool) {
                if (isset($pool['name']) && $pool['name'] === $ipPool->nombre_pool) {
                    $poolExists = true;
                    break;
                }
            }
    
            if (!$poolExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error: El pool de IP "' . $ipPool->nombre_pool . '" no existe en MikroTik.',
                ], 400);
            }
    
            // **Buscar el perfil PPP en MikroTik**
            $api->write('/ppp/profile/print', false);
            $api->write('?name=' . $plan->nombre_plan, true);
            $response = $api->read();
    
            Log::info("Respuesta MikroTik al buscar perfil: ", $response);
    
            if (!isset($response[0]) || !is_array($response[0]) || !array_key_exists('name', $response[0])) {
                Log::warning("Perfil PPP no encontrado en MikroTik. Se procederá a crearlo.");
    
                // **Crear el perfil en MikroTik**
                $api->write('/ppp/profile/add', false);
                $api->write('=name=' . $validated['nombre_plan'], false);
                $api->write('=rate-limit=' . $validated['mb_subida'] . 'M/' . $validated['mb_bajada'] . 'M', false);
                $api->write('=local-address=' . $subnet, false);
                $api->write('=remote-address=' . $ipPool->nombre_pool, false);
                $api->write('=comment=Perfil creado automáticamente en ' . now(), true);
    
                $createResponse = $api->read();
                Log::info("Perfil creado en MikroTik: ", $createResponse);
                
                $plan->update(array_merge($validated, [
                    'id_usuario_auditor' => Auth::id(),
                ]));
    
                return response()->json([
                    'success' => true,
                    'message' => 'Perfil creado exitosamente en MikroTik.',
                    'data' => $plan,
                    'mikrotik_response' => $createResponse
                ]);
            }
    
            // **Si el perfil ya existe, actualizarlo**
            $existingProfile = $response[0];
    
            $api->write('/ppp/profile/set', false);
            $api->write('=.id=' . $existingProfile['.id'], false);
            $api->write('=name=' . $validated['nombre_plan'], false);
            $api->write('=rate-limit=' . $validated['mb_subida'] . 'M/' . $validated['mb_bajada'] . 'M', false);
            $api->write('=local-address=' . $subnet, false);
            $api->write('=remote-address=' . $ipPool->nombre_pool, false);
            $api->write('=comment=Plan actualizado el ' . now(), true);
    
            $updateResponse = $api->read();
            $api->disconnect();
    
            // **Actualizar en la base de datos**
            $plan->update(array_merge($validated, [
                'id_usuario_auditor' => Auth::id(),
            ]));
    
            return response()->json([
                'success' => true,
                'message' => 'Plan actualizado en la base de datos y en MikroTik.',
                'data' => $plan,
                'mikrotik_response' => $updateResponse,
            ]);
    
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar en MikroTik: ' . $e->getMessage(),
            ], 500);
        }
    }
    

    // Eliminar un plan
    public function eliminarPlan($id)
    {
        $plan = Plan::find($id);

        if (!$plan || $plan->estado === 'E') {
            return response()->json([
                'success' => false,
                'message' => 'Plan no encontrado.',
            ], 404);
        }

        // Obtener el router asociado
        $router = Router::find($plan->id_router);
        if (!$router || $router->estado !== 'A') {
            return response()->json([
                'success' => false,
                'message' => 'Router no encontrado o inactivo.',
            ], 404);
        }

        // Conexión a MikroTik
        $api = new RouterosAPI();
        try {
            if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
                // Buscar y eliminar el perfil en el MikroTik
                $api->write('/ppp/profile/print', false);
                $api->write('?name=' . $plan->nombre_plan, true);
                $response = $api->read();
                $existingProfile = $response[0] ?? null;

                if ($existingProfile) {
                    $api->write('/ppp/profile/remove', false);
                    $api->write('=.id=' . $existingProfile['.id'], true);
                    $deleteResponse = $api->read();
                }

                $api->disconnect();
            } else {
                throw new \Exception('No se pudo conectar al MikroTik del router.');
            }

            // Actualizar el estado del plan en la base de datos
            $plan->update([
                'estado' => 'E',
                'id_usuario_auditor' => Auth::id(),
            ]);

            // Guardar acción realizada
            AccionRealizada::create([
                'id_usuario' => Auth::id(),
                'descripcion' => 'Se ha eliminado el plan ' . $plan->nombre_plan,
                'tipo_accion' => 'Plan',
                'estado' => 'A',
                'fecha_creacion' => now(),
                'fecha_actualizacion' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Plan eliminado lógicamente y perfil eliminado del MikroTik.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el perfil en MikroTik: ' . $e->getMessage(),
            ], 500);
        }
    }
}