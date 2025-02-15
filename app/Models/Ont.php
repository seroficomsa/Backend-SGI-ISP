<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ont extends Model
{
    use HasFactory;

    protected $table = 'onts'; // Nombre correcto de la tabla
    protected $primaryKey = 'id_ont'; // Nueva clave primaria
    public $timestamps = false;

    protected $fillable = [
        'modelo',
        'sn',
        'gpon_sn',
        'mac',
        'estado',
        'fecha_creacion',
        'fecha_actualizacion'
    ];
}
