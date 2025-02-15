<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Olt extends Model
{
    use HasFactory;

    protected $table = 'olts'; // Nombre de la tabla en la base de datos

    protected $primaryKey = 'id_olt'; // Clave primaria

    protected $fillable = [
        'id_router',
        'nombre_olt',
        'descripcion_olt',
        'user_olt',
        'passw_olt',
        'port_olt',
        'ip_olt',
        'estado',
        'fecha_creacion',
        'fecha_actualizacion',
    ];

    public $timestamps = false; // Usamos las fechas manualmente en la base de datos

    /**
     * Relación con la tabla `routers`
     */
    public function router()
    {
        return $this->belongsTo(Router::class, 'id_router', 'id_router');
    }
}
