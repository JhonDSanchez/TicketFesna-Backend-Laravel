<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Ticket;
use Illuminate\Http\Request;

class AreaController extends Controller
{
    public function index(Request $request)
    {
        $areas = Area::query()
            ->with('usuarios')
            ->orderBy('ID_area')
            ->get();

        return response()->json($areas->map(fn (Area $area) => [
            'id' => 'DEP-' . str_pad((string) $area->ID_area, 3, '0', STR_PAD_LEFT),
            'name' => $area->nombre_area,
            'head' => 'Sin definir',
            'staff' => $area->usuarios->count(),
            'openTickets' => Ticket::query()->where('ID_area', $area->ID_area)->count(),
            'email' => $area->correo_area,
        ]));
    }
}
