<?php

namespace App\Services;

use phpseclib3\Net\SSH2;

class OLTService
{
    protected $host = '10.10.10.245';
    protected $username = 'dev';
    protected $password = 'Sai009988*';

    public function connectAndConfigure()
    {
        $ssh = new SSH2($this->host);

        if (!$ssh->login($this->username, $this->password)) {
            return 'Error: No se pudo autenticar con la OLT';
        }

        // Enviar los comandos requeridos
        $ssh->write("enable\n");
        $ssh->write("configure\n");

        // Capturar la salida del terminal
        $output = $ssh->read();

        return $output;
    }

    public function registerONU($serialNumber, $lineProfile, $serviceProfile, $port)
    {
        $ssh = new SSH2($this->host);

        if (!$ssh->login($this->username, $this->password)) {
            return 'Error: No se pudo autenticar con la OLT';
        }

        $ssh->write("enable\n");
        $ssh->write("configure\n");

        // Comando para registrar ONU por número de serie
        $command = "interface gpon 1/0/$port\n";
        $command .= "ont confirm sn-auth $serialNumber ont-lineprofile-id $lineProfile ont-srvprofile-id $serviceProfile\n";
        
        $ssh->write($command);
        $output = $ssh->read();

        return $output;
    }
}
