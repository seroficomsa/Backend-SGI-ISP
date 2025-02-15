<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InformacionAdicionalCliente extends Model
{
    protected $table = 'informacion_adicional_cliente';


    public $timestamps = false;

    
    protected $fillable = [
        'id_cliente',
        'telefono_principal',
        'telefono_secundario',
        'direccion',
        'referencia_direccion',
        'latitud',
        'longitud',
        'estado',
        'id_usuario_auditor',
        'fecha_creacion',
        'fecha_actualizacion',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }
}
