<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuarioCliente extends Model
{
    protected $table = 'usuarios_clientes';


    public $timestamps = false;

    protected $primaryKey = 'id_usuario_cliente';


    protected $fillable = [
        'id_usuario',
        'id_cliente',
        'estado',
        'id_usuario_auditor',
        'fecha_creacion',
        'fecha_actualizacion',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }
}
