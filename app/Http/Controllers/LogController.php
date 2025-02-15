<?php

namespace App\Http\Controllers;

use App\Models\AccionRealizada;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * Obtiene todos los registros de la tabla acciones_realizadas junto con los datos del usuario relacionado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function obtenerRegistros()
    {
        try {
            // Obtiene todas las acciones realizadas con los datos del usuario en orden descendente
            $acciones = AccionRealizada::with('usuario')->orderBy('fecha_creacion', 'desc')->get();
    
            return response()->json([
                'success' => true,
                'data' => $acciones
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los registros.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
}
