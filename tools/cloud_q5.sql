select u.id, u.email, u.name from users u order by u.id;
select ut.user_id, ut.tenant_id, t.slug, ut.status from user_tenants ut join tenants t on t.id = ut.tenant_id order by ut.user_id, ut.tenant_id;
