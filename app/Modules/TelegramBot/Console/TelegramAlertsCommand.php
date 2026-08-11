<?php

namespace App\Modules\TelegramBot\Console;

use App\Modules\Inventory\Services\AlertHistoryService;
use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\TelegramBot\Services\TelegramApiService;
use App\Modules\TelegramBot\Services\TelegramBotService;
use App\Modules\TelegramBot\Services\TelegramReportService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Envia alertas programadas del bot de Telegram:
 *  - resumen: resumen diario a los chats con acceso al tenant.
 *  - stock:   alerta de productos sin stock / stock bajo.
 *
 * Se configura por empresa en tenant_settings.telegram.
 * Uso: php artisan telegram:alerts [--type=resumen|stock|all]
 */
class TelegramAlertsCommand extends Command
{
    protected $signature = 'telegram:alerts
        {--type=all : resumen|stock|all}';

    protected $description = 'Envia alertas programadas del bot de Telegram (resumen diario y stock bajo).';

    public function handle(
        TelegramApiService $api,
        TelegramBotService $bot,
        TelegramReportService $reports,
        AlertHistoryService $alerts,
    ): int {
        if (! $api->isConfigured()) {
            $this->warn('TELEGRAM_BOT_TOKEN no configurado. Nada que enviar.');

            return self::SUCCESS;
        }

        $type = $this->option('type');
        $sent = 0;

        $tenants = Tenant::query()
            ->where('is_group', true)
            ->get()
            ->merge(Tenant::query()->where('is_group', false)->get());

        foreach ($tenants as $tenant) {
            $setting = TenantSetting::where('tenant_id', $tenant->id)->first();
            $telegram = $setting?->get('telegram', []) ?: [];
            if (empty($telegram['enabled'])) {
                continue;
            }

            // Solo los chats con acceso a ESTE tenant reciben su resumen.
            $entries = TelegramBotUser::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->get();

            $sendResumen = ($type === 'resumen')
                && ($telegram['report_time'] ?? '21:00') === now()->format('H:i');

            $sendStock = ($type === 'stock' || $type === 'all') && ! empty($telegram['low_stock_alerts']);

            if ($sendResumen) {
                $sent += $this->sendToEntries($entries, fn (string $chatId) => $api->sendMessage($chatId, $reports->summaryText($tenant)));
            }

            if ($sendStock) {
                $threshold = (float) ($telegram['low_stock_threshold'] ?? 3);
                $alerts->snapshotAlerts($tenant->id, $threshold);
                $sent += $this->sendStockAlert($api, $bot, $reports, $tenant, $threshold);
            }
        }

        $this->info("Alertas enviadas: {$sent}");

        return self::SUCCESS;
    }

    private function sendToEntries(Collection $entries, callable $sender): int
    {
        $sent = 0;
        foreach ($entries as $entry) {
            if ($sender((string) $entry->telegram_chat_id)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function sendStockAlert(TelegramApiService $api, TelegramBotService $bot, TelegramReportService $reports, Tenant $tenant, float $threshold): int
    {
        $entries = TelegramBotUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get();

        $text = "🚨 <b>{$tenant->name}</b>\nResumen de stock bajo (umbral {$threshold}):\n\n";
        $text .= $reports->summaryText($tenant);

        return $this->sendToEntries($entries, fn (string $chatId) => $api->sendMessage($chatId, $text));
    }
}
