<?php

namespace Tests\Feature;

use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    public function test_webhook_returns_ok_and_accepts_update(): void
    {
        $response = $this->post('/telegram/webhook', [
            'update_id' => 1001,
            'message' => [
                'message_id' => 1,
                'chat' => [
                    'id' => 42,
                    'type' => 'private',
                    'first_name' => 'Ivan',
                ],
                'from' => [
                    'id' => 42,
                    'is_bot' => false,
                    'first_name' => 'Ivan',
                ],
                'text' => 'кофе 150',
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_webhook_returns_ok_for_unknown_update_shape(): void
    {
        $response = $this->post('/telegram/webhook', [
            'update_id' => 1002,
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }
}
