<?php

namespace App\Http\Controllers;

use App\Services\Bot\CommandRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Exceptions\TelegramSDKException;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly CommandRouter $router,
    ) {}

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

        try {
            $this->router->handle($update);
        } catch (TelegramSDKException $exception) {
            Log::channel('single')->error('Telegram reply failed, asking for redelivery', [
                'update_id' => $update['update_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Determine the type of the incoming update.
     */
    private function updateType(array $update): ?string
    {
        $types = ['message', 'edited_message', 'channel_post', 'inline_query', 'callback_query'];

        return array_find($types, fn ($type) => array_key_exists($type, $update));
    }
}
