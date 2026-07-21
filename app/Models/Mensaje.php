<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Adjunto;
class Mensaje extends Model
{
    protected $table = 'mensajes';
    protected $primaryKey = 'ID_mensaje';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'ID_ticket',
        'ID_usuario',
        'contenido',
        'fecha_hora',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ID_ticket', 'ID_ticket');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'ID_usuario', 'ID_usuario');
    }

    // ¡NUEVA RELACIÓN! Un mensaje puede tener varios archivos adjuntos
    public function adjuntos()
    {
        return $this->hasMany(Adjunto::class, 'ID_mensaje', 'ID_mensaje');
    }
}