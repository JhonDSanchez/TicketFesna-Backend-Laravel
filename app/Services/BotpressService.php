<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BotpressService
{
    public function createUser(string $userId): array
    {
        $result = $this->request('POST', '/users', [
            'id' => $userId,
        ]);

        if (!$result['ok'] && is_array($result['body'])) {
            $message = $result['body']['message'] ?? '';
            if (is_string($message) && str_contains($message, 'already exists')) {
                Log::info('Botpress createUser: user already exists, intentando getOrCreateUser', [
                    'requestedUserId' => $userId,
                    'body' => $result['body'],
                ]);

                $orCreate = $this->getOrCreateUser($userId);
                if ($orCreate['ok']) {
                    return $orCreate;
                }

                $fallbackId = $userId . '-' . Str::random(8);
                Log::info('Botpress createUser: getOrCreateUser falló, creando fallback ID', [
                    'requestedUserId' => $userId,
                    'fallbackUserId' => $fallbackId,
                    'fallbackBody' => $orCreate['body'],
                ]);

                return $this->request('POST', '/users', [
                    'id' => $fallbackId,
                ]);
            }
        }

        return $result;
    }

    public function getOrCreateUser(string $userId): array
    {
        return $this->request('POST', '/users/get-or-create', [
            'id' => $userId,
        ]);
    }

    public function createConversation(string $userKey, ?string $conversationId = null): array
    {
        if ($conversationId === null || trim($conversationId) === '') {
            $conversationId = 'conversation_' . Str::uuid()->toString();
        }

        return $this->request('POST', '/conversations', [
            'id' => $conversationId,
        ], [
            'x-user-key' => $userKey,
        ]);
    }

    public function getOrCreateConversation(string $userKey, string $conversationId): array
    {
        $result = $this->request('POST', '/conversations/get-or-create', [
            'id' => $conversationId,
        ], [
            'x-user-key' => $userKey,
        ]);

        if ($result['status'] === 404 && is_array($result['body'])) {
            $message = $result['body']['message'] ?? '';
            if (is_string($message) && str_contains($message, "Method doesn't exist")) {
                Log::info('Botpress getOrCreateConversation fallback to createConversation due to missing support for get-or-create', [
                    'conversationId' => $conversationId,
                    'userKey' => $userKey,
                    'body' => $result['body'],
                ]);
                return $this->createConversation($userKey, null);
            }
        }

        if ($result['status'] === 403 && is_array($result['body'])) {
            $message = $result['body']['message'] ?? '';
            if (is_string($message) && str_contains(strtolower($message), 'participant')) {
                Log::info('Botpress getOrCreateConversation fallback to createConversation because user is not a participant', [
                    'conversationId' => $conversationId,
                    'userKey' => $userKey,
                    'body' => $result['body'],
                ]);
                return $this->createConversation($userKey, null);
            }
        }

        return $result;
    }

    public function sendMessage(string $userKey, string $conversationId, string $text): array
    {
        $result = $this->request('POST', '/messages', [
            'conversationId' => $conversationId,
            'payload' => [
                'type' => 'text',
                'text' => $text,
            ],
        ], [
            'x-user-key' => $userKey,
        ]);

        if (!$result['ok']) {
            Log::warning('Botpress sendMessage falló', [
                'userKey' => $userKey,
                'conversationId' => $conversationId,
                'text' => $text,
                'body' => $result['body'],
                'status' => $result['status'],
            ]);
        }

        return $result;
    }

    public function getUser(string $userKey): array
    {
        return $this->request('GET', '/users/me', null, [
            'x-user-key' => $userKey,
        ]);
    }

    public function listMessages(string $userKey, string $conversationId, ?string $nextToken = null): array
    {
        $path = '/conversations/' . urlencode($conversationId) . '/messages';

        if ($nextToken !== null && trim($nextToken) !== '') {
            $path .= '?nextToken=' . urlencode($nextToken);
        }

        return $this->request('GET', $path, null, [
            'x-user-key' => $userKey,
        ]);
    }

    public function getLatestMessage(string $userKey, string $conversationId): array
    {
        $result = $this->listMessages($userKey, $conversationId);
        if (!$result['ok'] || !is_array($result['body'])) {
            return [];
        }

        $messages = $result['body']['messages'] ?? $result['body'];
        if (!is_array($messages) || $messages === []) {
            return [];
        }

        usort($messages, fn (array $left, array $right) => strcmp(
            (string) ($left['createdAt'] ?? ''),
            (string) ($right['createdAt'] ?? '')
        ));

        $latest = end($messages);
        return is_array($latest) ? $latest : [];
    }

    public function sendMessageAndListen(string $userKey, string $conversationId, string $text, int $timeoutSeconds = 30, array $baselineMessage = []): array
    {
        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            return [
                'ok' => false,
                'status' => 500,
                'body' => ['message' => 'BOTPRESS_CHAT_URL no está configurado.'],
            ];
        }

        $sentMessage = [
            'payload' => [
                'type' => 'text',
                'text' => $text,
            ],
        ];
        $listenBuffer = '';
        $partialLine = '';
        $eventLines = [];
        $events = [];
        $reply = null;

        $consumeChunk = function (string $chunk) use (&$listenBuffer, &$partialLine, &$eventLines, &$events, &$reply, $sentMessage): void {
            $listenBuffer .= $chunk;
            $lines = preg_split('/\r\n|\n|\r/', $partialLine . $chunk);
            if ($chunk !== '' && !str_ends_with($chunk, "\n") && !str_ends_with($chunk, "\r")) {
                $partialLine = array_pop($lines);
            } else {
                $partialLine = '';
            }

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    if ($eventLines !== []) {
                        $event = $this->parseSseEvent($eventLines);
                        $events[] = $event;
                        $candidate = $this->extractAssistantMessageFromEvent($event, null, $sentMessage);
                        if (is_array($candidate)) {
                            $reply = $candidate;
                            return;
                        }
                        $eventLines = [];
                    }
                    continue;
                }

                $eventLines[] = $line;
            }
        };

        $listenHandle = curl_init($baseUrl . '/conversations/' . rawurlencode($conversationId) . '/listen');
        $sendHandle = curl_init($baseUrl . '/messages');
        curl_setopt_array($listenHandle, [
            CURLOPT_HTTPHEADER => [
                'Accept: text/event-stream',
                'Cache-Control: no-cache',
                'x-user-key: ' . $userKey,
            ],
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use ($consumeChunk): int {
                $consumeChunk($chunk);
                return strlen($chunk);
            },
            CURLOPT_TIMEOUT => $timeoutSeconds + 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_setopt_array($sendHandle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'x-user-key: ' . $userKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'conversationId' => $conversationId,
                'payload' => [
                    'type' => 'text',
                    'text' => $text,
                ],
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $multiHandle = curl_multi_init();
        curl_multi_add_handle($multiHandle, $listenHandle);
        curl_multi_add_handle($multiHandle, $sendHandle);
        $startedAt = microtime(true);
        $sendBody = '';
        $sendCompleted = false;

        do {
            do {
                $multiStatus = curl_multi_exec($multiHandle, $running);
            } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

            if (!$sendCompleted && curl_getinfo($sendHandle, CURLINFO_HTTP_CODE) > 0) {
                $sendCompleted = true;
                $sendBody = (string) curl_multi_getcontent($sendHandle);
            }

            if ($reply !== null) {
                break;
            }

            if ($running > 0) {
                $selected = curl_multi_select($multiHandle, 0.2);
                if ($selected === -1) {
                    usleep(10000);
                }
            }
        } while ($running > 0 && (microtime(true) - $startedAt) < $timeoutSeconds);

        if (!$sendCompleted) {
            $sendBody = (string) curl_multi_getcontent($sendHandle);
        }

        $sendStatus = (int) curl_getinfo($sendHandle, CURLINFO_HTTP_CODE);
        $sendError = curl_error($sendHandle);
        curl_multi_remove_handle($multiHandle, $listenHandle);
        curl_multi_remove_handle($multiHandle, $sendHandle);
        curl_close($listenHandle);
        curl_close($sendHandle);
        curl_multi_close($multiHandle);

        $decodedSendBody = json_decode($sendBody, true);
        if ($sendStatus < 200 || $sendStatus >= 300) {
            return [
                'ok' => false,
                'status' => $sendStatus ?: 500,
                'body' => is_array($decodedSendBody) ? $decodedSendBody : ['message' => $sendError ?: $sendBody],
            ];
        }

        if ($reply === null && $eventLines !== []) {
            $event = $this->parseSseEvent($eventLines);
            $events[] = $event;
            $reply = $this->extractAssistantMessageFromEvent($event, null, $sentMessage);
        }

        if ($reply === null) {
            $reply = $this->getLatestAssistantMessageFromConversation($userKey, $conversationId, null, $baselineMessage, $sentMessage);
        }

        if (is_array($reply)) {
            return [
                'ok' => true,
                'status' => 200,
                'body' => [
                    'message' => $this->extractMessageText($reply),
                    'payload' => $reply['payload'] ?? [],
                    'events' => $events,
                ],
            ];
        }

        return [
            'ok' => false,
            'status' => 504,
            'body' => ['message' => 'No se recibió respuesta del bot en el tiempo esperado.', 'events' => $events],
        ];
    }

    public function listenConversation(string $userKey, string $conversationId, ?string $botpressUserId = null, int $timeoutSeconds = 10, array $baselineMessage = [], array $sentMessage = []): array
    {
        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            return [
                'ok' => false,
                'status' => 500,
                'body' => ['message' => 'BOTPRESS_CHAT_URL no está configurado.'],
            ];
        }

        $client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => $timeoutSeconds + 10,
            'connect_timeout' => 5,
        ]);

        try {
            $response = $client->request('GET', '/conversations/' . urlencode($conversationId) . '/listen', [
                'headers' => [
                    'Accept' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                    'x-user-key' => $userKey,
                ],
                'stream' => true,
            ]);
        } catch (RequestException $e) {
            $response = $e->hasResponse() ? $e->getResponse() : null;
            Log::warning('Botpress listenConversation falló', [
                'url' => $baseUrl . '/conversations/' . urlencode($conversationId) . '/listen',
                'status' => $response?->getStatusCode(),
                'body' => $response?->getBody()->getContents(),
                'exception' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => $response?->getStatusCode() ?? 500,
                'body' => ['message' => $response?->getBody()->getContents() ?? 'Error al iniciar la escucha de la conversación.'],
            ];
        }

        $stream = $response->getBody();
        $startTime = microtime(true);
        $buffer = '';
        $partialLine = '';
        $eventLines = [];
        $events = [];

        while ((microtime(true) - $startTime) < $timeoutSeconds) {
            if ($stream->eof()) {
                break;
            }

            $chunk = $stream->read(1024);
            if ($chunk === '') {
                usleep(100000);
                continue;
            }

            $buffer = $partialLine . $chunk;
            $lines = preg_split('/\r\n|\n|\r/', $buffer);
            if ($buffer !== '' && !str_ends_with($buffer, "\n") && !str_ends_with($buffer, "\r")) {
                $partialLine = array_pop($lines);
            } else {
                $partialLine = '';
            }

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    if ($eventLines !== []) {
                        $event = $this->parseSseEvent($eventLines);
                        $events[] = $event;

                        $assistantMessage = $this->extractAssistantMessageFromEvent($event, $botpressUserId, $sentMessage);
                        if (is_array($assistantMessage)) {
                            return [
                                'ok' => true,
                                'status' => $response->getStatusCode(),
                                'body' => [
                                    'message' => $this->extractMessageText($assistantMessage),
                                    'payload' => $assistantMessage['payload'] ?? [],
                                    'events' => $events,
                                ],
                            ];
                        }

                        $eventLines = [];
                    }
                    continue;
                }

                $eventLines[] = $line;
            }
        }

        if ($eventLines !== []) {
            $event = $this->parseSseEvent($eventLines);
            $events[] = $event;
            $assistantMessage = $this->extractAssistantMessageFromEvent($event, $botpressUserId, $sentMessage);
            if (is_array($assistantMessage)) {
                return [
                    'ok' => true,
                    'status' => $response->getStatusCode(),
                    'body' => [
                        'message' => $this->extractMessageText($assistantMessage),
                        'payload' => $assistantMessage['payload'] ?? [],
                        'events' => $events,
                    ],
                ];
            }
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            usleep(300000);
            $assistantMessage = $this->getLatestAssistantMessageFromConversation($userKey, $conversationId, $botpressUserId, $baselineMessage, $sentMessage);
            if (is_array($assistantMessage)) {
                return [
                    'ok' => true,
                    'status' => 200,
                    'body' => [
                        'message' => $this->extractMessageText($assistantMessage),
                        'payload' => $assistantMessage['payload'] ?? [],
                        'events' => $events,
                        'fallback' => true,
                        'pollAttempt' => $attempt + 1,
                    ],
                ];
            }
        }

        Log::warning('Botpress listenConversation expiró sin mensaje del asistente', [
            'conversationId' => $conversationId,
            'userKey' => $userKey,
            'events' => $events,
        ]);

        return [
            'ok' => false,
            'status' => 504,
            'body' => ['message' => 'No se recibió respuesta del bot en el tiempo esperado.'],
        ];
    }

    public function getLatestAssistantMessageFromConversation(string $userKey, string $conversationId, ?string $botpressUserId = null, array $baselineMessage = [], array $sentMessage = []): ?array
    {
        $messagesResult = $this->listMessages($userKey, $conversationId);
        if (!$messagesResult['ok'] || !is_array($messagesResult['body'])) {
            return null;
        }

        $messages = $messagesResult['body']['messages'] ?? $messagesResult['body'];
        if (!is_array($messages)) {
            return null;
        }

        usort($messages, fn (array $left, array $right) => strcmp(
            (string) ($left['createdAt'] ?? ''),
            (string) ($right['createdAt'] ?? '')
        ));

        $baselineId = $baselineMessage['id'] ?? null;
        $baselineCreatedAt = $baselineMessage['createdAt'] ?? null;
        $sentId = $sentMessage['id'] ?? null;
        $sentText = $this->extractMessageText($sentMessage);
        $afterBaseline = $baselineId === null && $baselineCreatedAt === null;

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $content = $this->extractMessageText($message);
            $type = $message['payload']['type'] ?? null;

            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            if ($type !== null && !in_array($type, ['text', 'choice'], true)) {
                continue;
            }

            if ($sentId !== null && ($message['id'] ?? null) === $sentId) {
                continue;
            }

            if ($sentId === null && is_string($sentText) && trim($sentText) === trim($content)) {
                continue;
            }

            if ($baselineId !== null) {
                if (!$afterBaseline) {
                    if (($message['id'] ?? null) === $baselineId) {
                        $afterBaseline = true;
                    }
                    continue;
                }
            } elseif ($baselineCreatedAt !== null) {
                if (!$afterBaseline) {
                    if (strcmp((string) ($message['createdAt'] ?? ''), (string) $baselineCreatedAt) > 0) {
                        $afterBaseline = true;
                    } else {
                        continue;
                    }
                }
            }

            return $message;
        }

        return null;
    }

    protected function parseSseEvent(array $lines): array
    {
        $eventName = null;
        $dataLines = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'event:')) {
                $eventName = trim(substr($line, strlen('event:')));
                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $dataLines[] = substr($line, strlen('data:'));
            }
        }

        $rawData = trim(implode("\n", $dataLines));
        $decoded = json_decode($rawData, true);

        return [
            'event' => $eventName,
            'raw' => $rawData,
            'json' => $decoded,
        ];
    }

    protected function extractMessageText(array $message): ?string
    {
        $payload = $message['payload'] ?? null;
        if (is_array($payload) && is_string($payload['text'] ?? null) && trim($payload['text']) !== '') {
            return trim($payload['text']);
        }

        if (is_string($message['text'] ?? null) && trim($message['text']) !== '') {
            return trim($message['text']);
        }

        return null;
    }

    protected function extractAssistantMessageFromEvent(array $event, ?string $botpressUserId = null, array $sentMessage = []): ?array
    {
        $payload = $event['json'] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        $candidates = [];
        if (isset($payload['message']) && is_array($payload['message'])) {
            $candidates[] = $payload['message'];
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            if (isset($payload['data']['message']) && is_array($payload['data']['message'])) {
                $candidates[] = $payload['data']['message'];
            } elseif (isset($payload['data']['messages']) && is_array($payload['data']['messages'])) {
                $candidates = array_merge($candidates, $payload['data']['messages']);
            } else {
                $candidates[] = $payload['data'];
            }
        }
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            $candidates = array_merge($candidates, $payload['messages']);
        }
        if (isset($payload['payload']) && is_array($payload['payload'])) {
            $candidates[] = $payload['payload'];
        }

        if ($candidates === []) {
            $candidates[] = $payload;
        }

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $msgUserId = $candidate['userId'] ?? $candidate['message']['userId'] ?? null;
            $msgId = $candidate['id'] ?? $candidate['message']['id'] ?? null;
            $content = $candidate['payload']['text'] ?? $candidate['text'] ?? null;
            $type = $candidate['payload']['type'] ?? $candidate['type'] ?? null;

            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            if ($type !== null && !in_array($type, ['text', 'choice'], true)) {
                continue;
            }

            if (($sentMessage['id'] ?? null) !== null && $msgId === $sentMessage['id']) {
                continue;
            }

            if (($sentMessage['id'] ?? null) === null && $this->extractMessageText($sentMessage) === trim($content)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    public function checkHealth(): array
    {
        return $this->request('GET', '/hello');
    }

    public function sendEvent(array $payload): array
    {
        $url = (string) config('services.botpress.webhook_url', '');
        $token = (string) config('services.botpress.token', '');

        if ($url === '') {
            return [
                'ok' => false,
                'status' => 500,
                'body' => ['message' => 'BOTPRESS_WEBHOOK_URL no está configurado.'],
            ];
        }

        $request = Http::timeout(12)->withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post($url, $payload);

        if ($response->failed()) {
            Log::warning('Botpress webhook falló', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    protected function request(string $method, string $path, array|string|null $body = null, array $headers = []): array
    {
        $baseUrl = $this->getBaseUrl();

        if ($baseUrl === '') {
            return [
                'ok' => false,
                'status' => 500,
                'body' => ['message' => 'BOTPRESS_CHAT_URL no está configurado.'],
            ];
        }

        $request = Http::timeout(12)->withHeaders(array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $headers));

        $response = match (strtoupper($method)) {
            'GET' => $request->get($baseUrl . $path),
            'POST' => $request->post($baseUrl . $path, $body ?? []),
            'PUT' => $request->put($baseUrl . $path, $body ?? []),
            'DELETE' => $request->delete($baseUrl . $path, $body ?? []),
            default => null,
        };

        if ($response === null) {
            return [
                'ok' => false,
                'status' => 500,
                'body' => ['message' => 'Método HTTP inválido para Botpress.'],
            ];
        }

        if ($response->failed()) {
            Log::warning('Botpress Chat API falló', [
                'url' => $baseUrl . $path,
                'method' => $method,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    protected function getBaseUrl(): string
    {
        $chatUrl = (string) config('services.botpress.chat_url', '');

        if (trim($chatUrl) !== '') {
            return rtrim($chatUrl, '/');
        }

        $webhookUrl = (string) config('services.botpress.webhook_url', '');
        if (trim($webhookUrl) === '') {
            return '';
        }

        $parsed = parse_url($webhookUrl);
        if (!isset($parsed['path'])) {
            return '';
        }

        $webhookId = trim($parsed['path'], '/');
        if ($webhookId === '') {
            return '';
        }

        return 'https://chat.botpress.cloud/' . $webhookId;
    }
}
