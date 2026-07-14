#!/bin/bash
cd /opt/inventarioarens-cloud
echo "=== Grupo creado ==="
sudo -u postgres psql -d inventory_arens -c "SELECT name, slug, parent_id, plan, status FROM tenants WHERE slug = 'test-holding-wpf';"

echo ""
echo "=== Group owner creado ==="
sudo -u postgres psql -d inventory_arens -c "SELECT id, name, email, is_platform_admin FROM users WHERE email = 'jefe.test@holding.test';"

echo ""
echo "=== Pivot tenant_user ==="
sudo -u postgres psql -d inventory_arens -c "SELECT t.slug AS tenant, u.email, tu.status FROM tenant_user tu JOIN tenants t ON tu.tenant_id=t.id JOIN users u ON tu.user_id=u.id WHERE t.slug = 'test-holding-wpf';"

echo ""
echo "=== Branch + Warehouse + ExchangeRate ==="
sudo -u postgres psql -d inventory_arens -c "SELECT 'branch' AS kind, code, status FROM branches WHERE tenant_id = (SELECT id FROM tenants WHERE slug = 'test-holding-wpf') UNION ALL SELECT 'warehouse', code, status FROM warehouses WHERE tenant_id = (SELECT id FROM tenants WHERE slug = 'test-holding-wpf') UNION ALL SELECT 'rate_type', code, is_default::text FROM exchange_rate_types WHERE tenant_id = (SELECT id FROM tenants WHERE slug = 'test-holding-wpf');"

echo ""
echo "=== Rol Administrador del group owner ==="
sudo -u postgres psql -d inventory_arens -c "SELECT r.name, COUNT(rp.permission_id) AS perms_count FROM model_has_roles mhr JOIN roles r ON mhr.role_id = r.id LEFT JOIN role_has_permissions rp ON r.id = rp.role_id WHERE r.name = 'Owner' AND r.tenant_id = (SELECT id FROM tenants WHERE slug = 'test-holding-wpf') GROUP BY r.name;"

echo ""
echo "=== Audit log ==="
sudo -u postgres psql -d inventory_arens -c "SELECT action, LEFT(metadata::text, 80) FROM audit_logs WHERE action = 'tenant_group.created' ORDER BY id DESC LIMIT 1;"

echo ""
echo "=== Login del group owner (deberia ser OK) ==="
curl -sS -X POST https://app.miinventariofacil.com/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Tenant: test-holding-wpf" \
  -d '{"email":"jefe.test@holding.test","password":"Secret123","device_name":"smoke"}' \
  | head -c 400
echo ""