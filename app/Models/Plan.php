<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $table = 'planes';
    protected $primaryKey = 'id_plan';

    public $timestamps = false;


    protected $fillable = [
        'id_router',
        'nombre_plan',
        'descripcion_plan',
        'id_ippool',
        'mb_subida',
        'mb_bajada',
        'precio',
        'estado',
        'id_usuario_auditor',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class, 'id_router', 'id_router');
    }

    public function usuarioAuditor()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_auditor');
    }
    public function ippool()
    {
        return $this->belongsTo(IpPool::class, 'id_ippool', 'id_ippool');
    }
    
}
