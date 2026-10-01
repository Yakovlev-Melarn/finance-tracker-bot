<?php

namespace Tests\Unit\Services\Bot;

use App\Services\Bot\BotService;
use Mockery;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;

class BotServiceTest extends TestCase
{
    public function test_send_message_succeeds_on_first_attempt(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('sendMessage')
            ->once()
            ->with(['chat_id' => 42, 'text' => 'Привет'])
            ->andReturn(new Message(['message_id' => 1]));

        $service = new BotService($api);

        $message = $service->sendMessage(42, 'Привет');

        $this->assertSame(1, $message->message_id);
    }

    public function test_send_message_retries_after_transient_failures(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('sendMessage')
            ->times(3)
            ->andReturnUsing(function (): Message {
                static $attempts = 0;

                $attempts++;

                if ($attempts < 3) {
                    throw new TelegramSDKException('cURL error 35: TLS connect error');
                }

                return new Message(['message_id' => 7]);
            });

        $service = new BotService($api);

        $message = $service->sendMessage(42, 'Привет');

        $this->assertSame(7, $message->message_id);
    }

    public function test_send_message_throws_after_exhausting_retries(): void
    {
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('sendMessage')
            ->times(3)
            ->andThrow(new TelegramSDKException('cURL error 35: TLS connect error'));

        $service = new BotService($api);

        $this->expectException(TelegramSDKException::class);

        $service->sendMessage(42, 'Привет');
    }
}
