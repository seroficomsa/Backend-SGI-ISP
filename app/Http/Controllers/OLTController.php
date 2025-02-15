<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Olt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class OLTController extends Controller
{
    /**
     * Obtener todas las OLTs.
     */
    public function obtenerOLTs()
    {
        $olts = Olt::with('router')->get();
        
        foreach ($olts as $olt) {
            $olt->estado_olt = $this->verificarConexion($olt->ip_olt, $olt->port_olt);
        }

        return response()->json(['data' => $olts], 200);
    }
    /**
     * Obtener una OLT específica.
     */
    public function obtenerOLT(Request $request)
    {
        $request->validate([
            'id_olt' => 'required|integer|exists:olts,id_olt'
        ]);

        $olt = Olt::with('router')->find($request->id_olt);
        return response()->json($olt, 200);
    }

    /**
     * Crear una nueva OLT.
     */
    public function crearOLT(Request $request)
    {
        $request->validate([
            'id_router' => 'required|integer|exists:routers,id_router',
            'nombre_olt' => 'required|string|max:100',
            'descripcion_olt' => 'nullable|string',
            'user_olt' => 'required|string|max:100',
            'passw_olt' => 'required|string',
            'port_olt' => 'required|integer|min:1|max:65535',
            'ip_olt' => 'required|ip',
            'estado' => 'required|in:A,I,E'
        ]);

        $olt = Olt::create([
            'id_router' => $request->id_router,
            'nombre_olt' => $request->nombre_olt,
            'descripcion_olt' => $request->descripcion_olt,
            'user_olt' => $request->user_olt,
            'passw_olt' => $request->passw_olt,
            'port_olt' => $request->port_olt,
            'ip_olt' => $request->ip_olt,
            'estado' => $request->estado,
            'fecha_creacion' => now(),
            'fecha_actualizacion' => now(),
        ]);

        return response()->json(['message' => 'OLT creada correctamente', 'data' => $olt], 201);
    }

    /**
     * Actualizar una OLT existente.
     */
    public function actualizarOLT(Request $request)
    {
        $request->validate([
            'id_olt' => 'required|integer|exists:olts,id_olt',
            'id_router' => 'integer|exists:routers,id_router',
            'nombre_olt' => 'string|max:100',
            'descripcion_olt' => 'nullable|string',
            'user_olt' => 'string|max:100',
            'passw_olt' => 'nullable|string',
            'port_olt' => 'integer|min:1|max:65535',
            'ip_olt' => 'ip',
            'estado' => 'in:A,I,E'
        ]);

        $olt = Olt::find($request->id_olt);

        $olt->update([
            'id_router' => $request->id_router ?? $olt->id_router,
            'nombre_olt' => $request->nombre_olt ?? $olt->nombre_olt,
            'descripcion_olt' => $request->descripcion_olt ?? $olt->descripcion_olt,
            'user_olt' => $request->user_olt ?? $olt->user_olt,
            'passw_olt' => $request->passw_olt,
            'port_olt' => $request->port_olt ?? $olt->port_olt,
            'ip_olt' => $request->ip_olt ?? $olt->ip_olt,
            'estado' => $request->estado ?? $olt->estado,
            'fecha_actualizacion' => now(),
        ]);

        return response()->json(['message' => 'OLT actualizada correctamente', 'data' => $olt], 200);
    }

    /**
     * Eliminar (desactivar) una OLT.
     */
    public function eliminarOLT(Request $request)
    {
        $request->validate([
            'id_olt' => 'required|integer|exists:olts,id_olt'
        ]);

        $olt = Olt::find($request->id_olt);
        $olt->update(['estado' => 'E', 'fecha_actualizacion' => now()]);

        return response()->json(['message' => 'OLT eliminada correctamente'], 200);
    }


        /**
     * Hacer ping a la OLT para verificar si está activa.
     */
    private function verificarConexion($ip, $port)
    {
        $timeout = 2; // Segundos de espera para la conexión
        $connection = @fsockopen($ip, $port, $errno, $errstr, $timeout);

        if ($connection) {
            fclose($connection);
            return 'Activo';
        } else {
            return 'Inactivo';
        }
    }
}
