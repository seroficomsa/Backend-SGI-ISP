<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id_cliente'; 

    public $timestamps = false;

    protected $fillable = [
        'nombres',
        'apellidos',
        'login',
        'identificacion',
        'correo_electronico',
        'fecha_nacimiento',
        'estado',
        'id_usuario_auditor',
        'fecha_creacion',
        'fecha_actualizacion',
    ];

    public function informacionAdicional()
    {
        return $this->hasOne(InformacionAdicionalCliente::class, 'id_cliente');
    }

    public function usuariosClientes()
    {
        return $this->hasMany(UsuarioCliente::class, 'id_cliente');
    }
}
