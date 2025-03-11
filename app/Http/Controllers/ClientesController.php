<?php

namespace App\Http\Controllers;

use App\Libraries\RouterosAPI;
use Illuminate\Http\Request;
use App\Models\Cliente;
use App\Models\Usuario;
use App\Models\UsuarioCliente;
use App\Models\InformacionAdicionalCliente;
use App\Models\AccionRealizada;
use App\Models\AsignacionServicioCliente;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\Router;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use phpseclib3\Net\SSH2;

class ClientesController extends Controller
{
    /**
     * Listar todos los clientes.
     */
    public function listarClientes()
    {
        // Primero obtener los clientes
        $clientes = Cliente::with([
            'informacionAdicional',
            'usuariosClientes.usuario' => function ($query) {
                $query->where('id_rol', 4);
            }
        ])
            ->whereHas('usuariosClientes.usuario', function ($query) {
                $query->where('id_rol', 4);
            })
            ->whereIn('estado', ['A', 'I'])
            ->get();

        // ======== COMPROBAR EN MIKROTIK SI EXISTE EL SECRET (opcional) ========
        // Recorremos y agregamos un campo "existe_mikrotik" => true/false
        // Podrías tener un método "checkSecretEnMikrotik($login)" que conecte y verifique
        // si /ppp/secret/print where name=...
        // Cuidado con performance si son muchos clientes (podrías cachear).
        $router = Router::where('estado', 'A')->first();
        // Ejemplo: si solo tienes 1 router o, mejor, 
        // habría que determinar con qué router se asocia el cliente.

        $api = new RouterosAPI();
        if ($router && $api->connect($router->ip, $router->user, $router->password, $router->port)) {
            foreach ($clientes as $cliente) {
                if ($cliente->estado === 'A') {
                    // Buscar si existe secret con name=$cliente->login
                    $api->write('/ppp/secret/print', false);
                    $api->write('?name=' . $cliente->login, true);
                    $secrets = $api->read();

                    // Si $secrets no está vacío, existe en Mikrotik
                    $cliente->existe_mikrotik = count($secrets) > 0;
                } else {
                    $cliente->existe_mikrotik = false;
                }
            }
            $api->disconnect();
        } else {
            // no se pudo conectar, por defecto set false o algo
            foreach ($clientes as $cliente) {
                $cliente->existe_mikrotik = false;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $clientes,
        ], 200);
    }



    public function verificarCorreo(Request $request)
    {
        $request->validate([
            'correo_electronico' => 'required|email',
        ]);

        // Solo contar clientes con estado A o I
        // (Si está en E, no se considera "registrado")
        $correoExiste = Cliente::where('correo_electronico', $request->correo_electronico)
            ->whereIn('estado', ['A', 'I'])
            ->exists();

        if ($correoExiste) {
            return response()->json([
                'success' => true,
                'exists' => true,
                'message' => 'El correo electrónico ya está registrado.',
            ], 200);
        }

        return response()->json([
            'success' => true,
            'exists' => false,
            'message' => 'El correo electrónico no está registrado.',
        ], 200);
    }

    /**
     * Crear un nuevo cliente.
     */


    // public function guardarCliente(Request $request)
    // {
    //     $usuarioAuditor = auth()->user();

    //     // 1️⃣ **Validar conexión a MikroTik antes de hacer cambios**
    //     $router = Router::find($request->id_router);
    //     if (!$router || $router->estado !== 'A') {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'El router especificado no existe o está inactivo.',
    //         ], 400);
    //     }

    //     $api = new RouterosAPI();
    //     $api->debug = false;

    //     if (!$api->connect($router->ip, $router->user, $router->password, $router->port)) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'No se pudo conectar al MikroTik del router. Proceso detenido.',
    //         ], 500);
    //     }

    //     // 2️⃣ **Iniciar transacción para evitar datos inconsistentes**
    //     DB::beginTransaction();

    //     try {
    //         // Validaciones
    //         $validator = Validator::make($request->all(), [
    //             'nombres' => 'required|string|max:255',
    //             'apellidos' => 'required|string|max:255',
    //             'identificacion' => 'required|string|max:20',
    //             'correo_electronico' => 'required|string|email|max:255',
    //             'fecha_nacimiento' => 'nullable|date',
    //             'estado' => 'required|string|in:A,I',
    //             'telefono_principal' => 'required|string|max:20',
    //             'telefono_secundario' => 'nullable|string|max:20',
    //             'direccion' => 'nullable|string|max:255',
    //             'referencia_direccion' => 'nullable|string|max:255',
    //             'latitud' => 'nullable|numeric',
    //             'longitud' => 'nullable|numeric',
    //             'id_plan' => 'required|exists:planes,id_plan',
    //             'id_router' => 'nullable|exists:routers,id_router',
    //             'id_ippool' => 'nullable|exists:ip_pools,id_ippool',
    //             'id_ont' => 'nullable|exists:onts,id_ont', // Nueva validación para ONT
    //         ]);

    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'success' => false,
    //                 'errors' => $validator->errors(),
    //             ], 422);
    //         }

