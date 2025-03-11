<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Ont;
use App\Models\AccionRealizada;
use App\Models\AsignacionServicioCliente;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OntController extends Controller
{
    /**
     * Obtener todas las ONTs activas.
     */
    public function obtenerOnts()
    {
        // Obtener todas las ONTs activas
        $onts = Ont::where('estado', '!=', 'E')
            ->get()
            ->map(function ($ont) {
                // Verificar si la ONT está en uso en la tabla asignacion_servicio_clientes
                $enUso = AsignacionServicioCliente::where('id_ont', $ont->id_ont)
                    ->where('estado', 'A')
                    ->exists(); // Si existe una asignación activa, está en uso
    
                // Agregar el campo "enuso" al objeto
                $ont->enuso = $enUso;
    
                return $ont;
            });
    
        return response()->json(['data' => $onts], 200);
    }
    
    /**
     * Obtener una ONT específica.
     */
    public function mostrarOnt($id_ont)
    {
        try {
            $ont = Ont::where('id_ont', $id_ont)->where('estado', '!=', 'E')->firstOrFail();
            return response()->json(['data' => $ont], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'ONT no encontrada'], 404);
        }
    }

    /**
     * Crear o reactivar una ONT en el inventario.
     */
    public function guardarOnt(Request $request)
    {
        $request->validate([
            'modelo' => 'required|string|max:100',
            'sn' => 'required|string|max:50',
            'gpon_sn' => 'required|string|max:50',
            'mac' => 'required|string|max:17',
        ]);

        $usuarioAuditor = auth()->user();

        // Buscar si ya existe con estado "E" (Eliminado)
        $ont = Ont::where(function ($query) use ($request) {
            $query->where('sn', $request->sn)
                  ->orWhere('gpon_sn', $request->gpon_sn)
                  ->orWhere('mac', $request->mac);
        })->first();

        if ($ont && $ont->estado === 'E') {
            // Reactivar la ONT eliminada
            $ont->update([
                'modelo' => $request->modelo,
                'estado' => 'A',
                'fecha_actualizacion' => now(),
            ]);
            $mensaje = "Se reactivó la ONT SN {$ont->sn}";
        } elseif (!$ont) {
            // Crear nueva ONT si no existe
            $ont = Ont::create([
                'modelo' => $request->modelo,
                'sn' => $request->sn,
                'gpon_sn' => $request->gpon_sn,
                'mac' => $request->mac,
                'estado' => 'A',
                'fecha_creacion' => now(),
                'fecha_actualizacion' => now(),
            ]);
            $mensaje = "Se realizó la creación de la ONT SN {$ont->sn}";
        } else {
            return response()->json(['error' => 'SN, GPON_SN o MAC ya están en uso.'], 422);
        }

        // Registrar acción
        AccionRealizada::create([
            'id_usuario' => $usuarioAuditor->id,
            'descripcion' => $mensaje,
            'tipo_accion' => 'ONT',
            'estado' => 'A',
            'fecha_creacion' => now(),
            'fecha_actualizacion' => now(),
        ]);

        return response()->json(['message' => $mensaje, 'data' => $ont], 201);
    }

    /**
     * Actualizar una ONT existente.
     */
    public function actualizarOnt(Request $request)
    {
        $request->validate([
            'id_ont' => 'required|integer|exists:onts,id_ont',
            'modelo' => 'string|max:100',
            'sn' => 'string|max:50|unique:onts,sn,' . $request->id_ont . ',id_ont',
            'gpon_sn' => 'string|max:50|unique:onts,gpon_sn,' . $request->id_ont . ',id_ont',
            'mac' => 'string|max:17|unique:onts,mac,' . $request->id_ont . ',id_ont',
            'estado' => 'in:A,I,E',
        ]);
        
        $usuarioAuditor = auth()->user();

        $ont = Ont::findOrFail($request->id_ont);
        $ont->update([
            'modelo' => $request->modelo ?? $ont->modelo,
            'sn' => $request->sn ?? $ont->sn,
            'gpon_sn' => $request->gpon_sn ?? $ont->gpon_sn,
            'mac' => $request->mac ?? $ont->mac,
            'estado' => $request->estado ?? $ont->estado,
            'fecha_actualizacion' => now(),
        ]);

        $mensaje = "Se actualizó la ONT SN {$ont->sn}";

        AccionRealizada::create([
            'id_usuario' => $usuarioAuditor->id,
            'descripcion' => $mensaje,
            'tipo_accion' => 'ONT',
            'estado' => 'A',
            'fecha_creacion' => now(),
            'fecha_actualizacion' => now(),
        ]);

        return response()->json(['message' => $mensaje, 'data' => $ont], 200);
    }

    /**
     * Eliminar (desactivar) una ONT.
     */
    public function eliminarOnt(Request $request)
    {
        $request->validate([
            'id_ont' => 'required|integer|exists:onts,id_ont'
        ]);

        $usuarioAuditor = auth()->user();

        $ont = Ont::findOrFail($request->id_ont);
        if ($ont->estado === 'I') {
            return response()->json(['message' => 'No puedes eliminar una ONT asignada a un cliente.'], 400);
        }

        $ont->update([
            'estado' => 'E',
            'fecha_actualizacion' => now(),
        ]);

        $mensaje = "Se eliminó la ONT SN {$ont->sn}";

        AccionRealizada::create([
            'id_usuario' => $usuarioAuditor->id,
            'descripcion' => $mensaje,
            'tipo_accion' => 'ONT',
            'estado' => 'A',
            'fecha_creacion' => now(),
            'fecha_actualizacion' => now(),
        ]);

        return response()->json(['message' => $mensaje], 200);
    }
}
