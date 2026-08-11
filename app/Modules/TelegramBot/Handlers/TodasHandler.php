<?php

namespace App\Modules\TelegramBot\Handlers;

use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\TelegramBot\Services\TelegramApiService;
use App\Modules\TelegramBot\Services\TelegramBotService;
use App\Modules\TelegramBot\Services\TelegramReportService;

/**
 * /todas — resumen consolidado de todas las empresas a las que el chat tiene
 * acceso (Owner de grupo: todas sus hijas; Platform admin: todas).
 */
class TodasHandler implements Handler
{
    public function __construct(
        private readonly TelegramApiService $api,
        private readonly TelegramBotService $bot,
        private readonly TelegramReportService $reports,
    ) {}

    public function handle(string $chatId, TelegramBotUser $entry, string $arg = ''): void
    {
        $accessible = $this->bot->accessibleTenants($entry);

        if ($accessible->count() <= 1) {
            $this->api->sendMessage($chatId, 'Solo tienes acceso a una empresa. Usa /resumen.');

            return;
        }

        $blocks = $accessible->map(fn ($tenant) => $this->reports->summaryText($tenant));
        $text = "📈 <b>Resumen de todas mis empresas</b>\n\n".$blocks->implode("\n\n──────────\n\n");

        $this->api->sendMessage($chatId, $text);
    }
}
