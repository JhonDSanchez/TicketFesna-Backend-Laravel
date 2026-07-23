<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BotpressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BotpressController extends Controller
{
    public function chat(Request $request, BotpressService $botpress): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'userId' => ['nullable', 'string', 'max:120'],
            'conversationId' => ['nullable', 'string', 'max:120'],
            'userKey' => ['nullable', 'string'],
        ]);

        $userId = (string) ($data['userId'] ?? 'web-user');
        $conversationId = $data['conversationId'] ?? null;
        $userKey = $data['userKey'] ?? null;

        if (!is_string($userKey) || trim($userKey) === '') {
            $userResult = $botpress->createUser($userId);
            if (!$userResult['ok']) {
                return response()->json([
                    'message' => 'No se pudo crear usuario para Botpress.',
                    'providerStatus' => $userResult['status'],
                    'providerMessage' => $userResult['body']['message'] ?? null,
                ], 502);
            }

            $userKey = $userResult['body']['key'] ?? null;
            $botpressUserId = $userResult['body']['user']['id'] ?? null;
            if (!is_string($userKey) || trim($userKey) === '' || !is_string($botpressUserId) || trim($botpressUserId) === '') {
                return response()->json([
                    'message' => 'Botpress devolvió datos de usuario inválidos.',
                    'providerStatus' => $userResult['status'],
                    'providerMessage' => json_encode($userResult['body']),
                ], 502);
            }
        } else {
            $botpressUserResult = $botpress->getUser($userKey);
            if (!$botpressUserResult['ok']) {
                Log::warning('Botpress getUser inválido, creando nuevo usuario', [
                    'userKey' => $userKey,
                    'status' => $botpressUserResult['status'],
                    'body' => $botpressUserResult['body'],
                ]);

                $conversationId = null;
                $userKey = null;

                $userResult = $botpress->createUser($userId);
                if (!$userResult['ok']) {
                    return response()->json([
                        'message' => 'No se pudo crear un nuevo usuario para Botpress después de que el userKey quedó inválido.',
                        'providerStatus' => $userResult['status'],
                        'providerMessage' => json_encode($userResult['body']),
                    ], 502);
                }

                $userKey = $userResult['body']['key'] ?? null;
                $botpressUserId = $userResult['body']['user']['id'] ?? null;
                if (!is_string($userKey) || trim($userKey) === '' || !is_string($botpressUserId) || trim($botpressUserId) === '') {
                    return response()->json([
                        'message' => 'Botpress devolvió datos de usuario inválidos luego de recrear el userKey.',
                        'providerStatus' => $userResult['status'],
                        'providerMessage' => json_encode($userResult['body']),
                    ], 502);
                }
            } else {
                $botpressUserId = $botpressUserResult['body']['user']['id'] ?? null;
                if (!is_string($botpressUserId) || trim($botpressUserId) === '') {
                    return response()->json([
                        'message' => 'Botpress devolvió un ID de usuario inválido.',
                        'providerStatus' => $botpressUserResult['status'],
                        'providerMessage' => json_encode($botpressUserResult['body']),
                    ], 502);
                }
            }
        }

        if (!is_string($conversationId) || trim($conversationId) === '') {
            $conversationId = 'conversation_' . 
                (string) 
                (function () {
                    return str_replace('-', '', (string) \Illuminate\Support\Str::uuid());
                })();
        }

        $conversationResult = $botpress->getOrCreateConversation($userKey, $conversationId);
        if (!$conversationResult['ok']) {
            return response()->json([
                'message' => 'No se pudo obtener o crear la conversación en Botpress.',
                'providerStatus' => $conversationResult['status'],
                'providerMessage' => $conversationResult['body']['message'] ?? null,
            ], 502);
        }

        $conversation = $conversationResult['body']['conversation'] ?? null;
        $conversationId = is_array($conversation) ? ($conversation['id'] ?? null) : null;
        if (!is_string($conversationId) || trim($conversationId) === '') {
            return response()->json([
                'message' => 'Botpress devolvió un ID de conversación inválido.',
                'providerStatus' => $conversationResult['status'],
                'providerMessage' => json_encode($conversationResult['body']),
            ], 502);
        }

        $baselineMessage = $botpress->getLatestMessage($userKey, $conversationId);

        $listenResult = $botpress->sendMessageAndListen($userKey, $conversationId, (string) $data['text'], 30, $baselineMessage);
        Log::info('BOTPRESS LISTEN CONVERSATION RESPONSE', [
            'response' => $listenResult['body'],
            'status' => $listenResult['status'],
        ]);

        if (!$listenResult['ok']) {
            return response()->json([
                'message' => $listenResult['body']['message'] ?? 'No se recibió respuesta del bot en el tiempo esperado.',
                'conversationId' => $conversationId,
                'userKey' => $userKey,
                'providerStatus' => $listenResult['status'],
                'providerMessage' => is_array($listenResult['body']) ? json_encode($listenResult['body']) : $listenResult['body'],
            ], 504);
        }

        $botReplyText = $listenResult['body']['message']
            ?? $listenResult['body']['payload']['text']
            ?? null;
        if (!is_string($botReplyText) || trim($botReplyText) === '') {
            return response()->json([
                'message' => 'No se recibió un mensaje de texto del asistente en el stream SSE.',
                'conversationId' => $conversationId,
                'userKey' => $userKey,
                'providerStatus' => $listenResult['status'],
                'providerMessage' => is_array($listenResult['body']) ? json_encode($listenResult['body']) : $listenResult['body'],
            ], 504);
        }

        return response()->json([
            'message' => $botReplyText,
            'payload' => $listenResult['body']['payload'] ?? [],
            'conversationId' => $conversationId,
            'userKey' => $userKey,
        ]);
    }
}
