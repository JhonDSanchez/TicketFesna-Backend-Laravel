<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Mensaje;
use App\Models\Ticket;
use App\Models\Usuario;
use App\Models\Adjunto; // <-- Añadido para poder usar la tabla de adjuntos
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $tickets = Ticket::query()
            ->with(['area', 'solicitante', 'responsable'])
            ->orderByDesc('ID_ticket')
            ->get();

        $tickets->each(function (Ticket $ticket) {
            $this->autoCloseResolvedIfExpired($ticket);
        });

        return response()->json($tickets->map(fn (Ticket $ticket) => $this->serializeTicket($ticket)));
    }

    public function myTickets(string $userId)
    {
        $id = (int) preg_replace('/\D+/', '', $userId);
        Usuario::query()->findOrFail($id);

        $tickets = Ticket::query()
            ->with(['area', 'solicitante', 'responsable'])
            ->where('ID_usuario_solicitante', $id)
            ->orderByDesc('ID_ticket')
            ->get();

        $tickets->each(function (Ticket $ticket) {
            $this->autoCloseResolvedIfExpired($ticket);
        });

        return response()->json($tickets->map(fn (Ticket $ticket) => $this->serializeTicket($ticket))->values());
    }

    public function areaInbox(string $userId)
    {
        $id = (int) preg_replace('/\D+/', '', $userId);
        $user = Usuario::query()->with('area')->findOrFail($id);

        if (!in_array((string) $user->rol, ['Funcionario', 'Administrador'], true)) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $tickets = Ticket::query()
            ->with(['area', 'solicitante', 'responsable'])
            ->where('ID_area', $user->ID_area)
            ->orderByDesc('ID_ticket')
            ->get();

        $tickets->each(function (Ticket $ticket) {
            $this->autoCloseResolvedIfExpired($ticket);
        });

        $agents = Usuario::query()
            ->where('ID_area', $user->ID_area)
            ->whereIn('rol', ['Funcionario', 'Administrador'])
            ->whereRaw('LOWER(estado_cuenta) = ?', ['activo'])
            ->orderBy('nombre_completo')
            ->get(['ID_usuario', 'nombre_completo'])
            ->map(fn (Usuario $agent) => [
                'id' => (int) $agent->ID_usuario,
                'name' => $agent->nombre_completo,
            ])
            ->values();

        return response()->json([
            'areaName' => $user->area?->nombre_area ?? 'Sin área',
            'tickets' => $tickets->map(fn (Ticket $ticket) => $this->serializeTicket($ticket))->values(),
            'agents' => $agents,
        ]);
    }

    public function store(Request $request)
    {
        // 1. Limpiamos los strings "USR-001" y "DEP-001" para dejar solo los números enteros
        $request->merge([
            'userId' => (int) preg_replace('/\D+/', '', $request->input('userId', '0')),
            'areaId' => (int) preg_replace('/\D+/', '', $request->input('areaId', '0')),
        ]);

        // 2. Modificado: Ahora la validación permite archivos
        $data = $request->validate([
            'userId' => ['required', 'integer'],
            'areaId' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:Baja,Media,Alta,Crítica,Urgente'],
            'archivos.*' => ['nullable', 'file', 'max:10240'] // <-- NUEVO: Permite adjuntos
        ]);

        /** @var Usuario $actor */
        $actor = Usuario::query()->findOrFail((int) $data['userId']);
        Area::query()->findOrFail((int) $data['areaId']);

        $priority = isset($data['priority'])
            ? $this->mapUiPriorityToDb((string) $data['priority'])
            : 'Media';

        /** @var Ticket $ticket */
        $ticket = DB::transaction(function () use ($request, $data, $actor, $priority) {
            $ticket = Ticket::query()->create([
                'ID_usuario_solicitante' => (int) $actor->ID_usuario,
                'ID_usuario_responsable' => null,
                'ID_area' => (int) $data['areaId'],
                'titulo' => (string) $data['title'],
                'descripcion' => $data['description'] ?? null,
                'estado_ticket' => 'Nuevo',
                'prioridad' => $priority,
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

            // NUEVO: Si llegan archivos desde la IA, creamos un mensaje inicial para adjuntarlos
            if ($request->hasFile('archivos')) {
                $mensaje = Mensaje::create([
                    'ID_ticket' => $ticket->ID_ticket,
                    'ID_usuario' => $actor->ID_usuario,
                    'contenido' => 'Archivos adjuntos y contexto enviados mediante NOVA (IA)',
                    'fecha_hora' => now(),
                ]);

                foreach ($request->file('archivos') as $archivo) {
                    $ruta = $archivo->store('adjuntos_tickets', 'public');
                    
                    Adjunto::create([
                        'ID_mensaje' => $mensaje->ID_mensaje,
                        'nombre_archivo' => $archivo->getClientOriginalName(),
                        'ruta_archivo' => $ruta,
                        'tipo_archivo' => $archivo->getClientMimeType(),
                        'tamaño' => $archivo->getSize(),
                    ]);
                }
            }

            return $ticket;
        });

        $ticket->load(['area', 'solicitante', 'responsable']);

        return response()->json($this->serializeTicket($ticket), 201);
    }

    public function updateAreaTicket(Request $request, string $ticketId)
    {
        $data = $request->validate([
            'actorUserId' => ['required', 'integer'],
            'assignedUserId' => ['nullable', 'integer'],
            'areaId' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:Nuevo,En proceso,Esperando respuesta,Resuelto,Cerrado'],
            'priority' => ['required', 'in:Baja,Media,Alta,Crítica,Urgente'],
        ]);

        $id = (int) preg_replace('/\D+/', '', $ticketId);
        /** @var Ticket $ticket */
        $ticket = Ticket::query()->with(['area', 'solicitante', 'responsable'])->findOrFail($id);
        $this->autoCloseResolvedIfExpired($ticket);

        if ((string) $ticket->estado_ticket === 'Cerrado') {
            return response()->json(['message' => 'El ticket está cerrado y no puede modificarse.'], 422);
        }

        /** @var Usuario $actor */
        $actor = Usuario::query()->findOrFail((int) $data['actorUserId']);
        if ((int) $actor->ID_area !== (int) $ticket->ID_area) {
            return response()->json(['message' => 'No autorizado para gestionar tickets de otra área.'], 403);
        }

        if (!in_array((string) $actor->rol, ['Funcionario', 'Administrador'], true)) {
            return response()->json(['message' => 'No autorizado para realizar esta acción.'], 403);
        }

        $newAreaId = array_key_exists('areaId', $data) && $data['areaId'] !== null
            ? (int) $data['areaId']
            : (int) $ticket->ID_area;

        if ($newAreaId !== (int) $ticket->ID_area) {
            Area::query()->findOrFail($newAreaId);
        }

        $newResponsibleId = $data['assignedUserId'] === null ? null : (int) $data['assignedUserId'];
        if ($newResponsibleId !== null) {
            $responsable = Usuario::query()
                ->where('ID_usuario', $newResponsibleId)
                ->where('ID_area', $newAreaId)
                ->whereIn('rol', ['Funcionario', 'Administrador'])
                ->whereRaw('LOWER(estado_cuenta) = ?', ['activo'])
                ->first();

            if (!$responsable) {
                return response()->json(['message' => 'El responsable debe ser un funcionario o administrador activo del área del ticket.'], 422);
            }
        }

        $newDbPriority = $this->mapUiPriorityToDb((string) $data['priority']);

        $oldResponsibleId = $ticket->ID_usuario_responsable ? (int) $ticket->ID_usuario_responsable : null;
        $oldAreaId = (int) $ticket->ID_area;
        $oldPriority = (string) ($ticket->prioridad ?? 'Media');
        $oldStatus = (string) $ticket->estado_ticket;

        $responsibleChanged = $newResponsibleId !== $oldResponsibleId;
        $areaChanged = $newAreaId !== $oldAreaId;
        $priorityChanged = $newDbPriority !== $oldPriority;

        $requestedStatus = isset($data['status']) ? (string) $data['status'] : null;
        $newStatus = $requestedStatus ?? $oldStatus;
        if ($requestedStatus === null && $oldResponsibleId === null && $newResponsibleId !== null && $oldStatus === 'Nuevo') {
            $newStatus = 'En proceso';
        }
        $statusChanged = $newStatus !== $oldStatus;

        $actorIsResponsible = $oldResponsibleId !== null && (int) $actor->ID_usuario === $oldResponsibleId;
        if (($statusChanged || $priorityChanged) && !$actorIsResponsible) {
            return response()->json(['message' => 'Solo el responsable del ticket puede cambiar estado o prioridad.'], 403);
        }

        if (!$responsibleChanged && !$areaChanged && !$priorityChanged && !$statusChanged) {
            return response()->json($this->serializeTicket($ticket));
        }

        DB::transaction(function () use (
            $ticket,
            $actor,
            $newResponsibleId,
            $newAreaId,
            $newDbPriority,
            $newStatus,
            $oldResponsibleId,
            $oldAreaId,
            $oldPriority,
            $oldStatus,
            $responsibleChanged,
            $areaChanged,
            $priorityChanged,
            $statusChanged
        ) {
            $ticket->ID_usuario_responsable = $newResponsibleId;
            $ticket->ID_area = $newAreaId;
            $ticket->prioridad = $newDbPriority;
            $ticket->estado_ticket = $newStatus;
            $ticket->fecha_actualizacion = now();
            $ticket->fecha_cierre = $newStatus === 'Cerrado' ? now() : null;
            $ticket->save();

            if ($responsibleChanged) {
                $this->insertTicketLog(
                    ticketId: (int) $ticket->ID_ticket,
                    actorUserId: (int) $actor->ID_usuario,
                    action: $oldResponsibleId === null ? 'ASIGNACION_RESPONSABLE' : 'CAMBIO_RESPONSABLE',
                    oldValue: $oldResponsibleId === null ? null : (string) $oldResponsibleId,
                    newValue: $newResponsibleId === null ? null : (string) $newResponsibleId
                );
            }

            if ($areaChanged) {
                $this->insertTicketLog(
                    ticketId: (int) $ticket->ID_ticket,
                    actorUserId: (int) $actor->ID_usuario,
                    action: 'CAMBIO_AREA',
                    oldValue: (string) $oldAreaId,
                    newValue: (string) $newAreaId
                );
            }

            if ($statusChanged) {
                $this->insertTicketLog(
                    ticketId: (int) $ticket->ID_ticket,
                    actorUserId: (int) $actor->ID_usuario,
                    action: $newStatus === 'Cerrado' ? 'CIERRE' : 'CAMBIO_ESTADO',
                    oldValue: $oldStatus,
                    newValue: $newStatus
                );
            }

            if ($priorityChanged) {
                $ticket->prioridad = $newDbPriority;
            }
        });

        $ticket->load(['area', 'solicitante', 'responsable']);

        return response()->json($this->serializeTicket($ticket));
    }

    public function messages(string $ticketId)
    {
        $id = (int) preg_replace('/\D+/', '', $ticketId);
        $ticket = Ticket::query()->findOrFail($id);
        $this->autoCloseResolvedIfExpired($ticket);

        $messages = Mensaje::query()
            ->with(['usuario', 'adjuntos']) 
            ->where('ID_ticket', $ticket->ID_ticket)
            ->orderBy('fecha_hora')
            ->orderBy('ID_mensaje')
            ->get();

        $messageTimeline = $messages->map(function (Mensaje $message) {
            $senderRole = (string) ($message->usuario?->rol ?? 'Estudiante');
            $isAgent = in_array($senderRole, ['Funcionario', 'Administrador'], true);

            $attachments = $message->adjuntos->map(function ($adj) {
                return [
                    'name' => $adj->nombre_archivo,
                    'url' => url('storage/' . $adj->ruta_archivo) 
                ];
            })->toArray();

            return [
                'id' => 'msg-' . (string) $message->ID_mensaje,
                'type' => 'message',
                'role' => $isAgent ? 'agent' : 'user',
                'senderUserId' => $message->ID_usuario ? (int) $message->ID_usuario : null,
                'senderRole' => $senderRole,
                'senderName' => $message->usuario?->nombre_completo,
                'content' => $message->contenido,
                'timestamp' => $message->fecha_hora,
                'agentName' => $isAgent ? ($message->usuario?->nombre_completo ?? 'Agente de Soporte') : null,
                'attachments' => empty($attachments) ? null : $attachments,
                '_sortKey' => 'msg-' . str_pad((string) $message->ID_mensaje, 12, '0', STR_PAD_LEFT),
            ];
        })->values()->all();

        $logs = DB::table('historial_ticket')
            ->where('ID_ticket', $ticket->ID_ticket)
            ->orderBy('fecha_hora')
            ->orderBy('ID_log')
            ->get();

        $userIds = [];
        $areaIds = [];
        foreach ($logs as $log) {
            $actorId = (int) ($log->ID_usuario ?? 0);
            if ($actorId > 0) {
                $userIds[] = $actorId;
            }
            if (in_array((string) $log->accion, ['ASIGNACION_RESPONSABLE', 'CAMBIO_RESPONSABLE'], true)) {
                $newResponsibleId = (int) ($log->valor_nuevo ?? 0);
                if ($newResponsibleId > 0) {
                    $userIds[] = $newResponsibleId;
                }
            }
            if ((string) $log->accion === 'CAMBIO_AREA') {
                $oldAreaId = (int) ($log->valor_anterior ?? 0);
                $newAreaId = (int) ($log->valor_nuevo ?? 0);
                if ($oldAreaId > 0) {
                    $areaIds[] = $oldAreaId;
                }
                if ($newAreaId > 0) {
                    $areaIds[] = $newAreaId;
                }
            }
        }

        $userIds = array_values(array_unique($userIds));
        $areaIds = array_values(array_unique($areaIds));

        $usersById = Usuario::query()
            ->whereIn('ID_usuario', $userIds)
            ->get(['ID_usuario', 'nombre_completo'])
            ->keyBy('ID_usuario');

        $areasById = Area::query()
            ->whereIn('ID_area', $areaIds)
            ->get(['ID_area', 'nombre_area'])
            ->keyBy('ID_area');

        $logTimeline = collect($logs)->map(function ($log) use ($usersById, $areasById) {
            return [
                'id' => 'log-' . (string) $log->ID_log,
                'type' => 'system',
                'role' => 'system',
                'content' => $this->buildLogMessage($log, $usersById, $areasById),
                'timestamp' => $log->fecha_hora,
                '_sortKey' => 'log-' . str_pad((string) $log->ID_log, 12, '0', STR_PAD_LEFT),
            ];
        })->values()->all();

        $timeline = array_merge($messageTimeline, $logTimeline);

        usort($timeline, function (array $a, array $b) {
            $left = (string) ($a['timestamp'] ?? '');
            $right = (string) ($b['timestamp'] ?? '');
            $byTime = strcmp($left, $right);
            if ($byTime !== 0) {
                return $byTime;
            }

            return strcmp((string) ($a['_sortKey'] ?? ''), (string) ($b['_sortKey'] ?? ''));
        });

        $timeline = array_map(function (array $item) {
            unset($item['_sortKey']);
            return $item;
        }, $timeline);

        return response()->json($timeline);
    }

    public function storeMessage(Request $request, string $ticketId)
    {
        $data = $request->validate([
            'userId' => ['required', 'integer'],
            'content' => ['required', 'string'],
        ]);

        $id = (int) preg_replace('/\D+/', '', $ticketId);
        $ticket = Ticket::query()->findOrFail($id);
        $this->autoCloseResolvedIfExpired($ticket);
        /** @var Usuario $actor */
        $actor = Usuario::query()->findOrFail((int) $data['userId']);

        if (in_array((string) $ticket->estado_ticket, ['Resuelto', 'Cerrado'], true)) {
            return response()->json(['message' => 'El chat no está disponible para tickets resueltos o cerrados.'], 403);
        }

        $isResponsible = (int) ($ticket->ID_usuario_responsable ?? 0) === (int) $actor->ID_usuario;
        $isRequester = (int) $ticket->ID_usuario_solicitante === (int) $actor->ID_usuario;

        if (!$isResponsible && !$isRequester) {
            return response()->json(['message' => 'Solo el responsable del ticket o el solicitante pueden responder en el chat.'], 403);
        }

        $message = Mensaje::query()->create([
            'ID_ticket' => $ticket->ID_ticket,
            'ID_usuario' => (int) $data['userId'],
            'contenido' => $data['content'],
            'fecha_hora' => now(),
        ]);

        $isAgent = in_array((string) $actor->rol, ['Funcionario', 'Administrador'], true);

        return response()->json([
            'id' => 'msg-' . (string) $message->ID_mensaje,
            'type' => 'message',
            'role' => $isAgent ? 'agent' : 'user',
            'senderUserId' => (int) $actor->ID_usuario,
            'senderRole' => (string) $actor->rol,
            'senderName' => $actor->nombre_completo,
            'content' => $message->contenido,
            'timestamp' => $message->fecha_hora,
            'agentName' => $isAgent ? ($actor->nombre_completo ?? 'Agente de Soporte') : null,
        ], 201);
    }

    public function requesterDecision(Request $request, string $ticketId)
    {
        $data = $request->validate([
            'userId' => ['required', 'integer'],
            'solved' => ['required', 'boolean'],
        ]);

        $id = (int) preg_replace('/\D+/', '', $ticketId);
        /** @var Ticket $ticket */
        $ticket = Ticket::query()->with(['area', 'solicitante', 'responsable'])->findOrFail($id);
        $this->autoCloseResolvedIfExpired($ticket);
        /** @var Usuario $actor */
        $actor = Usuario::query()->findOrFail((int) $data['userId']);

        $isRequester = (int) $ticket->ID_usuario_solicitante === (int) $actor->ID_usuario;
        if (!$isRequester) {
            return response()->json(['message' => 'Solo el solicitante del ticket puede confirmar la solución.'], 403);
        }

        $oldStatus = (string) $ticket->estado_ticket;
        if ($oldStatus !== 'Resuelto') {
            return response()->json(['message' => 'Este ticket no está en estado Resuelto para confirmar.'], 422);
        }

        $newStatus = (bool) $data['solved'] ? 'Cerrado' : 'En proceso';

        DB::transaction(function () use ($ticket, $actor, $oldStatus, $newStatus, $data) {
            $ticket->estado_ticket = $newStatus;
            $ticket->fecha_actualizacion = now();
            $ticket->fecha_cierre = (bool) $data['solved'] ? now() : null;
            $ticket->save();

            $this->insertTicketLog(
                ticketId: (int) $ticket->ID_ticket,
                actorUserId: (int) $actor->ID_usuario,
                action: (bool) $data['solved'] ? 'CIERRE' : 'CAMBIO_ESTADO',
                oldValue: $oldStatus,
                newValue: $newStatus
            );
        });

        $ticket->load(['area', 'solicitante', 'responsable']);

        return response()->json($this->serializeTicket($ticket));
    }

    public function reportTicket(Request $request, string $ticketId)
    {
        $data = $request->validate([
            'actorUserId' => ['required', 'integer'],
            'newAreaId' => ['required', 'integer'],
            'reason' => ['required', 'string'],
        ]);

        $id = (int) preg_replace('/\D+/', '', $ticketId);
        /** @var Ticket $ticket */
        $ticket = Ticket::query()->with(['area', 'solicitante', 'responsable'])->findOrFail($id);
        $this->autoCloseResolvedIfExpired($ticket);

        /** @var Usuario $actor */
        $actor = Usuario::query()->findOrFail((int) $data['actorUserId']);

        $oldResponsibleId = $ticket->ID_usuario_responsable ? (int) $ticket->ID_usuario_responsable : null;
        if ($oldResponsibleId === null) {
            return response()->json(['message' => 'Este ticket no tiene responsable asignado para reportar.'], 422);
        }

        if ((int) $actor->ID_usuario !== $oldResponsibleId) {
            return response()->json(['message' => 'Solo el responsable actual puede reportar este ticket.'], 403);
        }

        $oldAreaId = (int) $ticket->ID_area;
        $newAreaId = (int) $data['newAreaId'];
        if ($newAreaId === $oldAreaId) {
            return response()->json(['message' => 'La nueva área debe ser diferente al área actual del ticket.'], 422);
        }

        Area::query()->findOrFail($newAreaId);

        $oldStatus = (string) $ticket->estado_ticket;
        $reason = trim((string) $data['reason']);

        DB::transaction(function () use ($ticket, $actor, $oldAreaId, $newAreaId, $oldResponsibleId, $oldStatus, $reason) {
            DB::table('reportes_ticket')->insert([
                'ID_ticket' => (int) $ticket->ID_ticket,
                'ID_usuario_reporta' => (int) $oldResponsibleId,
                'ID_area_original' => $oldAreaId,
                'ID_area_nueva' => $newAreaId,
                'motivo' => $reason,
                'fecha_reporte' => now(),
            ]);

            $ticket->ID_usuario_responsable = null;
            $ticket->ID_area = $newAreaId;
            $ticket->estado_ticket = 'Nuevo';
            $ticket->fecha_actualizacion = now();
            $ticket->fecha_cierre = null;
            $ticket->save();

            $this->insertTicketLog(
                ticketId: (int) $ticket->ID_ticket,
                actorUserId: (int) $actor->ID_usuario,
                action: 'CAMBIO_RESPONSABLE',
                oldValue: (string) $oldResponsibleId,
                newValue: null
            );

            $this->insertTicketLog(
                ticketId: (int) $ticket->ID_ticket,
                actorUserId: (int) $actor->ID_usuario,
                action: 'CAMBIO_AREA',
                oldValue: (string) $oldAreaId,
                newValue: (string) $newAreaId
            );

            $this->insertTicketLog(
                ticketId: (int) $ticket->ID_ticket,
                actorUserId: (int) $actor->ID_usuario,
                action: 'CAMBIO_ESTADO',
                oldValue: $oldStatus,
                newValue: 'Nuevo'
            );
        });

        $ticket->load(['area', 'solicitante', 'responsable']);

        return response()->json([
            'message' => 'Reporte registrado y ticket reasignado correctamente.',
            'ticket' => $this->serializeTicket($ticket),
        ]);
    }

    public function misroutedReports()
    {
        $rows = DB::table('reportes_ticket as r')
            ->join('tickets as t', 't.ID_ticket', '=', 'r.ID_ticket')
            ->join('usuarios as u', 'u.ID_usuario', '=', 'r.ID_usuario_reporta')
            ->join('areas as a_old', 'a_old.ID_area', '=', 'r.ID_area_original')
            ->join('areas as a_new', 'a_new.ID_area', '=', 'r.ID_area_nueva')
            ->orderBy('r.fecha_reporte')
            ->orderBy('r.ID_reporte')
            ->get([
                'r.ID_reporte',
                'r.fecha_reporte',
                'r.motivo',
                't.ID_ticket',
                't.titulo',
                'u.nombre_completo as reporting_user',
                'a_old.nombre_area as original_area',
                'a_new.nombre_area as suggested_area',
            ]);

        return response()->json($rows->map(function ($row) {
            return [
                'id' => 'REP-' . str_pad((string) $row->ID_reporte, 4, '0', STR_PAD_LEFT),
                'ticketId' => 'TK-' . str_pad((string) $row->ID_ticket, 4, '0', STR_PAD_LEFT),
                'ticketTitle' => (string) ($row->titulo ?? 'Sin título'),
                'reportingUser' => (string) ($row->reporting_user ?? 'Funcionario'),
                'userArea' => (string) ($row->original_area ?? 'Área desconocida'),
                'correctArea' => (string) ($row->suggested_area ?? 'Área desconocida'),
                'reason' => (string) ($row->motivo ?? ''),
                'date' => (string) ($row->fecha_reporte ?? now()),
            ];
        })->values());
    }

    public function deleteMisroutedReport(string $reportId)
    {
        $id = (int) preg_replace('/\D+/', '', $reportId);
        if ($id <= 0) {
            return response()->json(['message' => 'Identificador de reporte inválido.'], 422);
        }

        $deleted = DB::table('reportes_ticket')
            ->where('ID_reporte', $id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Reporte no encontrado.'], 404);
        }

        return response()->json(['message' => 'Reporte descartado correctamente.']);
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

    private function mapUiPriorityToDb(string $priority): string
    {
        return match ($priority) {
            'Crítica' => 'Urgente',
            default => $priority,
        };
    }

    private function mapDbPriorityToUi(?string $priority): string
    {
        return match ($priority) {
            'Urgente' => 'Crítica',
            null => 'Media',
            default => $priority,
        };
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

    private function autoCloseResolvedIfExpired(Ticket $ticket): void
    {
        if ((string) $ticket->estado_ticket !== 'Resuelto') {
            return;
        }

        $resolvedLog = DB::table('historial_ticket')
            ->where('ID_ticket', $ticket->ID_ticket)
            ->where('accion', 'CAMBIO_ESTADO')
            ->where('valor_nuevo', 'Resuelto')
            ->orderByDesc('fecha_hora')
            ->orderByDesc('ID_log')
            ->first();

        if (!$resolvedLog || empty($resolvedLog->fecha_hora)) {
            return;
        }

        $resolvedAt = Carbon::parse((string) $resolvedLog->fecha_hora);
        if (Carbon::now()->lt($resolvedAt->copy()->addHours(36))) {
            return;
        }

        DB::transaction(function () use ($ticket, $resolvedLog) {
            /** @var Ticket|null $freshTicket */
            $freshTicket = Ticket::query()->find($ticket->ID_ticket);
            if (!$freshTicket || (string) $freshTicket->estado_ticket !== 'Resuelto') {
                return;
            }

            $freshTicket->estado_ticket = 'Cerrado';
            $freshTicket->fecha_actualizacion = now();
            $freshTicket->fecha_cierre = now();
            $freshTicket->save();

            $actorUserId = (int) ($resolvedLog->ID_usuario ?? 0);
            if ($actorUserId <= 0) {
                $actorUserId = (int) ($freshTicket->ID_usuario_responsable ?? $freshTicket->ID_usuario_solicitante ?? 0);
            }

            if ($actorUserId > 0) {
                $this->insertTicketLog(
                    ticketId: (int) $freshTicket->ID_ticket,
                    actorUserId: $actorUserId,
                    action: 'CIERRE',
                    oldValue: 'Resuelto',
                    newValue: 'Cerrado'
                );
            }

            $ticket->estado_ticket = $freshTicket->estado_ticket;
            $ticket->fecha_actualizacion = $freshTicket->fecha_actualizacion;
            $ticket->fecha_cierre = $freshTicket->fecha_cierre;
        });
    }

    private function resolveUserName($usersById, ?int $userId): string
    {
        if ($userId === null || $userId <= 0) {
            return 'Usuario';
        }

        $name = $usersById->get($userId)?->nombre_completo;
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return 'Usuario #' . $userId;
    }

    private function resolveAreaName($areasById, ?int $areaId): string
    {
        if ($areaId === null || $areaId <= 0) {
            return 'Área desconocida';
        }

        $name = $areasById->get($areaId)?->nombre_area;
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return 'Área #' . $areaId;
    }

    private function buildLogMessage(object $log, $usersById, $areasById): string
    {
        $action = (string) ($log->accion ?? '');
        $actorName = $this->resolveUserName($usersById, (int) ($log->ID_usuario ?? 0));

        return match ($action) {
            'CREACION_TICKET' => $actorName . ' creó el ticket.',
            'ASIGNACION_RESPONSABLE' => $this->resolveUserName($usersById, (int) ($log->valor_nuevo ?? 0)) . ' fue asignada como responsable del ticket.',
            'CAMBIO_RESPONSABLE' => $actorName . ' reasignó el ticket a ' . $this->resolveUserName($usersById, (int) ($log->valor_nuevo ?? 0)) . '.',
            'CAMBIO_AREA' => $actorName . ' cambió el área del ticket de ' . $this->resolveAreaName($areasById, (int) ($log->valor_anterior ?? 0)) . ' a ' . $this->resolveAreaName($areasById, (int) ($log->valor_nuevo ?? 0)) . '.',
            'CAMBIO_ESTADO' => ((string) ($log->valor_anterior ?? '') === 'Resuelto' && (string) ($log->valor_nuevo ?? '') === 'En proceso')
                ? $actorName . ' rechazó la solución y devolvió el ticket a "En proceso".'
                : $actorName . ' cambió el estado del ticket de "' . (string) ($log->valor_anterior ?? '') . '" a "' . (string) ($log->valor_nuevo ?? '') . '".',
            'CIERRE' => $actorName . ' cerró el ticket.',
            default => $actorName . ' registró un cambio en el ticket.',
        };
    }

    private function serializeTicket(Ticket $ticket): array
    {
        return [
            'id' => 'TK-' . str_pad((string) $ticket->ID_ticket, 4, '0', STR_PAD_LEFT),
            'title' => $ticket->titulo,
            'status' => $this->mapStatus((string) $ticket->estado_ticket),
            'statusDb' => (string) $ticket->estado_ticket,
            'priority' => $this->mapPriority($ticket->prioridad),
            'priorityDb' => $this->mapDbPriorityToUi($ticket->prioridad),
            'area' => $ticket->area?->nombre_area ?? 'Sin área',
            'areaId' => (int) $ticket->ID_area,
            'department' => $ticket->area?->nombre_area ?? 'Sin área',
            'requester' => $ticket->solicitante?->nombre_completo ?? 'Sin solicitante',
            'requesterUserId' => (int) $ticket->ID_usuario_solicitante,
            'assignedTo' => $ticket->responsable?->nombre_completo,
            'assignedUserId' => $ticket->ID_usuario_responsable ? (int) $ticket->ID_usuario_responsable : null,
            'date' => $ticket->fecha_creacion ? substr((string) $ticket->fecha_creacion, 0, 10) : '',
            'lastUpdate' => $ticket->fecha_actualizacion ? substr((string) $ticket->fecha_actualizacion, 0, 10) : '',
        ];
    }
}