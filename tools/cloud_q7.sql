select tablename from pg_tables where schemaname='public' and (tablename like '%tenant%' or tablename like '%user%');
