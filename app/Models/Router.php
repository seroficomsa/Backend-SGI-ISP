<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    protected $table = 'routers';

    protected $primaryKey = 'id_router';

    // Campos asignables en masiva (Mass Assignment)
    protected $fillable = [
        'nombre_router',
        'descripcion_router',
        'user',
        'password',
        'ip',
        'port',
        'latitud',
        'longitud',
        'estado',
        'id_usuario_auditor',
    ];

    // Deshabilitamos timestamps si no queremos usar created_at y updated_at
    public $timestamps = false;

    // Relación con el usuario auditor
    public function usuarioAuditor()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_auditor');
    }

    public function ipPools()
    {
        // 'id_router' en ip_pools hace referencia a 'id_router' en routers
        return $this->hasMany(IpPool::class, 'id_router', 'id_router');
    }

    // Relación a Plan
    public function planes()
    {
        return $this->hasMany(Plan::class, 'id_router', 'id_router');
    }
}
