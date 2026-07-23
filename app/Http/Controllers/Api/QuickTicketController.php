<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Ticket;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuickTicketController extends Controller
{
    public function create(Request $request)
    {
        $userId = (int) preg_replace('/\D+/', '', $request->input('userId', '0'));
        if ($userId <= 0) {
            return response()->json(['message' => 'Usuario no válido.'], 422);
        }

        $actor = Usuario::query()->findOrFail($userId);
        $areaId = (int) preg_replace('/\D+/', '', $request->input('areaId', '8'));
        $areaId = $areaId > 0 ? $areaId : 8;
        Area::query()->findOrFail($areaId);

        $title = $request->input('title', 'Olvidé la contraseña de Q10');
        $description = $request->input('description', 'El usuario informa que olvidó la contraseña de acceso a la plataforma académica Q10 y no puede iniciar sesión. Solicita el restablecimiento de sus credenciales o asistencia para recuperar el acceso a su cuenta y continuar con sus actividades académicas.');

        $ticket = DB::transaction(function () use ($actor, $areaId, $title, $description) {
            $ticket = Ticket::query()->create([
                'ID_usuario_solicitante' => (int) $actor->ID_usuario,
                'ID_usuario_responsable' => null,
                'ID_area' => $areaId,
                'titulo' => $title,
                'descripcion' => $description,
                'estado_ticket' => 'Nuevo',
                'prioridad' => 'Media',
                'fecha_creacion' => now(),
                'fecha_actualizacion' => now(),
                'fecha_cierre' => null,
            ]);

            $this->insertTicketLog(
                ticketId: (int) $ticket->ID_ticket,
                actorUserId: (int) $actor->ID_usuario,
                action: 'CREACION_TICKET',
                oldValue: null,
                newValue: 'Nuevo'
            );

            return $ticket;
        });

        return response()->json([
            'success' => true,
            'ticketId' => (int) $ticket->ID_ticket,
        ], 201);
    }

    private function insertTicketLog(int $ticketId, int $actorUserId, string $action, ?string $oldValue, ?string $newValue): void
    {
        DB::table('historial_ticket')->insert([
            'ID_ticket' => $ticketId,
            'ID_usuario' => $actorUserId,
            'accion' => $action,
            'valor_anterior' => $oldValue,
            'valor_nuevo' => $newValue,
            'fecha_hora' => now(),
        ]);
    }
}
