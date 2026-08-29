<?php

namespace App\Modules\Fiscal\Models;

use App\Support\Sync\Syncable;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'rate', 'category', 'is_active'])]
class FiscalTaxRate extends Model
{
    use BelongsToTenant, Syncable;

    public const CATEGORY_TAXABLE = 'taxable';

    public const CATEGORY_EXEMPT = 'exempt';

    public const CATEGORY_EXONERATED = 'exonerated';

    public const CATEGORY_NON_TAXABLE = 'non_taxable';

    public const CATEGORIES = [
        self::CATEGORY_TAXABLE,
        self::CATEGORY_EXEMPT,
        self::CATEGORY_EXONERATED,
        self::CATEGORY_NON_TAXABLE,
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    protected function syncOutboxMethod(string $action): ?string
    {
        return match ($action) {
            'created' => 'fiscalTaxRateCreated',
            'updated' => 'fiscalTaxRateUpdated',
            default => null,
        };
    }
}
