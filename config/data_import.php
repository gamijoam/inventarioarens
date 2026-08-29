<?php

return [
    'max_file_mb' => max(1, (int) env('DATA_IMPORT_MAX_FILE_MB', 50)),
    'max_rows' => max(1, (int) env('DATA_IMPORT_MAX_ROWS', 100_000)),
    'queue' => env('DATA_IMPORT_QUEUE', 'imports'),
];
