<?php

namespace App\Modules\TelegramBot\Handlers;

use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\TelegramBot\Services\TelegramApiService;
use App\Modules\TelegramBot\Services\TelegramBotService;
use App\Modules\TelegramBot\Services\TelegramReportService;

/**
 * /resumen — resumen de la empresa actual (o empresa:&lt;nombre&gt;).
 * /todas — resumen consolidado de todas las empresas del chat.
 */
class ResumenHandler implements Handler
{
    public function __construct(
        private readonly TelegramApiService $api,
        private readonly TelegramBotService $bot,
        private readonly TelegramReportService $reports,
    ) {}

    public function handle(string $chatId, TelegramBotUser $entry, string $arg = ''): void
    {
        $accessible = $this->bot->accessibleTenants($entry);

        $named = $this->extractCompany($arg);
        if ($named !== null) {
            $target = $accessible->first(fn ($tenant) => mb_strtolower($tenant->name) === mb_strtolower($named));
            if (! $target) {
                $this->api->sendMessage($chatId, "No tienes acceso a la empresa \"<b>{$named}</b>\".");

                return;
            }
            $this->api->sendMessage($chatId, $this->reports->summaryText($target));

            return;
        }

        $primary = $entry->tenant;
        $this->api->sendMessage($chatId, $this->reports->summaryText($primary));
    }

    private function extractCompany(string $arg): ?string
    {
        if (preg_match('/empresa:\s*(.+)/iu', $arg, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
