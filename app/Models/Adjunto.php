<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Adjunto extends Model
{
    protected $table = 'adjuntos';
    protected $primaryKey = 'ID_adjunto';
    public $incrementing = true;
    public $timestamps = false; // La base de datos no tiene created_at / updated_at aquí

    protected $fillable = [
        'ID_mensaje',
        'nombre_archivo',
        'ruta_archivo',
        'tipo_archivo',
        'tamaño',
    ];

    // Relación inversa: Este archivo pertenece a un mensaje específico
    public function mensaje()
    {
        return $this->belongsTo(Mensaje::class, 'ID_mensaje', 'ID_mensaje');
    }
}