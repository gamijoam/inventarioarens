<?php

namespace Tests\Feature\TelegramBot;

use App\Models\User;
use App\Modules\TelegramBot\Models\TelegramBotUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_when_bot_not_configured(): void
    {
        config(['services.telegram.bot_token' => '']);

        $this
            ->postJson('/telegram/webhook', ['message' => ['chat' => ['id' => '1'], 'text' => '/start']])
            ->assertStatus(403);
    }

    public function test_webhook_rejects_wrong_secret_token(): void
    {
        config(['services.telegram.bot_token' => '123:TOKEN']);
        config(['services.telegram.webhook_secret' => 'correct-secret']);

        $this
            ->postJson('/telegram/webhook', ['message' => ['chat' => ['id' => '1'], 'text' => '/start']], [
                'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
            ])
            ->assertStatus(403);
    }

    public function test_webhook_accepts_valid_secret_and_responds_with_chat_id_on_start(): void
    {
        config(['services.telegram.bot_token' => '123:TOKEN']);
        config(['services.telegram.webhook_secret' => 'correct-secret']);

        Http::fake(['https://api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true])]);

        // Chat no en lista blanca: /start SI responde con su chat_id.
        $this
            ->postJson('/telegram/webhook', ['message' => ['chat' => ['id' => '999999'], 'text' => '/start']], [
                'X-Telegram-Bot-Api-Secret-Token' => 'correct-secret',
            ])
            ->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bot123:TOKEN/sendMessage');
    }

    public function test_webhook_ignores_non_start_command_for_unlisted_chat(): void
    {
        config(['services.telegram.bot_token' => '123:TOKEN']);
        config(['services.telegram.webhook_secret' => 'correct-secret']);

        Http::fake();

        // Chat no en lista blanca: un comando que no sea /start se ignora en
        // silencio (no se envia nada a Telegram).
        $this
            ->postJson('/telegram/webhook', ['message' => ['chat' => ['id' => '999999'], 'text' => '/resumen']], [
                'X-Telegram-Bot-Api-Secret-Token' => 'correct-secret',
            ])
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_webhook_responds_to_start_for_whitelisted_chat(): void
    {
        config(['services.telegram.bot_token' => '123:TOKEN']);
        config(['services.telegram.webhook_secret' => 'correct-secret']);

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a']);
        TelegramBotUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => User::factory()->create()->id,
            'telegram_chat_id' => '777888999',
            'name' => 'Juan',
            'is_active' => true,
        ]);

        Http::fake(['https://api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true])]);

        $this
            ->postJson('/telegram/webhook', ['message' => ['chat' => ['id' => '777888999'], 'text' => '/start']], [
                'X-Telegram-Bot-Api-Secret-Token' => 'correct-secret',
            ])
            ->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bot123:TOKEN/sendMessage');
    }
}