    //         // Generar LOGIN
    //         $fecha = now()->format('d');
    //         $nombres = explode(" ", $request->nombres);
    //         $apellidos = explode(" ", $request->apellidos);

    //         $inicialesNombres = implode("", array_map(
    //             fn($name) => strtoupper(substr($name, 0, 1)),
    //             $nombres
    //         ));
    //         $inicialesApellidos = implode("", array_map(
    //             fn($last) => strtoupper(substr($last, 0, 1)),
    //             $apellidos
    //         ));

    //         $baseLogin = "I-{$inicialesNombres}{$inicialesApellidos}{$fecha}";
    //         $contador = 1;
    //         do {
    //             $login = $baseLogin . '-' . str_pad($contador, 3, '0', STR_PAD_LEFT);
    //             $contador++;
    //         } while (Cliente::where('login', $login)->exists());

    //         // Crear Cliente
    //         $cliente = Cliente::create([
    //             'nombres' => $request->nombres,
    //             'apellidos' => $request->apellidos,
    //             'login' => $login,
    //             'identificacion' => $request->identificacion,
    //             'correo_electronico' => $request->correo_electronico,
    //             'fecha_nacimiento' => $request->fecha_nacimiento,
    //             'estado' => $request->estado,
    //         ]);

    //         // Crear Información Adicional
    //         InformacionAdicionalCliente::create([
    //             'id_cliente' => $cliente->id_cliente,
    //             'telefono_principal' => $request->telefono_principal,
    //             'telefono_secundario' => $request->telefono_secundario,
    //             'direccion' => $request->direccion,
    //             'referencia_direccion' => $request->referencia_direccion,
    //             'latitud' => $request->latitud,
    //             'longitud' => $request->longitud,
    //             'estado' => 'A',
    //         ]);

    //         // Generar Password
    //         $allChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789@#$-_';
    //         $passLength = 10;
    //         $rawPassword = '';
    //         for ($i = 0; $i < $passLength; $i++) {
    //             $rawPassword .= $allChars[random_int(0, strlen($allChars) - 1)];
    //         }

    //         // Crear Usuario
    //         $usuario = Usuario::create([
    //             'id_rol' => 4,
    //             'nombres' => $request->nombres,
    //             'apellidos' => $request->apellidos,
    //             'identificacion' => $request->identificacion,
    //             'correo_electronico' => $request->correo_electronico,
    //             'password' => Hash::make($rawPassword),
    //             'estado' => 'A',
    //         ]);

    //         // Relación Usuario-Cliente
    //         UsuarioCliente::create([
    //             'id_usuario' => $usuario->id_usuario,
    //             'id_cliente' => $cliente->id_cliente,
    //             'estado' => 'A',
    //             'fecha_creacion' => now(),
    //             'fecha_actualizacion' => now(),
    //         ]);

    //         // Asignación de Servicio con ONT
    //         AsignacionServicioCliente::create([
    //             'id_cliente' => $cliente->id_cliente,
    //             'id_plan' => $request->id_plan,
    //             'id_router' => $request->id_router,
    //             'id_ippool' => $request->id_ippool,
    //             'id_ont' => $request->id_ont, // Nueva relación con ONT
    //             'estado' => 'A',
    //             'fecha_creacion' => now(),
    //             'fecha_actualizacion' => now(),
    //         ]);

    //         // Crear PPP Secret en MikroTik
    //         $plan = Plan::find($request->id_plan);
    //         $api->write('/ppp/secret/add', false);
    //         $api->write('=name=' . $login, false);
    //         $api->write('=password=' . $rawPassword, false);
    //         $api->write('=service=pppoe', false);
    //         $api->write('=profile=' . $plan->nombre_plan, false);
    //         $api->write('=comment=' . strtoupper($cliente->nombres) . ' ' . strtoupper($cliente->apellidos), true);

    //         $responseAPI = $api->read();
    //         $api->disconnect();

    //         // ✅ Confirmar transacción
    //         DB::commit();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Cliente creado exitosamente con asignación de servicio y PPPoE en MikroTik.',
    //             'cliente' => $cliente,
    //             'usuario' => $usuario,
    //             'password_generada' => $rawPassword,
    //             'mikrotik_response' => $responseAPI
    //         ], 201);

