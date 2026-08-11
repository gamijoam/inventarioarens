<?php

namespace App\Modules\TelegramBot\Controllers;

use App\Modules\TelegramBot\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Recibe los updates que Telegram empuja via webhook.
 *
 * Seguridad:
 *  - Ruta PUBLICA (Telegram no envia headers custom de auth).
 *  - Valida el header X-Telegram-Bot-Api-Secret-Token contra el secret
 *    configurado (setWebhook lo envia con ese secret_token).
 *  - Si el bot no esta configurado o el secret no coincide, 403.
 *  - El enrutado por lista blanca ocurre dentro de TelegramBotService
 *    (cualquier chat_id no autorizado se ignora silenciosamente).
 */
class TelegramWebhookController extends Controller
{
    public function __construct(private readonly TelegramBotService $bot) {}

    public function handle(Request $request): JsonResponse
    {
        if (! config('services.telegram.bot_token')) {
            return response()->json(['ok' => false], 403);
        }

        $secret = config('services.telegram.webhook_secret');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($secret !== '' && ! hash_equals((string) $secret, $provided)) {
            return response()->json(['ok' => false], 403);
        }

        $this->bot->handleUpdate($request->input() ?: []);

        // Telegram espera 200 lo antes posible; el procesamiento ya termino.
        return response()->json(['ok' => true]);
    }
}
