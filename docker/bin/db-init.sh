#!/usr/bin/env bash
#
# Creates the four databases, applies the schema, loads the reference data and sets the indexer start level.
# Idempotent: the schema uses CREATE TABLE IF NOT EXISTS, and the reference data is loaded only once
# (the marker is a non-empty fixer_transactions_sync, which is what seeds the indexer start point).

set -euo pipefail

APP_DIR=/opt/cryptonoises
cd "$APP_DIR"

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME_MAIN="${DB_NAME_MAIN:-cryptonoises}"
DB_NAME_SHARED="${DB_NAME_SHARED:-cryptonoises_shared}"
DB_NAME_TEZOS="${DB_NAME_TEZOS:-cryptonoises_tezos}"
DB_NAME_TEZOS_LOG="${DB_NAME_TEZOS_LOG:-cryptonoises_tezos_log}"

# --ssl-verify-server-cert=0: MySQL 8.4 generates a self-signed TLS certificate on first start and the client
# verifies it by default. The connection is still encrypted, and it never leaves the compose network.
conn_opts=(--protocol=TCP --ssl-verify-server-cert=0 -h "$DB_HOST" -P "$DB_PORT")

mysql_cmd=(mariadb "${conn_opts[@]}" -u "$DB_USER" -p"$DB_PASSWORD")
root_cmd=(mariadb  "${conn_opts[@]}" -u root       -p"${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD is required}")

wait-for-db

#-----------------------------------------------------------------------------------------------------------------------
# Databases and grants, as root: the code runs cross-database queries, so one user needs all four databases.

echo "==> creating databases and granting access to [$DB_USER]"

for db in "$DB_NAME_MAIN" "$DB_NAME_SHARED" "$DB_NAME_TEZOS" "$DB_NAME_TEZOS_LOG"; do
    "${root_cmd[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    "${root_cmd[@]}" -e "GRANT ALL PRIVILEGES ON \`$db\`.* TO '$DB_USER'@'%'"
done

# tezos.sql defines two helper functions (ADDR2ADID / ADID2ADDR), which needs CREATE ROUTINE and,
# without binary logging, no SUPER. The app user creates them below, so grant it here.
"${root_cmd[@]}" -e "FLUSH PRIVILEGES"

#-----------------------------------------------------------------------------------------------------------------------
echo "==> applying schema"

# main.sql holds the tables of both the `main` and the `log` connection: they are the same database.
"${mysql_cmd[@]}" "$DB_NAME_MAIN"      < sql/schema/main.sql
"${mysql_cmd[@]}" "$DB_NAME_SHARED"    < sql/schema/shared.sql
"${mysql_cmd[@]}" "$DB_NAME_TEZOS"     < sql/schema/tezos.sql
"${mysql_cmd[@]}" "$DB_NAME_TEZOS_LOG" < sql/schema/tezos_log.sql

#-----------------------------------------------------------------------------------------------------------------------
# Reference data + indexer start point

seeded=$("${mysql_cmd[@]}" --batch --skip-column-names "$DB_NAME_TEZOS" \
    -e 'SELECT COUNT(*) FROM fixer_transactions_sync')

if [ "$seeded" != "0" ]; then
    echo "==> reference data already loaded (fixer_transactions_sync has $seeded row(s)), skipping"
else
    echo "==> loading reference data"

    # load.sh calls `mysql`; mariadb-client provides it as a symlink
    sql/data/load.sh main   "$DB_NAME_MAIN"   "${conn_opts[@]}" -u "$DB_USER" -p"$DB_PASSWORD"
    sql/data/load.sh shared "$DB_NAME_SHARED" "${conn_opts[@]}" -u "$DB_USER" -p"$DB_PASSWORD"
    sql/data/load.sh tezos  "$DB_NAME_TEZOS"  "${conn_opts[@]}" -u "$DB_USER" -p"$DB_PASSWORD"

    #-------------------------------------------------------------------------------------------------------------------
    # TEZOS_START_LEVEL moves the start point off block 1.
    #
    # The data files seed block 1 so that the database gets the whole chain history, which takes days to catch up.
    # A later start level skips that history: the indexer then only knows tokens minted from that block on.
    # All five values have to move together (see sql/data/README.md).

    start_level="${TEZOS_START_LEVEL:-1}"

    if [ "$start_level" != "1" ]; then
        echo "==> setting indexer start level to $start_level"

        "${mysql_cmd[@]}" "$DB_NAME_TEZOS" <<SQL
UPDATE fixer_transactions_sync      SET level = $start_level WHERE level = 1;
UPDATE fixer_bigmap_updates_sync    SET level = $start_level WHERE level = 1;
UPDATE fixer_bigmap_updates_analyze SET level = $start_level WHERE level = 1;
UPDATE fixer_account_operations_sync SET level = $start_level WHERE level = 1;
UPDATE config SET cfg_value = $((start_level - 1)) WHERE cfg_key = 'TezosAnalyzer.LastSyncedLevel';
SQL
    fi
fi

#-----------------------------------------------------------------------------------------------------------------------
echo "==> creating the monthly log tables for the current month"

setpriv --reuid=www-data --regid=www-data --init-groups \
    "$APP_DIR/commands/run" /lifecycle/prepare/tables

echo "==> database is ready"