    //     } catch (\Exception $e) {
    //         // ❌ Revertir todo si hay error
    //         DB::rollBack();
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error en el proceso: ' . $e->getMessage(),
    //         ], 500);
    //     }
    // }
    public function guardarCliente(Request $request)
    {
        $usuarioAuditor = auth()->user();

        // 1️⃣ Validar que el router exista y esté activo
        $router = Router::find($request->id_router);
        if (!$router || $router->estado !== 'A') {
            return response()->json([
                'success' => false,
                'message' => 'El router especificado no existe o está inactivo.',
            ], 400);
        }

        // Conectar a MikroTik
        $api = new RouterosAPI();
        $api->debug = false;
        if (!$api->connect($router->ip, $router->user, $router->password, $router->port)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar al MikroTik del router. Proceso detenido.',
            ], 500);
        }

        // 2️⃣ Iniciar transacción para garantizar la consistencia
        DB::beginTransaction();

        try {
            // Validar datos del request
            $validator = Validator::make($request->all(), [
                'nombres'             => 'required|string|max:255',
                'apellidos'           => 'required|string|max:255',
                'identificacion'      => 'required|string|max:20',
                'correo_electronico'  => 'required|string|email|max:255',
                'fecha_nacimiento'    => 'nullable|date',
                'estado'              => 'required|string|in:A,I',
                'telefono_principal'  => 'required|string|max:20',
                'telefono_secundario' => 'nullable|string|max:20',
                'direccion'           => 'nullable|string|max:255',
                'referencia_direccion' => 'nullable|string|max:255',
                'latitud'             => 'nullable|numeric',
                'longitud'            => 'nullable|numeric',
                'id_plan'             => 'required|exists:planes,id_plan',
                'id_router'           => 'required|exists:routers,id_router',
                'id_ippool'           => 'required|exists:ip_pools,id_ippool',
                'id_ont'              => 'required|exists:onts,id_ont',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // Generar LOGIN a partir de las iniciales y el día actual
            $fecha = now()->format('d');
            $nombresArr = explode(" ", $request->nombres);
            $apellidosArr = explode(" ", $request->apellidos);
            $inicialesNombres = implode("", array_map(fn($name) => strtoupper(substr($name, 0, 1)), $nombresArr));
            $inicialesApellidos = implode("", array_map(fn($last) => strtoupper(substr($last, 0, 1)), $apellidosArr));
            $baseLogin = "I-{$inicialesNombres}{$inicialesApellidos}{$fecha}";
            $contador = 1;
            do {
                $login = $baseLogin . '-' . str_pad($contador, 3, '0', STR_PAD_LEFT);
                $contador++;
            } while (Cliente::where('login', $login)->exists());

            // Crear Cliente
            $cliente = Cliente::create([
                'nombres'            => $request->nombres,
                'apellidos'          => $request->apellidos,
                'login'              => $login,
                'identificacion'     => $request->identificacion,
                'correo_electronico' => $request->correo_electronico,
                'fecha_nacimiento'   => $request->fecha_nacimiento,
                'estado'             => $request->estado,
            ]);

            // Crear Información Adicional
            InformacionAdicionalCliente::create([
                'id_cliente'             => $cliente->id_cliente,
                'telefono_principal'     => $request->telefono_principal,
                'telefono_secundario'    => $request->telefono_secundario,
                'direccion'              => $request->direccion,
                'referencia_direccion'   => $request->referencia_direccion,
                'latitud'                => $request->latitud,
                'longitud'               => $request->longitud,
                'estado'                 => 'A',
            ]);

            // Generar password aleatoria
            $allChars   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789@#$-_';
            $passLength = 10;
            $rawPassword = '';
            for ($i = 0; $i < $passLength; $i++) {
                $rawPassword .= $allChars[random_int(0, strlen($allChars) - 1)];
            }

            // Crear Usuario
            $usuario = Usuario::create([
                'id_rol'             => 4,
                'nombres'            => $request->nombres,
                'apellidos'          => $request->apellidos,
                'identificacion'     => $request->identificacion,
                'correo_electronico' => $request->correo_electronico,
                'password'           => Hash::make($rawPassword),
                'estado'             => 'A',
            ]);

            // Relacionar Usuario y Cliente
            UsuarioCliente::create([
                'id_usuario'      => $usuario->id_usuario,
                'id_cliente'      => $cliente->id_cliente,
                'estado'          => 'A',
                'fecha_creacion'  => now(),
                'fecha_actualizacion' => now(),
            ]);

            // Crear PPP Secret en MikroTik
            $plan = Plan::find($request->id_plan);
            $api->write('/ppp/secret/add', false);
            $api->write('=name=' . $login, false);
            $api->write('=password=' . $rawPassword, false);
            $api->write('=service=pppoe', false);
            $api->write('=profile=' . $plan->nombre_plan, false);
            $api->write('=comment=' . strtoupper($cliente->nombres) . ' ' . strtoupper($cliente->apellidos), true);
            $responseAPI = $api->read();
            $api->disconnect();

            // Recuperar la ONT usando el id enviado en el request
            $ont = Ont::find($request->id_ont);
            if (!$ont) {
                throw new \Exception("No se encontró la ONT con el id especificado.");
            }
            // Formatear el gpon_sn: usar el prefijo "TPLG-" y los 8 últimos dígitos del campo gpon_sn
            $gpon_sn = "TPLG-" . substr($ont->gpon_sn, -8);

            $clienteDatos = strtoupper(str_replace(' ', '', "$request->nombres$request->apellidos"));

            // Recuperar la OLT asociada al router
            $olt = Olt::where('id_router', $router->id_router)->first();
            if (!$olt) {
                throw new \Exception("No se encontró una OLT asociada al router.");
            }

            // Registrar (confirmar) la ONT en la OLT usando el gpon_sn obtenido
            $ontRegistrationResult = $this->registerONTForClient($gpon_sn, $olt, $clienteDatos);

            // Crear la asignación de servicio (relación Cliente - Servicio - ONT)
            AsignacionServicioCliente::create([
                'id_cliente'         => $cliente->id_cliente,
                'id_plan'            => $request->id_plan,
                'id_router'          => $request->id_router,
                'id_ippool'          => $request->id_ippool,
                'id_ont'             => $ont->id_ont,
                'estado'             => 'A',
                'fecha_creacion'     => now(),
                'fecha_actualizacion' => now(),
            ]);

            // Confirmar la transacción
            DB::commit();

            return response()->json([
                'success'           => true,
                'message'           => 'Cliente creado exitosamente con asignación de servicio, ONT registrada en la OLT y PPPoE en MikroTik.',
                'cliente'           => $cliente,
                'usuario'           => $usuario,
                'password_generada' => $rawPassword,
                'ont_registration'  => $ontRegistrationResult, // Aquí se devuelve la info parseada de la ONU
                'mikrotik_response' => $responseAPI
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error en el proceso: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Registra (confirma) la ONT en la OLT mediante SSH usando los datos del modelo Olt.
     */
    private function registerONTForClient($gpon_sn, $olt, $client)
    {
        try {
            // Conectar vía SSH a la OLT usando los datos del modelo
            $ssh = new SSH2($olt->ip_olt, $olt->port_olt);
            if (!$ssh->login($olt->user_olt, $olt->passw_olt)) {
                Log::error("❌ Error de autenticación en la OLT.");
                throw new \Exception("Error de autenticación en la OLT.");
            }

            $ssh->setTimeout(5);
            $initialOutput = $ssh->read();
            Log::info("📡 Salida inicial de la OLT:\n" . $initialOutput);

            // Verificar si el prompt es el esperado
            if (!preg_match('/OLT\d*>/', $initialOutput)) {
                Log::error("❌ Error: Prompt inicial no reconocido. Salida real: " . $initialOutput);
                throw new \Exception("Prompt inicial no reconocido. Verifica la salida de la OLT.");
            }

            // 1) Entrar en modo enable
            $ssh->write("enable\r");
            $enableOutput = $ssh->read();
            Log::info("📡 Salida después de 'enable':\n" . $enableOutput);

            if (!strpos($enableOutput, '#')) {
                throw new \Exception("El comando 'enable' no llevó al prompt esperado.");
            }

            // 2) Entrar en modo configuración
            $ssh->write("configure\r");
            $configureOutput = $ssh->read();
            Log::info("📡 Salida después de 'configure':\n" . $configureOutput);

            if (!strpos($configureOutput, "(config)#")) {
                throw new \Exception("No se pudo acceder al modo de configuración.");
            }

            // 3) Buscar la ONT en el rango 1/0/1-8
            $ponRange = "1/0/1-8";
            $autofindCmd = "show ont autofind by-sn $gpon_sn $ponRange\r";
            $ssh->write($autofindCmd);
            $autofindOutput = $ssh->read();
            Log::info("📡 Salida después de '$autofindCmd':\n" . $autofindOutput);

            // 4) Extraer el PON ID real de la ONT
            $ponId = null;
            foreach (explode("\n", $autofindOutput) as $line) {
                if (strpos($line, $gpon_sn) !== false) {
                    if (preg_match('/^\s*(\d+)\s+(\d+)\s+(TPLG-\S+)/', $line, $matches)) {
                        $ponId = $matches[2]; // PON ID
                        break;
                    }
                }
            }

            if (!$ponId) {
                throw new \Exception("No se encontró la ONT con el serial $gpon_sn en los puertos $ponRange.");
            }

            // 5) Entrar a la interfaz GPON correspondiente: "1/0/{ponId}"
            $ssh->write("interface gpon 1/0/$ponId\r");
            $interfaceOutput = $ssh->read();
            Log::info("📡 Salida después de 'interface gpon 1/0/$ponId':\n" . $interfaceOutput);

            if (!strpos($interfaceOutput, "(config-if-gpon)#")) {
                throw new \Exception("No se pudo acceder a la interfaz GPON 1/0/$ponId.");
            }

            // 6) Confirmar la ONT
            $confirmCmd = "ont confirm sn-auth $gpon_sn desc $client ont-lineprofile-id 1 ont-srvprofile-id 2\r";
            $ssh->write($confirmCmd);
            $confirmOutput = $ssh->read();
            Log::info("📡 Salida después de '$confirmCmd':\n" . $confirmOutput);

            // Se asume que la ONT ID es 0 tras la confirmación (ajusta si es necesario)
            $ontId = 0;

            // 7) Esperar unos segundos y consultar la potencia óptica
            sleep(5);
            $showOpticalCmd = "show ont optical-info gpon 1/0/$ponId $ontId\r";
            $ssh->write($showOpticalCmd);
            $opticalOutput = $ssh->read();
            Log::info("📡 Salida después de '$showOpticalCmd':\n" . $opticalOutput);

            $ssh->disconnect();

            // 8) Extraer los datos relevantes de la salida
            $dataLine = null;
            foreach (explode("\n", $opticalOutput) as $line) {
                if (strpos($line, "TPLG-") !== false) {
                    $dataLine = trim($line);
                    break;
                }
            }

            if (!$dataLine) {
                throw new \Exception("No se encontró la línea con los datos de la ONT en la salida óptica.");
            }

            // Formato esperado de la línea de salida:
            // NO.   PON_ID  ONU_ID  SERIAL         ONLINE   ACTIVE   RX      TX      ... 
            // Ejemplo:
            // 1     8       0       TPLG-CEEECC28 online   active   -5.84   1.71   ...
            $pattern = '/^\s*\d+\s+(\d+)\s+(\d+)\s+(TPLG-\S+)\s+(\S+)\s+(\S+)\s+([\-\d.]+)\s+([\-\d.]+)/';
            if (preg_match($pattern, $dataLine, $matches)) {
                $parsedData = [
                    'pon_id'        => $matches[1],
                    'onu_id'        => $matches[2],
                    'gpon_serial'   => $matches[3],
                    'online_status' => $matches[4],
                    'active_status' => $matches[5],
                    'rx_dbm'        => $matches[6],
                    'tx_dbm'        => $matches[7],
                ];
            } else {
                throw new \Exception("No se pudo parsear la información de la ONT.");
            }

            return [
                'message' => 'ONT registrada y datos de potencia obtenidos correctamente.',
                'data'    => $parsedData,
            ];
        } catch (\Exception $e) {
            Log::error("❌ Error en registerONTForClient: " . $e->getMessage());
            throw new \Exception('Error al comunicarse con la OLT: ' . $e->getMessage());
        }
    }



    /**
     * Actualizar un cliente.
     */
    public function ActualizarCliente(Request $request, $id)
    {
        $cliente = Cliente::find($id);

        if (!$cliente) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado.',
            ], 404);
        }

        $cliente->update($request->only([
            'nombres',
            'apellidos',
            'estado',
            'login',
        ]));

        // Registrar acción realizada
        AccionRealizada::create([
            'id_usuario' => $request->id_usuario_auditor,
            'descripcion' => "El usuario {$request->id_usuario_auditor} actualizó al cliente {$cliente->nombres} {$cliente->apellidos} con ID de cliente {$cliente->id_cliente}.",
            'tipo_accion' => 'Actualización',
            'estado' => 'A',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cliente actualizado exitosamente.',
            'data' => $cliente,
        ], 200);
    }

    /**
     * Cambiar el estado del cliente a "E" (Eliminado).
     */
    public function EliminarCliente(Request $request, $id)
    {
        // 1) Buscar el cliente
        $cliente = Cliente::find($id);
        if (!$cliente || $cliente->estado === 'E') {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado o ya eliminado.',
            ], 404);
        }

        // 2) Buscar la asignación de servicio del cliente
        $asignacion = AsignacionServicioCliente::where('id_cliente', $cliente->id_cliente)->first();
        if (!$asignacion) {
            return response()->json([
                'success' => false,
                'message' => 'Asignación de servicio no encontrada.',
            ], 404);
        }

        // 3) Buscar el router asociado a la asignación
        $router = Router::find($asignacion->id_router);
        if (!$router || $router->estado !== 'A') {
            return response()->json([
                'success' => false,
                'message' => 'Router no encontrado o inactivo.',
            ], 404);
        }

        // 4) Conectarse al MikroTik y eliminar el secret (PPP)
        $api = new RouterosAPI();
        $api->debug = false;
        try {
            if (!$api->connect($router->ip, $router->user, $router->password, $router->port)) {
                throw new \Exception('No se pudo conectar al MikroTik del router.');
            }
            $api->write('/ppp/secret/remove', false);
            $api->write('=numbers=' . $cliente->login, true);
            $responseAPI = $api->read();
            $api->disconnect();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el secreto en Mikrotik: ' . $e->getMessage(),
            ], 500);
        }

        // 4.5) Eliminar la ONU registrada en la OLT
        $ont = Ont::find($asignacion->id_ont);
        if (!$ont) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró la ONT asociada al cliente.',
            ], 404);
        }
        // Formatear el gpon_sn: se utiliza el prefijo "TPLG-" y los 8 últimos dígitos del campo gpon_sn
        $gpon_sn = "TPLG-" . substr($ont->gpon_sn, -8);
        // Recuperar la OLT asociada al router
        $olt = Olt::where('id_router', $router->id_router)->first();
        if (!$olt) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró una OLT asociada al router.',
            ], 404);
        }
        // Ejecutar la función para eliminar la ONU en la OLT
        try {
            $oltDeletionResult = $this->deleteONTFromOLT($gpon_sn, $olt);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la ONT en la OLT: ' . $e->getMessage(),
            ], 500);
        }

        // 5) Marcar en estado "E" todos los registros relacionados al cliente
        DB::beginTransaction();
        try {
            // Cliente principal
            $cliente->update(['estado' => 'E']);

            // Relación Usuario-Cliente
            $usuarioCliente = UsuarioCliente::where('id_cliente', $cliente->id_cliente)->first();
            if ($usuarioCliente) {
                $usuarioCliente->update(['estado' => 'E']);

                // Usuario
                $usuario = Usuario::find($usuarioCliente->id_usuario);
                if ($usuario) {
                    $usuario->update(['estado' => 'E']);
                }
            }

            // Información adicional
            InformacionAdicionalCliente::where('id_cliente', $cliente->id_cliente)
                ->update(['estado' => 'E']);

            // Asignación de servicio
            $asignacion->update(['estado' => 'E']);

            // Registrar acción realizada
            AccionRealizada::create([
                'id_usuario'   => $request->id_usuario_auditor, // Quien realiza la acción
                'descripcion'  => "El usuario {$request->id_usuario_auditor} eliminó al cliente {$cliente->nombres} {$cliente->apellidos} (ID {$cliente->id_cliente}).",
                'tipo_accion'  => 'Eliminación',
                'estado'       => 'A',
            ]);

            DB::commit();

            return response()->json([
                'success'           => true,
                'message'           => 'Cliente eliminado exitosamente, secreto eliminado del MikroTik y ONT desactivada/eliminada en la OLT.',
                'mikrotik_response' => $responseAPI,
                'olt_deletion'      => $oltDeletionResult,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar cliente en la BD: ' . $e->getMessage(),
            ], 500);
        }
    }


    private function deleteONTFromOLT($gpon_sn, $olt)
    {
        try {
            // 1️⃣ Conectar vía SSH a la OLT
            $ssh = new SSH2($olt->ip_olt, $olt->port_olt);
            if (!$ssh->login($olt->user_olt, $olt->passw_olt)) {
                throw new \Exception("Error de autenticación en la OLT.");
            }

            // 2️⃣ Ajustar tiempo de espera para recibir correctamente el prompt inicial
            $ssh->setTimeout(3);
            $initialOutput = $ssh->read();
            Log::info("OLT Deletion - Salida inicial:\n" . $initialOutput);

            // 3️⃣ Verificar dinámicamente el prompt inicial
            if (!preg_match('/OLT\d*>/', $initialOutput)) {
                Log::error("❌ OLT Deletion Error: Prompt inicial no reconocido. Salida real:\n" . $initialOutput);
                throw new \Exception("Prompt inicial no reconocido. Verifica la salida de la OLT.");
            }

            // 4️⃣ Ingresar a modo enable
            $ssh->write("enable\r");
            $enableOutput = $ssh->read();
            Log::info("OLT Deletion - Salida después de 'enable':\n" . $enableOutput);

            if (!strpos($enableOutput, "#")) {
                throw new \Exception("El comando 'enable' no llevó al prompt esperado.");
            }

            // 5️⃣ Ingresar a modo configuración
            $ssh->write("configure\r");
            $configureOutput = $ssh->read();
            Log::info("OLT Deletion - Salida después de 'configure':\n" . $configureOutput);

            if (!strpos($configureOutput, "(config)#")) {
                throw new \Exception("No se pudo acceder al modo de configuración.");
            }

            // 6️⃣ Buscar la ONU en el rango 1/0/1-8
            $ponRange = "1/0/1-8";
            $infoCmd = "show ont info by-sn $gpon_sn $ponRange\r";
            $ssh->write($infoCmd);
            sleep(2); // Esperar para recibir toda la respuesta
            $infoOutput = $ssh->read();
            Log::info("OLT Deletion - Salida de '$infoCmd':\n" . $infoOutput);

            // 7️⃣ Extraer PON ID y ONU ID de la salida
            $ponId = null;
            $onuId = null;
            $lines = explode("\n", $infoOutput);
            foreach ($lines as $line) {
                if (strpos($line, $gpon_sn) !== false) {
                    // Ejemplo de línea:
                    // "1    8   0   TPLG-CEEECC28 online  activated   active   success match    1       2"
                    $pattern = '/^\s*\d+\s+(\d+)\s+(\d+)\s+(TPLG-\S+)/';
                    if (preg_match($pattern, $line, $matches)) {
                        $ponId = $matches[1];
                        $onuId = $matches[2];
                        break;
                    }
                }
            }

            if (!$ponId || $onuId === null) {
                throw new \Exception("No se encontró la ONT con el serial $gpon_sn en el rango $ponRange.");
            }

            // 8️⃣ Entrar a la interfaz GPON real: "interface gpon 1/0/{ponId}"
            $ssh->write("interface gpon 1/0/$ponId\r");
            $ifaceOutput = $ssh->read();
            Log::info("OLT Deletion - Salida después de 'interface gpon 1/0/$ponId':\n" . $ifaceOutput);

            if (!strpos($ifaceOutput, "(config-if-gpon)#")) {
                throw new \Exception("No se pudo acceder a la interfaz GPON 1/0/$ponId.");
            }

            // 9️⃣ Ejecutar comandos para desactivar y eliminar la ONU:
            $ssh->write("ont deactivate $onuId\r");
            sleep(1);
            $deactOutput = $ssh->read();
            Log::info("OLT Deletion - ont deactivate $onuId:\n" . $deactOutput);

            $ssh->write("ont delete $onuId\r");
            sleep(1);
            $deleteOutput = $ssh->read();
            Log::info("OLT Deletion - ont delete $onuId:\n" . $deleteOutput);

            $ssh->disconnect();

            return [
                'message'       => 'ONT desactivada y eliminada en la OLT correctamente.',
                'pon_id'        => $ponId,
                'onu_id'        => $onuId,
                'info_output'   => $infoOutput,
                'delete_output' => $deleteOutput,
            ];
        } catch (\Exception $e) {
            Log::error("OLT Deletion Error: " . $e->getMessage());
            throw new \Exception("Error al eliminar la ONT en la OLT: " . $e->getMessage());
        }
    }


    /**
     * Mostrar información específica de un cliente.
     */
    public function MostrarInformacionCliente($id)
    {
        $cliente = Cliente::with(['informacionAdicional', 'usuariosClientes.usuario'])->find($id);

        if (!$cliente) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado.',
            ], 404);
        }

        // Buscar la ONT asignada al cliente
        $asignacion = AsignacionServicioCliente::where('id_cliente', $cliente->id_cliente)->first();

        if (!$asignacion || !$asignacion->id_ont) {
            return response()->json([
                'success' => true,
                'data' => $cliente,
                'ont_info' => 'No se encontró ONT asignada a este cliente.',
                'plan' => $asignacion ? $asignacion->plan : 'No tiene plan asignado.',
                'mikrotik_ip' => $asignacion ? $asignacion->ip_mikrotik : 'No tiene IP asignada.',
            ], 200);
        }

        // Obtener la ONT y su Serial GPON
        $ont = Ont::find($asignacion->id_ont);
        if (!$ont) {
            return response()->json([
                'success' => true,
                'data' => $cliente,
                'ont_info' => 'No se encontró la información de la ONT en la base de datos.',
                'plan' => $asignacion->plan,
                'mikrotik_ip' => $asignacion->ip_mikrotik,
            ], 200);
        }

        $gpon_sn = "TPLG-" . substr($ont->gpon_sn, -8);

        // Obtener la OLT asociada al router
        $olt = Olt::where('id_router', $asignacion->id_router)->first();
        if (!$olt) {
            return response()->json([
                'success' => true,
                'data' => $cliente,
                'ont_info' => 'No se encontró la OLT asociada a este cliente.',
                'plan' => $asignacion->plan,
                'mikrotik_ip' => $asignacion->ip_mikrotik,
            ], 200);
        }

        // Consultar información de la ONT en la OLT
        $ontData = $this->consultarInformacionONT($gpon_sn, $olt);

        return response()->json([
            'success' => true,
            'data' => $cliente,
            'ont_info' => $ontData,
            'plan' => $asignacion->plan,
            'mikrotik_ip' => $asignacion->ip_mikrotik,
            'olt' => [
                'nombre' => $olt->nombre,
                'direccion_ip' => $olt->direccion_ip,
                'ubicacion' => $olt->ubicacion,
            ]
        ], 200);
    }

    /**
     * Consulta la OLT para obtener información detallada de la ONT, incluyendo:
     * - PON ID
     * - ONU ID
     * - Estado Online
     * - Estado Activo
     * - RX y TX en dBm
     */
    private function consultarInformacionONT($gpon_sn, $olt)
    {
        try {
            // ✅ Establecer conexión SSH con la OLT
            $ssh = new SSH2($olt->ip_olt, $olt->port_olt);
            if (!$ssh->login($olt->user_olt, $olt->passw_olt)) {
                throw new \Exception("Error de autenticación en la OLT.");
            }

            $ssh->setTimeout(1);

            // ✅ Ingresar a modo enable y configuración
            $ssh->write("enable\r");
            $ssh->read("OLT1#");
            $ssh->write("configure\r");
            $ssh->read("OLT1(config)#");

            // 🔍 Buscar la ONT en la OLT
            $ponPortRange = "1/0/1-8";
            $showOntInfoCmd = "show ont info by-sn $gpon_sn $ponPortRange\r";
            $ssh->write($showOntInfoCmd);
            sleep(1); // Asegurar que la respuesta se reciba completa
            $ontInfoOutput = $ssh->read("OLT1(config)#");

            Log::info("📡 Salida de 'show ont info by-sn':\n" . $ontInfoOutput);

            // 🔎 Extraer PON ID y ONU ID de la salida usando **Regex**
            if (preg_match('/\s+(\d+)\s+(\d+)\s+' . $gpon_sn . '\s+(\w+)\s+(\w+)/', $ontInfoOutput, $matches)) {
                $ponId = $matches[1];  // PON ID
                $onuId = $matches[2];  // ONU ID
                $onlineStatus = $matches[3];  // Estado Online
                $activeStatus = $matches[4];  // Estado Activo

                Log::info("✅ ONT detectada - PON: $ponId, ONU: $onuId, Online: $onlineStatus, Active: $activeStatus");

                // ✅ Formatear PON Port como "1/0/{ponId}"
                $ponPort = "1/0/$ponId";

                // 🔍 Consultar la potencia óptica de la ONT
                $showOpticalCmd = "show ont optical-info gpon $ponPort $onuId\r";
                $ssh->write($showOpticalCmd);
                sleep(1); // Esperar la respuesta
                $opticalOutput = $ssh->read("OLT1(config)#");

                Log::info("📡 Salida de 'show ont optical-info':\n" . $opticalOutput);

                // ✅ Extraer RX y TX Power usando **Regex**
                if (preg_match('/\s+' . $ponId . '\s+' . $onuId . '\s+' . $gpon_sn . '\s+\w+\s+\w+\s+([-\d.]+)\s+([-\d.]+)/', $opticalOutput, $powerMatches)) {
                    $rxPower = $powerMatches[1]; // RX en dBm
                    $txPower = $powerMatches[2]; // TX en dBm

                    Log::info("✅ Potencia óptica detectada - RX: $rxPower dBm, TX: $txPower dBm");

                    // ✅ Cerrar conexión SSH
                    $ssh->disconnect();

                    return [
                        'pon_port' => $ponPort,
                        'onu_id' => $onuId,
                        'gpon_serial' => $gpon_sn,
                        'online_status' => $onlineStatus,
                        'active_status' => $activeStatus,
                        'tx_power' => $txPower,
                        'rx_power' => $rxPower,
                    ];
                } else {
                    throw new \Exception("No se pudieron extraer las potencias RX/TX de la salida.");
                }
            } else {
                throw new \Exception("No se encontraron PON ID y ONU ID para el número de serie $gpon_sn.");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error en consultarInformacionONT: " . $e->getMessage());
            return [
                'error' => 'Error al obtener la información de la ONT desde la OLT.',
                'message' => $e->getMessage(),
            ];
        }
    }





    public function repararCliente(Request $request, $id)
    {
        // 1) Buscar el cliente
        $cliente = Cliente::find($id);
        if (!$cliente) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado.',
            ], 404);
        }

        // 2) Verificar si está en estado A (activo)
        if ($cliente->estado !== 'A') {
            return response()->json([
                'success' => false,
                'message' => 'El cliente no está activo (estado: ' . $cliente->estado . '), no se puede reparar en Mikrotik.',
            ], 400);
        }

        // 3) Buscar la asignación de servicio para obtener el router, plan, etc.
        //    Ajusta si usas otra lógica
        $asignacion = AsignacionServicioCliente::where('id_cliente', $cliente->id_cliente)->first();
        if (!$asignacion) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró asignación de servicio para este cliente.',
            ], 404);
        }

        $router = Router::find($asignacion->id_router);
        if (!$router || $router->estado !== 'A') {
            return response()->json([
                'success' => false,
                'message' => 'Router no encontrado o inactivo.',
            ], 404);
        }

        $plan = Plan::find($asignacion->id_plan);
        if (!$plan || $plan->estado !== 'A') {
            return response()->json([
                'success' => false,
                'message' => 'Plan no encontrado o inactivo.',
            ], 404);
        }

        // 4) Conectar a MikroTik y verificar si ya existe el secret con name=$cliente->login
        $api = new RouterosAPI();
        $api->debug = false;

        try {
            if (!$api->connect($router->ip, $router->user, $router->password, $router->port)) {
                throw new \Exception('No se pudo conectar al MikroTik del router.');
            }

            // Revisar si existe
            $api->write('/ppp/secret/print', false);
            $api->write('?name=' . $cliente->login, true);
            $secrets = $api->read();

            if (count($secrets) > 0) {
                // Ya existe, no hay que crear
                $api->disconnect();
                return response()->json([
                    'success' => true,
                    'message' => 'El secret ya existe en MikroTik. No se requiere reparación.',
                ], 200);
            }

            // 5) Como no existe, lo creamos
            //    Podrías usar una contraseña aleatoria, o la guardada en USUARIO (si la tienes).
            //    Ejemplo: se genera una password random de 10 chars
            $allChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789@#$-_.';
            $passLength = 10;
            $randomPass = '';
            for ($i = 0; $i < $passLength; $i++) {
                $randomPass .= $allChars[random_int(0, strlen($allChars) - 1)];
            }

            // Crear el PPP secret
            $commentText = strtoupper($cliente->nombres) . ' ' . strtoupper($cliente->apellidos) . ' REPARADO EL ' . now();

            $api->write('/ppp/secret/add', false);
            $api->write('=name=' . $cliente->login, false);
            $api->write('=password=' . $randomPass, false);
            $api->write('=service=pppoe', false);
            $api->write('=profile=' . $plan->nombre_plan, false);
            $api->write('=comment=' . $commentText, true);

            $responseAPI = $api->read();
            $api->disconnect();

            // Si deseas actualizar la password en USUARIO, hazlo:
            // $usuarioCliente = UsuarioCliente::where('id_cliente',$cliente->id_cliente)->first();
            // if($usuarioCliente){
            //   $usuario = Usuario::find($usuarioCliente->id_usuario);
            //   if($usuario){
            //       $usuario->update(['password' => Hash::make($randomPass)]);
            //   }
            // }

            return response()->json([
                'success' => true,
                'message' => 'Secret creado exitosamente en MikroTik.',
                'nueva_password' => $randomPass,   // si quieres retornar la nueva pass
                'mikrotik_response' => $responseAPI,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al reparar el cliente en Mikrotik: ' . $e->getMessage(),
            ], 500);
        }
    }
}
