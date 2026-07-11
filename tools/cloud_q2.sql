select tenant_id, count(*) as n, array_agg(distinct status) as statuses from inventory_transfers group by tenant_id order by tenant_id;
