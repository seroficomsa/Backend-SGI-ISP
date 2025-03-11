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
    public function obtenerOLT($id_olt)
    {
        // Convertir a entero
        $id = (int) $id_olt;
    
        // Validar manualmente para evitar errores
        if (!is_int($id) || $id <= 0) {
            return response()->json(["message" => "El ID de la OLT debe ser un entero válido."], 400);
        }
    
        // Buscar la OLT en la base de datos con su relación router
        $olt = Olt::with('router')->find($id);
    
        if (!$olt) {
            return response()->json(["message" => "OLT no encontrada."], 404);
        }
    
        try {
            // Conectar por SSH a la OLT
            $ssh = new \phpseclib3\Net\SSH2($olt->ip_olt, $olt->port_olt);
            if (!$ssh->login($olt->user_olt, $olt->passw_olt)) {
                throw new \Exception("Error de autenticación en la OLT.");
            }
    
            // Enviar comandos a la OLT
            $ssh->write("enable\r");
            sleep(1);
            $ssh->write("configure\r");
            sleep(1);
            $ssh->write("show system-info\r");
            sleep(2);
            $output = $ssh->read();
    
            // Registrar salida para depuración
            Log::info("📡 Respuesta de la OLT:\n" . $output);
    
            // Extraer datos de la OLT
            $data = [
                "system_description" => $this->extractValue($output, "System Description"),
                "system_name" => $this->extractValue($output, "System Name"),
                "location" => $this->extractValue($output, "System Location"),
                "contact_info" => $this->extractValue($output, "Contact Information"),
                "hardware_version" => $this->extractValue($output, "Hardware Version"),
                "software_version" => $this->extractValue($output, "Software Version"),
                "bootloader_version" => $this->extractValue($output, "Bootloader Version"),
                "mac_address" => $this->extractValue($output, "Mac Address"),
                "serial_number" => $this->extractValue($output, "Serial Number"),
                "system_time" => $this->extractValue($output, "System Time"),
                "running_time" => $this->extractValue($output, "Running Time"),
            ];
    
            // Cerrar conexión SSH
            $ssh->disconnect();
    
            // Combinar la información de la OLT de la base de datos con la obtenida en vivo
            $oltData = [
                "id_olt" => $olt->id,
                "nombre_olt" => $olt->nombre_olt,
                "ip_olt" => $olt->ip_olt,
                "estado" => $olt->estado,
                "fecha_creacion" => $olt->fecha_creacion,
                "fecha_actualizacion" => $olt->fecha_actualizacion,
                "data_otl" => $data,
            ];
    
            return response()->json($oltData, 200);
        } catch (\Exception $e) {
            Log::error("❌ Error en obtenerOLT: " . $e->getMessage());
            return response()->json(["error" => "Error al obtener datos de la OLT: " . $e->getMessage()], 500);
        }
    }
    
    /**
     * Extrae un valor de la respuesta de la OLT basado en una clave.
     */
    private function extractValue($output, $key)
    {
        if (preg_match('/' . preg_quote($key, '/') . '\s*-\s*(.+)/', $output, $matches)) {
            return trim($matches[1]);
        }
        return null;
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
