<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Usuario extends Authenticatable implements JWTSubject
{
    protected $table = 'usuarios'; // Nombre de la tabla

    protected $primaryKey = 'id_usuario'; // Definir clave primaria personalizada

    public $timestamps = false; // Deshabilitar timestamps automáticos de Laravel

    protected $fillable = [
        'id_rol',
        'nombres',
        'apellidos',
        'identificacion',
        'correo_electronico',
        'password',
        'estado',
        'id_usuario_auditor',
    ];

    protected $hidden = ['password', 'remember_token']; // Campos ocultos en respuestas JSON

    // Configuración para JWT
    public function getJWTIdentifier()
    {
        return $this->getKey(); // Devuelve el valor de la clave primaria
    }

    public function getJWTCustomClaims()
    {
        return []; // Reclamos personalizados para JWT (vacío en este caso)
    }

    // Relación con la tabla de roles
    public function rol()
    {
        return $this->belongsTo(Rol::class, 'id_rol', 'id_rol');
    }

    public function usuariosClientes()
    {
        return $this->hasMany(UsuarioCliente::class, 'id_cliente');
    }


    // Relación con clientes (auditoría)
    public function clientes()
    {
        return $this->hasMany(Cliente::class, 'id_usuario_auditor');
    }

    // Sobreescribir para usar nombres de columnas personalizados para timestamps
    public function getCreatedAtColumn()
    {
        return 'fecha_creacion'; // Columna de creación personalizada
    }

    public function getUpdatedAtColumn()
    {
        return 'fecha_actualizacion'; // Columna de actualización personalizada
    }
}
