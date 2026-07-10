<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $table = 'areas';
    protected $primaryKey = 'ID_area';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'nombre_area',
        'descripcion',
    ];

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'ID_area', 'ID_area');
    }
}
