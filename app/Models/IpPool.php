<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IpPool extends Model
{
    use HasFactory;

    protected $table = 'ip_pools';

    protected $primaryKey = 'id_ippool'; 


    public $timestamps = false;


    protected $fillable = [
        'id_router',
        'nombre_pool',
        'subnet',
        'rango_ip',
        'estado',
    ];

    // public function router()
    // {
    //     return $this->belongsTo(Router::class, 'id_router');
    // }

    public function router()
    {
        return $this->belongsTo(Router::class, 'id_router', 'id_router');
    }
}
