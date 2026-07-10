<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Usuario extends Model
{
    protected $table = 'usuarios';
    protected $primaryKey = 'ID_usuario';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'cedula',
        'nombre_completo',
        'correo',
        'contraseña',
        'rol',
        'estado_cuenta',
        'ID_area',
        'debe_cambiar_password',
    ];

    protected $hidden = ['contraseña'];

    protected $casts = [
        'debe_cambiar_password' => 'boolean',
    ];

    public function area()
    {
        return $this->belongsTo(Area::class, 'ID_area', 'ID_area');
    }

    public function mensajes()
    {
        return $this->hasMany(Mensaje::class, 'ID_usuario', 'ID_usuario');
    }
}
