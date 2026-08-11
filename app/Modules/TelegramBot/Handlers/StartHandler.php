<?php

namespace App\Modules\TelegramBot\Handlers;

use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\TelegramBot\Services\TelegramApiService;

/**
 * /start: confirma la vinculacion y muestra ayuda breve.
 */
class StartHandler implements Handler
{
    public function __construct(private readonly TelegramApiService $api) {}

    public function handle(string $chatId, TelegramBotUser $entry, string $arg = ''): void
    {
        $name = $entry->name ?: 'Usuario';
        $this->api->sendMessage(
            $chatId,
            "Hola <b>{$name}</b> 👋\n\n"
            .'Tu Telegram ID está vinculado y puedes consultar tus empresas desde el bot.'
            ."\n\nUsa /ayuda para ver los comandos disponibles."
        );
    }
}
