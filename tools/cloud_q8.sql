select tu.user_id, u.email, tu.tenant_id, t.slug, tu.status
from tenant_user tu
join users u on u.id = tu.user_id
join tenants t on t.id = tu.tenant_id
order by u.email, t.slug;
