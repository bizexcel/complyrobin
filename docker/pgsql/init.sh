#!/bin/bash
# Runs once when the local Postgres volume is first created.
# Creates the test database and applies the role/grant script to both databases.
set -euo pipefail

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE complyrobin_test"

for db in complyrobin complyrobin_test; do
    psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$db" \
        -v migrator_password=migrator -v tenant_password=tenant -v platform_password=platform \
        -f /sql/roles.sql
done
