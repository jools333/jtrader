<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Telegram\TelegramService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramServiceTest extends TestCase
{
    public function test_is_configured_returns_true_when_credentials_present(): void
    {
        $service = new TelegramService(
            http: null,
            botToken: '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
            chatId: '-1001234567890'
        );

        $this->assertTrue($service->isConfigured());
        $this->assertSame('-1001234567890', $service->getChatId());
    }

    public function test_is_configured_returns_false_when_empty(): void
    {
        $service = new TelegramService(
            http: null,
            botToken: '',
            chatId: ''
        );

        $this->assertFalse($service->isConfigured());
    }

    public function test_send_message_posts_to_telegram_api(): void
    {
        Http::fake([
            'https://api.telegram.org/bot123456:TOKEN/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 42],
            ], 200),
        ]);

        $service = new TelegramService(
            http: app(HttpFactory::class),
            botToken: '123456:TOKEN',
            chatId: '-100999999'
        );

        $success = $service->sendMessage('<b>Hello World</b>');

        $this->assertTrue($success);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123456:TOKEN/sendMessage'
                && $request['chat_id'] === '-100999999'
                && $request['text'] === '<b>Hello World</b>'
                && $request['parse_mode'] === 'HTML'
                && $request['disable_web_page_preview'] === true;
        });
    }

    public function test_send_message_fails_gracefully_on_api_error(): void
    {
        Http::fake([
            'https://api.telegram.org/bot123456:TOKEN/sendMessage' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        $service = new TelegramService(
            http: app(HttpFactory::class),
            botToken: '123456:TOKEN',
            chatId: '-100999999'
        );

        $success = $service->sendMessage('Test');
        $this->assertFalse($success);
    }

    public function test_split_message_chunks_long_text(): void
    {
        $service = new TelegramService;

        // 100 lines of 50 chars = 5000 chars
        $line = str_repeat('a', 49);
        $text = implode("\n", array_fill(0, 100, $line));

        $chunks = $service->splitMessage($text, 1000);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(1000, mb_strlen($chunk));
        }

        // Total content should be preserved
        $joined = implode("\n", $chunks);
        $this->assertSame(str_replace("\n", '', $text), str_replace("\n", '', $joined));
    }
}
