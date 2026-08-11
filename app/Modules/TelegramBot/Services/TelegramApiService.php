<?php

namespace App\Modules\TelegramBot\Services;

use Illuminate\Support\Facades\Http;

/**
 * Wrapper minimo de la Telegram Bot API (sendMessage, setWebhook).
 * No depende de librerias externas: usa Http de Laravel.
 */
class TelegramApiService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function botToken(): string
    {
        return (string) config('services.telegram.bot_token');
    }

    public function isConfigured(): bool
    {
        return $this->botToken() !== '';
    }

    private function api(string $method): string
    {
        return self::API_BASE.$this->botToken().'/'.$method;
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $response = Http::timeout(15)
            ->post($this->api('sendMessage'), array_merge([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], $extra));

        return $response->successful();
    }

    public function setWebhook(string $url): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $secret = config('services.telegram.webhook_secret');

        $response = Http::timeout(15)->post($this->api('setWebhook'), array_filter([
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
        ]));

        return $response->successful() && ($response->json('ok') ?? false);
    }
}
