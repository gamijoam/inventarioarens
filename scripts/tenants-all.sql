SELECT id, name, schema_name, domain, is_active, is_demo, organization_id, business_type, license_type
FROM public.tenants
ORDER BY id;
