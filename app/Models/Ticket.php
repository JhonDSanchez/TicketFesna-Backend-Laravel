<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $table = 'tickets';
    protected $primaryKey = 'ID_ticket';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'ID_usuario_solicitante',
        'ID_usuario_responsable',
        'ID_area',
        'titulo',
        'descripcion',
        'estado_ticket',
        'prioridad',
        'fecha_creacion',
        'fecha_actualizacion',
        'fecha_cierre',
    ];

    public function solicitante()
    {
        return $this->belongsTo(Usuario::class, 'ID_usuario_solicitante', 'ID_usuario');
    }

    public function responsable()
    {
        return $this->belongsTo(Usuario::class, 'ID_usuario_responsable', 'ID_usuario');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'ID_area', 'ID_area');
    }

    public function mensajes()
    {
        return $this->hasMany(Mensaje::class, 'ID_ticket', 'ID_ticket');
    }
}
