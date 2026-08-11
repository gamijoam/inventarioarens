<?php

namespace App\Modules\TelegramBot\Console;

use App\Models\User;
use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Vincula un telegram_chat_id a un usuario de una empresa (lista blanca).
 *
 * Uso:
 *   php artisan telegram:link <tenant-slug> <email> <telegram-chat-id> [--name="Nombre"]
 */
class TelegramLinkCommand extends Command
{
    protected $signature = 'telegram:link
        {tenantSlug : Slug de la empresa (o grupo) que da acceso}
        {email : Email del usuario a vincular}
        {chatId : Telegram chat id (lo muestra el bot en /start)}
        {--name= : Nombre visible (por defecto el nombre del usuario)}';

    protected $description = 'Vincula un telegram_chat_id a un usuario de una empresa para el bot de administracion.';

    public function handle(): int
    {
        $tenant = Tenant::withoutGlobalScopes()
            ->where('slug', $this->argument('tenantSlug'))
            ->first();

        if (! $tenant) {
            $this->error("No se encontro la empresa '{$this->argument('tenantSlug')}'.");

            return self::FAILURE;
        }

        $user = User::query()
            ->where('email', $this->argument('email'))
            ->first();

        if (! $user) {
            $this->error("No se encontro el usuario '{$this->argument('email')}'.");

            return self::FAILURE;
        }

        $isMember = $user->tenants()->whereKey($tenant->id)->wherePivot('status', 'active')->exists();
        if (! $isMember) {
            $this->warn("El usuario no es miembro activo de '{$tenant->name}', pero se vinculara igual.");
        }

        $chatId = trim((string) $this->argument('chatId'));

        TelegramBotUser::updateOrCreate(
            ['telegram_chat_id' => $chatId],
            [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'name' => $this->option('name') ?: $user->name,
                'is_active' => true,
            ],
        );

        $this->info("Vinculado chat {$chatId} a {$user->email} en {$tenant->name}.");

        return self::SUCCESS;
    }
}
