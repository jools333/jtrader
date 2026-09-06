<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramService
{
    private readonly HttpFactory $http;

    private readonly ?string $botToken;

    private readonly ?string $chatId;

    public function __construct(
        ?HttpFactory $http = null,
        ?string $botToken = null,
        ?string $chatId = null,
    ) {
        $this->http = $http ?? app(HttpFactory::class);
        $this->botToken = $botToken ?? (string) config('services.telegram.bot_token', '');
        $this->chatId = $chatId ?? (string) config('services.telegram.chat_id', '');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->botToken) && ! empty($this->chatId);
    }

    public function getChatId(): ?string
    {
        return $this->chatId;
    }

    /**
     * Send a text message to Telegram. If the message exceeds 4000 characters,
     * it is automatically split into sequential chunks.
     */
    public function sendMessage(string $text, ?string $chatId = null, string $parseMode = 'HTML'): bool
    {
        $targetChatId = $chatId ?? $this->chatId;

        if (empty($this->botToken) || empty($targetChatId)) {
            Log::warning('TelegramService: bot_token or chat_id is missing.', [
                'bot_token_set' => ! empty($this->botToken),
                'chat_id_set' => ! empty($targetChatId),
            ]);

            return false;
        }

        $chunks = $this->splitMessage($text, 4000);
        $allSuccessful = true;

        foreach ($chunks as $chunk) {
            if (! $this->sendRawMessage($chunk, $targetChatId, $parseMode)) {
                $allSuccessful = false;
            }
        }

        return $allSuccessful;
    }

    /**
     * Send multiple pre-formatted messages in sequence.
     *
     * @param  array<string>  $messages
     */
    public function sendMessages(array $messages, ?string $chatId = null, string $parseMode = 'HTML'): bool
    {
        $allSuccessful = true;
        foreach ($messages as $msg) {
            if (! $this->sendMessage($msg, $chatId, $parseMode)) {
                $allSuccessful = false;
            }
        }

        return $allSuccessful;
    }

    private function sendRawMessage(string $text, string $chatId, string $parseMode): bool
    {
        $endpoint = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        try {
            $response = $this->http->post($endpoint, [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
                'disable_web_page_preview' => true,
            ]);

            if (! $response->successful() || $response->json('ok') !== true) {
                Log::error('TelegramService: API returned error', [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::error('TelegramService: Failed to send request', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Splits a long text message into safe chunks <= $maxLength characters,
     * breaking cleanly along line breaks whenever possible.
     *
     * @return array<string>
     */
    public function splitMessage(string $text, int $maxLength = 4000): array
    {
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }

        $lines = explode("\n", $text);
        $chunks = [];
        $currentChunk = '';

        foreach ($lines as $line) {
            $lineLength = mb_strlen($line);

            // If a single line itself is longer than maxLength, slice it hard
            if ($lineLength > $maxLength) {
                if ($currentChunk !== '') {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                }
                $offset = 0;
                while ($offset < $lineLength) {
                    $chunks[] = mb_substr($line, $offset, $maxLength);
                    $offset += $maxLength;
                }

                continue;
            }

            $potentialChunk = $currentChunk === '' ? $line : $currentChunk."\n".$line;

            if (mb_strlen($potentialChunk) > $maxLength) {
                $chunks[] = trim($currentChunk);
                $currentChunk = $line;
            } else {
                $currentChunk = $potentialChunk;
            }
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }
}
