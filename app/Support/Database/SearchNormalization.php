<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

class SearchNormalization
{
    /**
     * Normaliza una columna o expresión SQL para búsqueda insensible a acentos pero preservando la letra Ñ.
     * En SQLite usa LOWER(col).
     * En PostgreSQL usa unaccent() protegiendo ñ y Ñ para no convertirlas en n/N.
     */
    public static function expression(string $column): string
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        if (! $isPgsql) {
            return "LOWER({$column})";
        }

        return "translate(unaccent(replace(replace(LOWER({$column}), 'ñ', chr(1)), 'Ñ', chr(1))), chr(1), 'ñ')";
    }

    /**
     * Expresión normalizada para el parámetro de búsqueda '?' en consultas LIKE o =.
     */
    public static function term(): string
    {
        return self::expression('?');
    }
}
