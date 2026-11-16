-- Database roles for ComplyRobin. Idempotent: safe to run on every deploy.
-- Run as the database owner: a superuser (local, CI) or a managed database's owner, which must be allowed to create
-- roles and to bypass row-level security itself (it can't hand out what it lacks). The passwords come from the environment variables
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
-- An empty password would remove the login's password and lock the app out.
SELECT :'migrator_password' = '' OR :'tenant_password' = '' OR :'platform_password' = '' AS a_password_is_empty \gset
\if :a_password_is_empty
    \warn 'roles.sql: set DB_MIGRATOR_PASSWORD, DB_TENANT_PASSWORD and DB_PLATFORM_PASSWORD: none may be empty'
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

-- Tables and sequences created later by app_migrator are usable by the app roles. Setting another role's default
-- privileges needs that role's privileges: a superuser has them (local, CI), but a managed database's owner
-- (Laravel Cloud) is not a superuser, so it joins app_migrator first. It may, having created the role.
DO $$
BEGIN
    IF NOT pg_has_role(current_user, 'app_migrator', 'USAGE') THEN
        EXECUTE format('GRANT app_migrator TO %I WITH INHERIT TRUE, SET TRUE', current_user);
    END IF;
END
$$;
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_tenant, app_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO app_tenant, app_platform;

-- No blanket GRANT … ON ALL TABLES here: this file runs on every deploy (`php artisan db:roles`), and that would undo
-- what migrations deliberately revoke (login_attempts is unreadable to the app; the audit trail and the rotation log
-- are append-only). The default privileges above cover every table the migrator creates; run this before migrating.
