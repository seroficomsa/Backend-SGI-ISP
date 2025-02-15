<?php

namespace App\Http\Controllers;

use App\Libraries\RouterosAPI;
use Illuminate\Http\Request;
use App\Models\IpPool;
use App\Models\Router;
use App\Models\AccionRealizada;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class IpPoolController extends Controller
{
    /**
     * Listar IP Pools activos con su estado en MikroTik.
     */
    public function listarIPPool()
    {
        $ippools = IpPool::with('router')
            ->where('estado', 'A')
            ->get();

        // Para cada Pool, revisamos en Mikrotik si existe (usando su nombre)
        foreach ($ippools as $pool) {
            $router = $pool->router;
            if ($router) {
                $pool->estado_mikrotik = $this->estadoPoolEnMikrotik($router, $pool->nombre_pool);
            } else {
                $pool->estado_mikrotik = 'Sin router';
            }
        }

        return response()->json(['success' => true, 'data' => $ippools]);
    }

    /**
     * Crear (GUARDAR) IP Pool en MikroTik y en la BD.
     */
    public function guardarIPPool(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_router'   => 'required|exists:routers,id_router',
            'nombre_pool' => 'required|string|max:100',
            'subnet'      => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => $validator->errors()
            ], 400);
        }

        $rango = $this->calcularRangoIP($request->subnet);
        if (!$rango) {
            return response()->json(['success' => false, 'error' => 'Subred inválida'], 400);
        }

        $router = Router::findOrFail($request->id_router);
        $api = new RouterosAPI();

        try {
            if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
                $api->write('/ip/pool/add', false);
                $api->write('=name=' . $request->nombre_pool, false);
                $api->write('=ranges=' . $rango, true);
                $api->read();
                $api->disconnect();

                $ipPool = IpPool::create([
                    'id_router'   => $request->id_router,
                    'nombre_pool' => $request->nombre_pool,
                    'subnet'      => $request->subnet,
                    'rango_ip'    => $rango,
                    'estado'      => 'A',
                ]);

                AccionRealizada::create([
                    'id_usuario' => Auth::id(),
                    'descripcion' => "Se ha creado el IP Pool '{$request->nombre_pool}'",
                    'tipo_accion' => 'IP',
                    'estado' => 'A',
                    'fecha_creacion' => now(),
                    'fecha_actualizacion' => now(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'IP Pool creado correctamente.',
                    'data'    => $ipPool
                ], 201);
            } else {
                return response()->json(['success' => false, 'error' => 'No se pudo conectar a MikroTik'], 400);
            }
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar IP Pool en MikroTik y BD.
     */
    public function actualizarIPPool(Request $request, $id_ippool)
    {
        $ipPool = IpPool::findOrFail($id_ippool);
    
        $request->validate([
            'nombre_pool' => 'required|string|max:100',
            'subnet'      => 'required|string|max:20',
        ]);
    
        $rango = $this->calcularRangoIP($request->subnet);
        if (!$rango) {
            return response()->json(['success' => false, 'error' => 'Subred inválida'], 400);
        }
    
        $router = Router::findOrFail($ipPool->id_router);
        $api = new RouterosAPI();
    
        // Nombre anterior que existe en Mikrotik
        $oldName = $ipPool->nombre_pool;
        // Nombre nuevo que el usuario desea
        $newName = $request->nombre_pool;
    
        try {
            if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
                // Buscamos en Mikrotik un pool con 'oldName'
                $api->write('/ip/pool/print', false);
                $api->write('?name=' . $oldName, true); // Ojo: sintaxis "?name=" no "?.name="
                $response = $api->read();
    
                // Checamos si lo encontró
                if (isset($response[0]['.id'])) {
                    $poolID = $response[0]['.id'];
    
                    // Actualizamos (renombramos y cambiamos rangos)
                    $api->write('/ip/pool/set', false);
                    $api->write('=.id=' . $poolID, false);
                    $api->write('=name=' . $newName, false);
                    $api->write('=ranges=' . $rango, true);
                    $api->read();
                } else {
                    return response()->json([
                        'success' => false,
                        'error'   => "No se encontró un Pool con el nombre '$oldName' en MikroTik"
                    ], 400);
                }
                $api->disconnect();
            } else {
                return response()->json(['success' => false, 'error' => 'No se pudo conectar a MikroTik'], 400);
            }
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    
        // Actualizamos en la BD
        $ipPool->update([
            'nombre_pool' => $newName,
            'subnet'      => $request->subnet,
            'rango_ip'    => $rango,
        ]);
    
        return response()->json(['success' => true, 'message' => 'IP Pool actualizado correctamente.']);
    }
    
/**
 * Eliminar un IP Pool: Cambia estado a "E" y lo elimina de MikroTik.
 */
public function eliminarIPPool($id)
{
    $ipPool = IpPool::findOrFail($id);
    $router = $ipPool->router;

    if (!$router) {
        return response()->json(['success' => false, 'error' => 'Router no encontrado'], 400);
    }

    $api = new RouterosAPI();
    try {
        if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
            // Buscar el pool en MikroTik
            $api->write('/ip/pool/print', false);
            $api->write('?name=' . $ipPool->nombre_pool, true);
            $response = $api->read();

            // Si el pool existe en MikroTik, lo eliminamos
            if (isset($response[0]['.id'])) {
                $api->write('/ip/pool/remove', false);
                $api->write('=.id=' . $response[0]['.id'], true);
                $api->read();
            }

            $api->disconnect();
        }
    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'error' => 'No se pudo eliminar de MikroTik: ' . $e->getMessage()
        ], 500);
    }

    // Cambiar el estado en la base de datos a "E"
    $ipPool->update(['estado' => 'E']);

    // Registrar la acción
    AccionRealizada::create([
        'id_usuario' => Auth::id(),
        'descripcion' => 'Se ha eliminado el IP Pool ' . $ipPool->nombre_pool,
        'tipo_accion' => 'IP',
        'estado' => 'A',
        'fecha_creacion' => now(),
        'fecha_actualizacion' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'IP Pool eliminado de MikroTik y desactivado en la base de datos.'
    ]);
}


    /**
     * -------------------------------------------------------------------
     * FUNCIONES AUXILIARES
     * -------------------------------------------------------------------
     */

    /**
     * Retorna el rango "startIP-endIP" para la subred dada,
     * comenzando en network+2 y terminando en broadcast-1.
     *
     * Ej. "192.168.0.1/24" => "192.168.0.2-192.168.0.254"
     */
    private function calcularRangoIP($subnet)
    {
        $parts = explode('/', $subnet);
        if (count($parts) !== 2) {
            return null; // Formato inválido
        }
    
        list($ip, $prefix) = $parts;
        $prefix = (int)$prefix;
    
        // Validar prefijo en 0..32
        if ($prefix < 0 || $prefix > 32) {
            return null;
        }
    
        // Convertir la IP a entero (32 bits)
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return null; // IP inválida
        }
    
        // Construir la máscara de red en entero
        //   Ej: /24 => 0xFFFFFF00
        //   /23 => 0xFFFFFE00, etc.
        $netmaskLong = 0xFFFFFFFF << (32 - $prefix);
    
        // Network address: IP base & netmask
        //   Si pasas "192.168.0.5/24", su network real es "192.168.0.0"
        $networkLong = $ipLong & $netmaskLong;
    
        // Broadcast: networkLong | ~netmaskLong
        //   /24 => .255
        //   /23 => .255 en la parte baja, etc.
        $broadcastLong = $networkLong | (~$netmaskLong & 0xFFFFFFFF);
    
        // Primer host => network + 2
        //  (se salta .0 y .1)
        $startLong = $networkLong + 2;
    
        // Último host => broadcast - 1
        $endLong = $broadcastLong - 1;
    
        // Si la subred es muy chica (por ej. /30), podría no haber hosts al saltar .0 y .1
        if ($startLong > $endLong) {
            return null; 
        }
    
        // Convertir de nuevo a notación "x.x.x.x"
        $startIP = long2ip($startLong);
        $endIP   = long2ip($endLong);
    
        return "$startIP-$endIP";
    }


    /**
     * Verifica si un pool con $nombre existe en el router.
     * Devuelve:
     *  - "Activo" si existe
     *  - "No Existe" si no está
     *  - "Error de conexión" si no pudo conectar
     */
    private function estadoPoolEnMikrotik(Router $router, $nombre)
    {
        $api = new RouterosAPI();
        if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
            $api->write('/ip/pool/print', true);
            $response = $api->read();
            $api->disconnect();

            Log::alert("Respuesta MikroTik para router {$router->nombre_router}: " . json_encode($response));

            foreach ($response as $entry) {
                if (isset($entry['name']) && $entry['name'] === $nombre) {
                    return 'Activo';
                }
            }
            return 'No Existe';
        } else {
            return 'Error de conexión';
        }
    }

    /**
     * Reparar (recrear) un IP Pool en MikroTik.
     */
    public function repararIPPool($id)
    {
        $ipPool = IpPool::findOrFail($id);
        $router = $ipPool->router;

        if (!$router) {
            return response()->json(['success' => false, 'error' => 'Router no encontrado'], 400);
        }

        $api = new RouterosAPI();

        try {
            if ($api->connect($router->ip, $router->user, $router->password, $router->port)) {
                $api->write('/ip/pool/add', false);
                $api->write('=name=' . $ipPool->nombre_pool, false);
                $api->write('=ranges=' . $ipPool->rango_ip, true);
                $api->read();
                $api->disconnect();

                AccionRealizada::create([
                    'id_usuario' => Auth::id(),
                    'descripcion' => "Se ha reparado el IP Pool '{$ipPool->nombre_pool}'",
                    'tipo_accion' => 'IP',
                    'estado' => 'A',
                    'fecha_creacion' => now(),
                    'fecha_actualizacion' => now(),
                ]);

                return response()->json(['success' => true, 'message' => 'IP Pool reparado correctamente.']);
            } else {
                return response()->json(['success' => false, 'error' => 'No se pudo conectar a MikroTik'], 400);
            }
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
