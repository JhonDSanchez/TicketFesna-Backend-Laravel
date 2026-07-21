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

    public function getCorreoAreaAttribute(): string
    {
        return match ((string) $this->nombre_area) {
            'Relación con el sector externo' => 'sectorexterno@nuevaamerica.edu.co',
            'Lideres de programa' => 'lideres.programa@nuevaamerica.edu.co',
            'Bienestar institucional' => 'bienestar@nuevaamerica.edu.co',
            'Académico' => 'academico@nuevaamerica.edu.co',
            'Promoción institucional' => 'promocion@nuevaamerica.edu.co',
            'Atención al estudiante' => 'atencion.estudiante@nuevaamerica.edu.co',
            'Tesoreria' => 'tesoreria@nuevaamerica.edu.co',
            'Sistemas' => 'sistemas@nuevaamerica.edu.co',
            default => 'contacto@nuevaamerica.edu.co',
        };
    }
}
