select t.id, t.slug, t.name, t.domain,
       (select count(*) from inventory_transfers where tenant_id = t.id) as transfers,
       (select count(*) from warehouses where tenant_id = t.id) as warehouses,
       (select count(*) from products where tenant_id = t.id) as products
from tenants t order by t.id;
