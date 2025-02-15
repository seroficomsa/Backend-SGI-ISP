<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AsignacionServicioCliente extends Model
{
    use HasFactory;

    protected $table = 'asignacion_servicio_clientes';
    protected $primaryKey = 'id_asignacion_servicio_cliente';
    public $timestamps = false; // Deshabilitamos timestamps por defecto de Laravel

    protected $fillable = [
        'id_cliente',
        'id_plan',
        'id_router',
        'id_ippool',
        'id_ont', // Nuevo campo para la ONT
        'estado',
        'fecha_creacion',
        'fecha_actualizacion',
    ];

    protected $casts = [
        'fecha_creacion' => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    // Relación con Cliente
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    // Relación con Plan
    public function plan()
    {
        return $this->belongsTo(Plan::class, 'id_plan', 'id_plan');
    }

    // Relación con Router
    public function router()
    {
        return $this->belongsTo(Router::class, 'id_router', 'id_router');
    }

    // Relación con IpPool
    public function ipPool()
    {
        return $this->belongsTo(IpPool::class, 'id_ippool', 'id_ippool');
    }

    // Nueva relación con ONT (Antes era ONU)
    public function ont()
    {
        return $this->belongsTo(Ont::class, 'id_ont', 'id_ont');
    }

    // Establecer automáticamente la fecha de creación y actualización
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->fecha_creacion = now();
            $model->fecha_actualizacion = now();
        });

        static::updating(function ($model) {
            $model->fecha_actualizacion = now();
        });
    }
}
