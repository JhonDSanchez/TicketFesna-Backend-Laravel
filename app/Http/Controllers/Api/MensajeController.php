<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Mensaje;
use App\Models\Adjunto;
use Illuminate\Support\Facades\Storage;

class MensajeController extends Controller
{
    public function enviarMensaje(Request $request)
    {
        // 1. LIMPIAR LOS IDs: Convertimos "TK-0001" a 1 y "USR-001" a 1
        $request->merge([
            'ID_ticket' => (int) preg_replace('/\D+/', '', $request->input('ID_ticket', '0')),
            'ID_usuario' => (int) preg_replace('/\D+/', '', $request->input('ID_usuario', '0')),
        ]);

        // 2. Validar lo que envía el frontend (ahora sí pasará la validación de integer)
        $request->validate([
            'ID_ticket' => 'required|integer',
            'ID_usuario' => 'required|integer',
            'contenido' => 'nullable|string',
            'archivos.*' => 'nullable|file|max:10240' // Permite archivos de hasta 10MB
        ]);

        // 3. Crear el registro del Mensaje
        $mensaje = Mensaje::create([
            'ID_ticket' => $request->ID_ticket,
            'ID_usuario' => $request->ID_usuario,
            'contenido' => $request->contenido ?? '',
            'fecha_hora' => now(), 
        ]);

        // 4. Procesar y guardar los archivos
        if ($request->hasFile('archivos')) {
            foreach ($request->file('archivos') as $archivo) {
                // Guarda el archivo físico
                $ruta = $archivo->store('adjuntos_tickets', 'public');
                
                // Guarda el registro en la base de datos
                Adjunto::create([
                    'ID_mensaje' => $mensaje->ID_mensaje,
                    'nombre_archivo' => $archivo->getClientOriginalName(),
                    'ruta_archivo' => $ruta,
                    'tipo_archivo' => $archivo->getClientMimeType(),
                    'tamaño' => $archivo->getSize(),
                ]);
            }
        }

        // Cargar los adjuntos para devolverlos al frontend
        $mensaje->load('adjuntos');

        // 5. Mapear los adjuntos para que el frontend los lea directo
        $attachments = $mensaje->adjuntos->map(function ($adj) {
            return [
                'name' => $adj->nombre_archivo,
                'url' => url('storage/' . $adj->ruta_archivo) // Ruta pública lista para mostrarse
            ];
        })->toArray();

        return response()->json([
            'success' => true,
            'message' => 'Mensaje y archivos enviados correctamente',
            'data' => [
                'ID_mensaje' => $mensaje->ID_mensaje,
                'ID_usuario' => $mensaje->ID_usuario,
                'contenido' => $mensaje->contenido,
                'fecha_hora' => $mensaje->fecha_hora,
                'adjuntos' => empty($attachments) ? null : $attachments
            ]
        ], 201);
    }
}