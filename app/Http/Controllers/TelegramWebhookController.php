<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    /**
     * Handle an incoming Telegram update.
     */
    public function handle(Request $request): JsonResponse
    {
        $update = $request->all();

        Log::channel('single')->info('Telegram update received', [
            'update_id' => $update['update_id'] ?? null,
            'type' => $this->updateType($update),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Determine the type of the incoming update.
     */
    private function updateType(array $update): ?string
    {
        $types = ['message', 'edited_message', 'channel_post', 'inline_query', 'callback_query'];
        return array_find($types, fn($type) => array_key_exists($type, $update));

    }
}
