<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuracion por empresa (tenant). Una fila por tenant con un JSON de
 * secciones (telegram, notificaciones, etc).
 *
 * Acceso tipado por secciones:
 *   $settings->get('telegram.enabled', true)
 *   $settings->set('telegram.report_time', '21:00')
 *
 * La fila se crea automaticamente al registrar la empresa (hook en Tenant).
 */
class TenantSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function set(string $key, mixed $value): self
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
        $this->save();

        return $this;
    }
}
