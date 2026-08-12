<?php

namespace App\Modules\TelegramBot\Services;

use App\Modules\TelegramBot\Handlers\HelpHandler;
use App\Modules\TelegramBot\Handlers\ResumenHandler;
use App\Modules\TelegramBot\Handlers\StartHandler;
use App\Modules\TelegramBot\Handlers\TodasHandler;
use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Nucleo del bot de Telegram: resuelve quien es el remitente (lista blanca),
 * a que empresas puede acceder (segun rol del user vinculado) y enruta los
 * comandos a los handlers.
 */
class TelegramBotService
{
    public function __construct(private readonly TelegramApiService $api) {}

    /**
     * Resuelve el usuario de la lista blanca por chat_id. Null si no esta
     * autorizado o inactivo (el bot ignora silenciosamente).
     */
    public function resolveFromChatId(string $chatId): ?TelegramBotUser
    {
        return TelegramBotUser::query()
            ->where('telegram_chat_id', $chatId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Tenants a los que el chat tiene acceso, segun el rol del user vinculado:
     *  - user es Platform Admin  => todos los tenants.
     *  - user es Owner del grupo => el grupo + sus spinoffs.
     *  - user es Admin/miembro   => solo su tenant.
     *  - sin user vinculado      => solo el tenant de la fila de la whitelist.
     */
    public function accessibleTenants(TelegramBotUser $entry): Collection
    {
        $user = $entry->user;

        if ($user && $user->isPlatformAdmin()) {
            return Tenant::query()->orderBy('id')->get();
        }

        $tenant = $entry->tenant;

        if ($user && $tenant->isGroup() && $user->isOwnerOf($tenant)) {
            return Tenant::query()
                ->where(fn ($q) => $q->where('id', $tenant->id)->orWhere('parent_id', $tenant->id))
                ->orderBy('id')
                ->get();
        }

        return collect([$tenant]);
    }

    /**
     * Enruta un update de Telegram (message) al handler correspondiente.
     */
    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));
        $chatId = (string) ($message['chat']['id'] ?? '');

        if ($text === '' || $chatId === '') {
            return;
        }

        $entry = $this->resolveFromChatId($chatId);
        if (! $entry) {
            // Fuera de lista blanca: solo respondemos a /start para que la
            // persona conozca su chat_id y pueda pasarselo al administrador
            // para activar el acceso. Cualquier otro comando se ignora en
            // silencio (el chat_id NO es secreto: es el propio id del chat).
            $firstLine = strtok($text, "\n") ?: '';
            $parts = preg_split('/\s+/', trim($firstLine)) ?: [];
            $command = strtolower((string) ($parts[0] ?? ''));

            if ($command === '/start') {
                $this->api->sendMessage(
                    $chatId,
                    "Tu Telegram ID es: <code>{$chatId}</code>\n\n"
                    .'Envíaselo al administrador para activar tu acceso al bot.'
                );
            }

            Log::info('telegram.unlisted_chat', ['chat_id' => $chatId, 'text' => $text]);

            return;
        }

        $this->dispatchCommand($chatId, $text, $entry);
    }

    private function dispatchCommand(string $chatId, string $text, TelegramBotUser $entry): void
    {
        $firstLine = strtok($text, "\n") ?: '';
        $parts = preg_split('/\s+/', trim($firstLine)) ?: [];
        $command = strtolower((string) ($parts[0] ?? ''));
        $arg = implode(' ', array_slice($parts, 1));

        $handler = match ($command) {
            '/start' => new StartHandler($this->api),
            '/ayuda', '/help' => new HelpHandler($this->api, $this),
            '/resumen' => new ResumenHandler($this->api, $this, app(TelegramReportService::class)),
            '/todas' => new TodasHandler($this->api, $this, app(TelegramReportService::class)),
            default => null,
        };

        if ($handler) {
            $handler->handle($chatId, $entry, $arg);
        }
    }
}
