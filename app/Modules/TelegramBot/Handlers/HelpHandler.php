<?php

namespace App\Modules\TelegramBot\Handlers;

use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\TelegramBot\Services\TelegramApiService;
use App\Modules\TelegramBot\Services\TelegramBotService;

/**
 * /ayuda: lista de comandos.
 */
class HelpHandler implements Handler
{
    public function __construct(
        private readonly TelegramApiService $api,
        private readonly TelegramBotService $bot,
    ) {}

    public function handle(string $chatId, TelegramBotUser $entry, string $arg = ''): void
    {
        $accessible = $this->bot->accessibleTenants($entry);
        $isBoss = $accessible->count() > 1;

        $text = '<b>Comandos disponibles</b>'
            ."\n\n/resumen — Resumen de la empresa actual"
            .($isBoss
                ? "\n/todas — Resumen consolidado de todas mis empresas"
                ."\n/resumen empresa:&lt;nombre&gt; — Resumen de una empresa especifica"
                : '');

        $this->api->sendMessage($chatId, $text);
    }
}
