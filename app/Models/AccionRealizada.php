<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccionRealizada extends Model
{
    protected $table = 'acciones_realizadas';

    public $timestamps = false;
    
    protected $fillable = [
        'id_usuario',
        'descripcion',
        'tipo_accion',
        'estado',
        'fecha_creacion',
        'fecha_actualizacion',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }
}
