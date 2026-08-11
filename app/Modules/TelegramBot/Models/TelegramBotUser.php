<?php

namespace App\Modules\TelegramBot\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Usuario autorizado del bot de Telegram (lista blanca).
 *
 * Vincula un telegram_chat_id a un user_id de la app dentro de un tenant.
 * La visibilidad se deriva del rol del usuario vinculado:
 *  - Owner (is_group=true)  => puede ver el grupo + todas sus hijas.
 *  - Admin / miembro        => solo su empresa.
 *  - Platform admin         => todas las empresas.
 */
class TelegramBotUser extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'telegram_chat_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
