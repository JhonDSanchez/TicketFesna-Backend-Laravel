<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Ticket;
use App\Models\Usuario;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary()
    {
        $tickets = Ticket::query()->with(['area', 'solicitante', 'responsable'])->get();
        $users = Usuario::query()->with('area')->get();
        $areas = Area::query()->get();
        $firstUser = $users->first();

        return response()->json([
            'user' => $firstUser ? [
                'name' => $firstUser->nombre_completo,
                'role' => $firstUser->rol,
                'department' => $firstUser->area?->nombre_area ?? 'Sin área',
                'initials' => mb_strtoupper(substr($firstUser->nombre_completo, 0, 1)),
                'id' => 'USR-' . str_pad((string) $firstUser->ID_usuario, 3, '0', STR_PAD_LEFT),
            ] : null,
            'tickets' => $tickets->map(function ($ticket) {
                return [
                    'id' => 'TK-' . str_pad((string) $ticket->ID_ticket, 4, '0', STR_PAD_LEFT),
                    'title' => $ticket->titulo,
                    'status' => $this->mapStatus($ticket->estado_ticket),
                    'priority' => $this->mapPriority($ticket->prioridad),
                    'area' => $ticket->area?->nombre_area ?? 'Sin área',
                    'department' => $ticket->area?->nombre_area ?? 'Sin área',
                    'requester' => $ticket->solicitante?->nombre_completo ?? 'Sin solicitante',
                    'assignedTo' => $ticket->responsable?->nombre_completo ?? null,
                    'date' => $ticket->fecha_creacion ? substr($ticket->fecha_creacion, 0, 10) : null,
                    'lastUpdate' => $ticket->fecha_actualizacion ? substr($ticket->fecha_actualizacion, 0, 10) : null,
                ];
            }),
            'users' => $users->map(function ($user) {
                return [
                    'id' => 'USR-' . str_pad((string) $user->ID_usuario, 3, '0', STR_PAD_LEFT),
                    'name' => $user->nombre_completo,
                    'email' => $user->correo,
                    'role' => $user->rol,
                    'department' => $user->area?->nombre_area ?? 'Sin área',
                    'status' => strtolower($user->estado_cuenta) === 'activo' ? 'active' : 'inactive',
                    'joined' => $user->created_at ?? '2024-01-01',
                ];
            }),
            'areas' => $areas->map(function ($area) {
                return [
                    'id' => 'DEP-' . str_pad($area->ID_area, 3, '0', STR_PAD_LEFT),
                    'name' => $area->nombre_area,
                    'head' => 'Sin definir',
                    'staff' => $users->where('ID_area', $area->ID_area)->count(),
                    'openTickets' => Ticket::where('ID_area', $area->ID_area)->count(),
                    'email' => 'contacto@empresa.test',
                ];
            }),
        ]);
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'Nuevo' => 'open',
            'En proceso' => 'in-progress',
            'Esperando respuesta' => 'open',
            'Resuelto' => 'resolved',
            'Cerrado' => 'closed',
            default => 'open',
        };
    }

    private function mapPriority(?string $priority): string
    {
        return match ($priority) {
            'Alta', 'Urgente' => 'high',
            'Media' => 'medium',
            'Baja' => 'low',
            default => 'medium',
        };
    }
}
