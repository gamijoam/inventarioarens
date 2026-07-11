select id, user_id, tenant_id, name, last_used_at, expires_at, revoked_at, created_at
from auth_tokens
where user_id = 6
order by id desc
limit 20;
