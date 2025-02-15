<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

class OLTController extends Controller
{
    protected $host = '10.10.10.245';
    protected $username = 'dev';
    protected $password = 'Sai009988*';
    protected $port = 22;

    public function registerONT(Request $request)
    {
        $serialNumber = strtoupper(trim($request->input('serial')));
        $ponPort = '1/0/8'; // Especifica el puerto PON correspondiente
        $ontId = 0; // ID de la ONT, se asignará después de la confirmación

        try {
            // Establecer conexión SSH
            $ssh = new SSH2($this->host, $this->port);
            if (!$ssh->login($this->username, $this->password)) {
                throw new \Exception("Error de autenticación en la OLT.");
            }

            // Configurar el tiempo de espera para lecturas
            $ssh->setTimeout(1); // Ajusta según el tiempo de respuesta de tu OLT

            // Leer el prompt inicial
            $initialOutput = $ssh->read();
            Log::info("📡 Salida inicial de la OLT:\n" . $initialOutput);

            // Verificar el prompt y enviar comandos según corresponda
            if (strpos($initialOutput, 'OLT1>') !== false) {
                // Enviar comando 'enable'
                $ssh->write("enable\r");
                $enableOutput = $ssh->read('OLT1#'); // Espera hasta que aparezca 'OLT1#'
                Log::info("📡 Salida después de 'enable':\n" . $enableOutput);

                if (strpos($enableOutput, 'OLT1#') !== false) {
                    // Enviar comando 'configure'
                    $ssh->write("configure\r");
                    $configureOutput = $ssh->read('OLT1(config)#'); // Espera hasta que aparezca 'OLT1(config)#'
                    Log::info("📡 Salida después de 'configure':\n" . $configureOutput);

                    if (strpos($configureOutput, 'OLT1(config)#') !== false) {
                        // Comando para mostrar ONTs no registradas con el número de serie proporcionado
                        $autofindCmd = "show ont autofind by-sn $serialNumber $ponPort\r";
                        $ssh->write($autofindCmd);
                        $autofindOutput = $ssh->read('OLT1(config)#');
                        Log::info("📡 Salida después de '$autofindCmd':\n" . $autofindOutput);

                        // Verificar si la ONT está en el puerto PON indicado
                        if (strpos($autofindOutput, $serialNumber) !== false) {
                            // Entrar en la interfaz GPON
                            $ssh->write("interface gpon $ponPort\r");
                            $interfaceOutput = $ssh->read("OLT1(config-if-gpon)#");
                            Log::info("📡 Salida después de 'interface gpon $ponPort':\n" . $interfaceOutput);

                            if (strpos($interfaceOutput, "OLT1(config-if-gpon)#") !== false) {
                                // Confirmar la ONT con los perfiles correspondientes
                                $confirmCmd = "ont confirm sn-auth $serialNumber ont-lineprofile-id 1 ont-srvprofile-id 2\r";
                                $ssh->write($confirmCmd);
                                $confirmOutput = $ssh->read("OLT1(config-if-gpon)#");
                                Log::info("📡 Salida después de '$confirmCmd':\n" . $confirmOutput);

                                // Pausa de 5 segundos para permitir que la ONT se registre y estabilice
                                sleep(5);

                                // Comando para mostrar la potencia óptica de la ONT
                                $showOpticalCmd = "show ont optical-info $ponPort $ontId\r";
                                $ssh->write($showOpticalCmd);
                                $opticalOutput = $ssh->read("OLT1(config-if-gpon)#");
                                Log::info("📡 Salida después de '$showOpticalCmd':\n" . $opticalOutput);

                                // Cerrar la conexión SSH
                                $ssh->disconnect();

                                // Retornar la respuesta en formato JSON
                                return response()->json([
                                    'message' => 'ONT registrada y datos de potencia obtenidos correctamente.',
                                    'optical_data' => $opticalOutput
                                ]);
                            } else {
                                throw new \Exception("No se pudo acceder a la interfaz GPON.");
                            }
                        } else {
                            throw new \Exception("La ONT con el número de serie $serialNumber no se encontró en el puerto PON $ponPort.");
                        }
                    } else {
                        throw new \Exception("No se pudo acceder al modo de configuración.");
                    }
                } else {
                    throw new \Exception("El comando 'enable' no llevó al prompt esperado.");
                }
            } else {
                throw new \Exception("Prompt inicial no reconocido.");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error: " . $e->getMessage());
            return response()->json(['error' => 'Error al comunicarse con la OLT: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Maneja la solicitud para obtener las potencias TX y RX de una ONT.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOntPower(Request $request)
    {
        // Validar que el número de serie esté presente en la solicitud
        $request->validate([
            'serial' => 'required|string',
        ]);

        $serialNumber = strtoupper(trim($request->input('serial')));

        try {
            // Llama a la función para obtener las potencias ópticas
            $opticalPowers = $this->getOntOpticalPower($serialNumber);

            // Devuelve una respuesta JSON con las potencias
            return response()->json([
                'message' => 'Potencias obtenidas correctamente.',
                'data' => $opticalPowers
            ], 200);
        } catch (\Exception $e) {
            // Manejo de errores
            Log::error("❌ Error: " . $e->getMessage());
            return response()->json([
                'error' => 'Error al obtener las potencias: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Obtiene las potencias TX y RX de una ONT dado su número de serie.
     *
     * @param string $serialNumber Número de serie de la ONT.
     * @return array Arreglo asociativo con las potencias TX y RX en dBm.
     * @throws \Exception Si ocurre un error durante la comunicación con la OLT.
     */
    public function getOntOpticalPower($serialNumber)
    {
        $serialNumber = strtoupper(trim($serialNumber));
        $ponPortRange = '1/0/1-8'; // Rango de puertos PON a consultar
    
        try {
            // Establecer conexión SSH
            $ssh = new SSH2($this->host, $this->port);
            if (!$ssh->login($this->username, $this->password)) {
                throw new \Exception("Error de autenticación en la OLT.");
            }
    
            // Configurar el tiempo de espera para lecturas
            $ssh->setTimeout(1); // Ajusta según el tiempo de respuesta de tu OLT
    
            // Leer el prompt inicial
            $initialOutput = $ssh->read();
            Log::info("📡 Salida inicial de la OLT:\n" . $initialOutput);
    
            // Verificar el prompt y enviar comandos según corresponda
            if (strpos($initialOutput, 'OLT1>') !== false) {
                // Enviar comando 'enable'
                $ssh->write("enable\r");
                $enableOutput = $ssh->read('OLT1#'); // Espera hasta que aparezca 'OLT1#'
                Log::info("📡 Salida después de 'enable':\n" . $enableOutput);
    
                if (strpos($enableOutput, 'OLT1#') !== false) {
                    // Enviar comando 'configure'
                    $ssh->write("configure\r");
                    $configureOutput = $ssh->read('OLT1(config)#'); // Espera hasta que aparezca 'OLT1(config)#'
                    Log::info("📡 Salida después de 'configure':\n" . $configureOutput);
    
                    if (strpos($configureOutput, 'OLT1(config)#') !== false) {
                        // Comando para obtener PON ID y ONU ID basados en el número de serie
                        $showOntInfoCmd = "show ont info by-sn $serialNumber $ponPortRange\r";
                        $ssh->write($showOntInfoCmd);
                        $ontInfoOutput = $ssh->read('OLT1(config)#');
                        Log::info("📡 Salida después de '$showOntInfoCmd':\n" . $ontInfoOutput);
    
                        // Extraer PON ID y ONU ID de la salida
                        if (preg_match('/\s+(\d+)\s+(\d+)\s+' . $serialNumber . '\s+(\w+)\s+(\w+)/', $ontInfoOutput, $matches)) {
                            $ponId = $matches[1];
                            $onuId = $matches[2];
                            $onlineStatus = $matches[3];
                            $activeStatus = $matches[4];
    
                            // Formatear el PON ID en el formato 1/0/x
                            $ponPort = "1/0/$ponId";
    
                            // Comando para obtener la información óptica de la ONT
                            $showOpticalCmd = "show ont optical-info $ponPort $onuId\r";
                            $ssh->write($showOpticalCmd);
                            $opticalOutput = $ssh->read('OLT1(config)#');
                            Log::info("📡 Salida después de '$showOpticalCmd':\n" . $opticalOutput);
    
                            // Cerrar la conexión SSH
                            $ssh->disconnect();
    
                            // Extraer las potencias RX y TX de la salida
                            if (preg_match('/\s+' . $ponId . '\s+' . $onuId . '\s+' . $serialNumber . '\s+\w+\s+\w+\s+([-\d.]+)\s+([-\d.]+)/', $opticalOutput, $powerMatches)) {
                                $rxPower = $powerMatches[1];
                                $txPower = $powerMatches[2];
    
                                return [
                                    'pon_port' => $ponPort,
                                    'online_status' => $onlineStatus,
                                    'active_status' => $activeStatus,
                                    'tx_power' => $txPower,
                                    'rx_power' => $rxPower,
                                ];
                            } else {
                                throw new \Exception("No se pudieron extraer las potencias RX/TX de la salida.");
                            }
                        } else {
                            throw new \Exception("No se encontraron PON ID y ONU ID para el número de serie $serialNumber.");
                        }
                    } else {
                        throw new \Exception("No se pudo acceder al modo de configuración.");
                    }
                } else {
                    throw new \Exception("El comando 'enable' no llevó al prompt esperado.");
                }
            } else {
                throw new \Exception("Prompt inicial no reconocido.");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error: " . $e->getMessage());
            throw new \Exception('Error al comunicarse con la OLT: ' . $e->getMessage());
        }
    }
    
}
