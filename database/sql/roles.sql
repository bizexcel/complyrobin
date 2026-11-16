-- Database roles for ComplyRobin. Idempotent: safe to run on every deploy.
-- Run as a superuser (or a role allowed to create roles). The passwords come from the environment variables
-- DB_MIGRATOR_PASSWORD, DB_TENANT_PASSWORD and DB_PLATFORM_PASSWORD (as `php artisan db:roles` runs it on every
-- deploy), or from psql variables:
--   psql -v migrator_password=… -v tenant_password=… -v platform_password=… -d complyrobin -f database/sql/roles.sql
\getenv migrator_password DB_MIGRATOR_PASSWORD
\getenv tenant_password DB_TENANT_PASSWORD
\getenv platform_password DB_PLATFORM_PASSWORD
\if :{?migrator_password}
\else
    \warn 'roles.sql: set DB_MIGRATOR_PASSWORD (or -v migrator_password=…)'
    \quit
\endif
\if :{?tenant_password}
\else
    \warn 'roles.sql: set DB_TENANT_PASSWORD (or -v tenant_password=…)'
    \quit
\endif
\if :{?platform_password}
\else
    \warn 'roles.sql: set DB_PLATFORM_PASSWORD (or -v platform_password=…)'
    \quit
\endif
--
--   app_migrator  owns the schema; runs migrations and seeders (bypasses RLS)
--   app_tenant    the application; every tenant table is filtered by row-level security
--   app_platform  platform admin and cross-tenant system jobs (bypasses RLS)

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_migrator') THEN
        CREATE ROLE app_migrator LOGIN BYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_tenant') THEN
        CREATE ROLE app_tenant LOGIN NOBYPASSRLS;
    END IF;
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_platform') THEN
        CREATE ROLE app_platform LOGIN BYPASSRLS;
    END IF;
END
$$;

ALTER ROLE app_migrator WITH LOGIN BYPASSRLS PASSWORD :'migrator_password';
ALTER ROLE app_tenant WITH LOGIN NOBYPASSRLS PASSWORD :'tenant_password';
ALTER ROLE app_platform WITH LOGIN BYPASSRLS PASSWORD :'platform_password';

-- The search path is a role setting, never a session SET: a connection pooler would share a session's settings.
ALTER ROLE app_migrator SET search_path = public;
ALTER ROLE app_tenant SET search_path = public;
ALTER ROLE app_platform SET search_path = public;

SELECT format('GRANT CONNECT ON DATABASE %I TO app_migrator, app_tenant, app_platform', current_database()) \gexec

GRANT USAGE, CREATE ON SCHEMA public TO app_migrator;
GRANT USAGE ON SCHEMA public TO app_tenant, app_platform;

-- Tables and sequences created later by app_migrator are usable by the app roles.
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_tenant, app_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO app_tenant, app_platform;

-- No blanket GRANT … ON ALL TABLES here: this file runs on every deploy (`php artisan db:roles`), and that would undo
-- what migrations deliberately revoke (login_attempts is unreadable to the app; the audit trail and the rotation log
-- are append-only). The default privileges above cover every table the migrator creates; run this before migrating.
