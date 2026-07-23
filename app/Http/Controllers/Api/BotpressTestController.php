<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BotpressService;
use Illuminate\Http\JsonResponse;

class BotpressTestController extends Controller
{
    public function ping(BotpressService $botpress): JsonResponse
    {
        $result = $botpress->sendEvent([
            'type' => 'test',
            'text' => 'ping desde Laravel',
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json($result);
    }
}
