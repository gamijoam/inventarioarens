<?php

namespace App\Modules\DataImport\Support;

final class CsvReportWriter
{
    public const HEADER = [
        'fila',
        'entidad',
        'estado',
        'clave_natural',
        'id_resultado',
        'errores',
    ];

    /**
     * Genera el reporte CSV como string. Usado por HTTP download.
     *
     * @param  iterable<array{row_number: int, entity: string, status: string, natural_key?: ?string, resulting_id?: ?int, errors?: array}>  $rows
     */
    public function write(iterable $rows): string
    {
        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            return '';
        }

        fputcsv($buffer, self::HEADER, ',', '"', '');

        foreach ($rows as $row) {
            $errors = $row['errors'] ?? [];
            if (is_array($errors)) {
                $errorsFlat = [];
                foreach ($errors as $field => $messages) {
                    if (is_array($messages)) {
                        foreach ($messages as $m) {
                            $errorsFlat[] = "{$field}: {$m}";
                        }
                    } else {
                        $errorsFlat[] = "{$field}: {$messages}";
                    }
                }
                $errorsText = implode(' | ', $errorsFlat);
            } else {
                $errorsText = (string) $errors;
            }

            $naturalKey = $row['natural_key'] ?? null;
            $resultingId = $row['resulting_id'] ?? null;
            $message = $row['message'] ?? null;
            if ($naturalKey === null && $message !== null && $row['status'] !== ImportRowResult::STATUS_OK) {
                $naturalKey = $message;
            }

            fputcsv($buffer, [
                $row['row_number'],
                $row['entity'],
                $row['status'],
                $naturalKey,
                $resultingId,
                $errorsText,
            ], ',', '"', '');
        }

        rewind($buffer);
        $content = stream_get_contents($buffer);
        fclose($buffer);

        return $content === false ? '' : $content;
    }
}
