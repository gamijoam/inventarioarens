<?php
/**
 * Genera el JSON de Postman Environment v2.1.0.
 * Output: C:\Users\gafit\Desktop\INVENTARIOARENS-Postman\INVENTARIOARENS.postman_environment.json
 */

$env = [
    'id' => 'a1b2c3d4-e5f6-7890-1234-567890abcdef',
    'name' => 'INVENTARIOARENS — Production',
    'values' => [
        ['key' => 'base_url', 'value' => 'https://app.miinventariofacil.com/api', 'enabled' => true, 'type' => 'default'],
        ['key' => 'tenant_slug', 'value' => 'demo-caracas', 'enabled' => true, 'type' => 'default'],
        ['key' => 'tenant_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'group_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'spinoff_id', 'value' => '2', 'enabled' => true, 'type' => 'default'],
        ['key' => 'user_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'role_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'branch_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'warehouse_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'customer_group_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'permission', 'value' => 'sales.view', 'enabled' => true, 'type' => 'default'],
        ['key' => 'auth_token', 'value' => '', 'enabled' => true, 'type' => 'secret'],
    ],
    '_postman_variable_scope' => 'environment',
    '_postman_exported_at' => date('Y-m-d\TH:i:s.000\Z'),
    '_postman_exported_using' => 'Postman/10',
];

$json = json_encode($env, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$outPath = 'C:\\Users\\gafit\\Desktop\\INVENTARIOARENS-Postman\\INVENTARIOARENS.postman_environment.json';
file_put_contents($outPath, $json);
echo "Wrote " . strlen($json) . " bytes to $outPath" . PHP_EOL;
