<?php

/**
 * Generate INVENTARIOARENS.postman_environment.json with all needed variables.
 */

$outputDir = $argv[1] ?? (__DIR__ . '/../storage/app/postman');
if (! is_dir($outputDir)) {
    @mkdir($outputDir, 0755, true);
}

$env = [
    'id' => 'a1b2c3d4-e5f6-7890-1234-567890abcdef',
    'name' => 'INVENTARIOARENS — Local + Production',
    'values' => [
        ['key' => 'base_url', 'value' => 'http://127.0.0.1:8000/api', 'enabled' => true, 'type' => 'default'],
        ['key' => 'base_url_prod', 'value' => 'https://app.miinventariofacil.com/api', 'enabled' => true, 'type' => 'default'],
        ['key' => 'tenant_slug', 'value' => 'mi-empresa', 'enabled' => true, 'type' => 'default'],
        ['key' => 'tenant_slug_alt', 'value' => 'demo-empresa', 'enabled' => true, 'type' => 'default'],
        ['key' => 'tenant_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'group_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'spinoff_id', 'value' => '2', 'enabled' => true, 'type' => 'default'],
        ['key' => 'user_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'role_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'branch_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'warehouse_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'customer_group_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'customer_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'supplier_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'product_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'price_list_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'payment_method_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'cash_register_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'cash_register_session_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'sale_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'pos_order_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'purchase_order_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'inventory_transfer_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'accounts_receivable_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'accounts_payable_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'warranty_policy_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'warranty_claim_id', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'permission', 'value' => 'sales.view', 'enabled' => true, 'type' => 'default'],
        ['key' => 'rate_type_code', 'value' => 'BCV', 'enabled' => true, 'type' => 'default'],
        ['key' => 'currency', 'value' => 'USD', 'enabled' => true, 'type' => 'default'],
        ['key' => 'page', 'value' => '1', 'enabled' => true, 'type' => 'default'],
        ['key' => 'limit', 'value' => '25', 'enabled' => true, 'type' => 'default'],
        ['key' => 'node_code', 'value' => 'LOCAL-PC-01', 'enabled' => true, 'type' => 'default'],
        ['key' => 'installation_code', 'value' => 'LOCAL-PC-01', 'enabled' => true, 'type' => 'default'],
        ['key' => 'auth_token', 'value' => '', 'enabled' => true, 'type' => 'secret'],
        ['key' => 'bootstrap_token', 'value' => 'PON_AQUI_TU_APP_BOOTSTRAP_TOKEN', 'enabled' => true, 'type' => 'secret'],
    ],
    '_postman_variable_scope' => 'environment',
    '_postman_exported_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
    '_postman_exported_using' => 'INVENTARIOARENS/scripts/generate-postman-env.php',
];

$path = rtrim($outputDir, '/\\') . '/INVENTARIOARENS.postman_environment.json';
file_put_contents($path, json_encode($env, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo 'Wrote: ' . $path . PHP_EOL;
echo 'Variables: ' . count($env['values']) . PHP_EOL;