<?php

namespace App\Modules\TelegramBot\Handlers;

use App\Modules\TelegramBot\Models\TelegramBotUser;

interface Handler
{
    public function handle(string $chatId, TelegramBotUser $entry, string $arg = ''): void;
}
