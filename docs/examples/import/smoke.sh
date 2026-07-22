#!/usr/bin/env bash
# Smoke test end-to-end del modulo Data Import.
# Uso: bash docs/examples/import/smoke.sh

set -e
BASE="${BASE:-http://127.0.0.1:8765}"
TENANT="${TENANT:-mi-empresa}"
EMAIL="${EMAIL:-gabo@gabo.com}"
PASS="${PASS:-gabo1234}"

echo ">>> 1. Login ($EMAIL en $TENANT)"
LOGIN=$(curl -s -X POST "$BASE/api/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Tenant: $TENANT" \
  -H "Accept: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}")
TOKEN=$(echo "$LOGIN" | grep -oP '"token":"\K[^"]+' | head -1)
[ -z "$TOKEN" ] && { echo "Login fallo"; exit 1; }
echo "    token=${TOKEN:0:20}..."

echo ">>> 2. Descargar plantilla de sucursales"
curl -s -o /tmp/_plantilla.csv -w "    HTTP %{http_code}\n" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: $TENANT" \
  "$BASE/api/import/templates/branches"
head -3 /tmp/_plantilla.csv | sed 's/^/    /'

echo ">>> 3. Crear sesion"
SESSION=$(curl -s -X POST "$BASE/api/import/sessions" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: $TENANT" -H "Accept: application/json" \
  -d '{"meta":{"source":"smoke"}}')
SID=$(echo "$SESSION" | grep -oP '"id":\K[0-9]+' | head -1)
echo "    session_id=$SID"

echo ">>> 4. Subir CSV de sucursales (3 filas: 1 dup + 2 nuevas)"
cat > /tmp/_smoke-branches.csv <<EOF
code,name,status
PRINCIPAL,Esta Ya Existe Del Seeder Demo,active
SMOKE-NORTE,Sucursal Norte Smoke,active
SMOKE-SUR,Sucursal Sur Smoke,inactive
EOF
curl -s -X POST "$BASE/api/import/sessions/$SID/entities/branches/upload" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: $TENANT" \
  -F "file=@/tmp/_smoke-branches.csv" -o /dev/null -w "    upload HTTP %{http_code}\n"

echo ">>> 5. Ejecutar import"
RESULT=$(curl -s -X POST "$BASE/api/import/sessions/$SID/entities/branches/run" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: $TENANT" -H "Accept: application/json")
SUMMARY=$(echo "$RESULT" | grep -oP '"summary":\{[^}]+\}')
echo "    $SUMMARY"

echo ">>> 6. Reporte CSV descargable"
curl -s "$BASE/api/import/sessions/$SID/report" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: $TENANT" \
  -H "Accept: text/csv" | head -5 | sed 's/^/    /'

echo ">>> 7. Productos con inventario inicial"
cat > /tmp/_smoke-products.csv <<EOF
sku,name,base_price,sale_currency,unit_of_measure,stock_inicial,almacen_codigo,costo_unitario
SMOKE-PROD-001,Camisa Smoke,25.00,USD,unit,10,SMOKE-NORTE,12.00
SMOKE-PROD-002,Pantalon Smoke,45.00,USD,unit,5,SMOKE-NORTE,20.00
EOF
SESSION=$(curl -s -X POST "$BASE/api/import/sessions" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: $TENANT" -H "Accept: application/json" -d '{}')
SID=$(echo "$SESSION" | grep -oP '"id":\K[0-9]+' | head -1)
curl -s -X POST "$BASE/api/import/sessions/$SID/entities/products/upload" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: $TENANT" \
  -F "file=@/tmp/_smoke-products.csv" -o /dev/null -w "    upload HTTP %{http_code}\n"
RESULT=$(curl -s -X POST "$BASE/api/import/sessions/$SID/entities/products/run" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: $TENANT" -H "Accept: application/json")
echo "    $(echo "$RESULT" | grep -oP '"summary":\{[^}]+\}')"

echo ""
echo ">>> 8. Listar historial de sesiones del tenant"
curl -s "$BASE/api/import/sessions" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: $TENANT" \
  -H "Accept: application/json" | grep -oP '"id":\K[0-9]+|"status":"\K[^"]+|"total_rows":\K[0-9]+' | paste -d' ' - - - | sed 's/^/    sesion #/'

echo ""
echo "Smoke test OK. Probas todo en:"
echo "  - Reportes CSV: GET /api/import/sessions/{id}/report"
echo "  - Plantillas:   GET /api/import/templates/{entity}"
echo "  - Frontend:     http://app.miinventariofacil.com/import"
