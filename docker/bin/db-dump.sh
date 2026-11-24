#!/usr/bin/env bash
#
# Dumps the databases, compresses, optionally encrypts, optionally uploads to S3, and prunes old artifacts.
#
# Usage: db-dump.sh [seed|full]
#
#   seed   the indexer schemas only - chain data, and nothing else. No accounts, no wallets, no message logs.
#          This is what seeds a new deployment, and it deliberately contains no personal data, so it can be
#          moved around without ceremony.
#   full   everything, including the bots schema: users, their wallets, their Telegram chat ids and Discord
#          user ids, and the monthly message-log tables holding message text and Telegram API payloads.
#          This one carries personal data and the script refuses to put it in S3 unencrypted.
#
# Environment (all optional except the database credentials):
#   BACKUP_DIR              where artifacts are written (default /backup)
#   BACKUP_PASSPHRASE       if set, the artifact is encrypted with AES-256 before it leaves this container
#   BACKUP_S3_URL           e.g. s3://my-bucket/cryptonoises/ - if set, the artifact is uploaded
#   BACKUP_S3_ENDPOINT      e.g. https://s3.example.com for an S3-compatible service
#   AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_REGION
#   BACKUP_RETENTION_DAYS   prune artifacts of the same kind older than this (default 7, 0 disables)

set -euo pipefail

MODE="${1:-full}"
case "$MODE" in
    seed|full) ;;
    *) echo "usage: db-dump.sh [seed|full]" >&2; exit 2 ;;
esac

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME_MAIN="${DB_NAME_MAIN:-cryptonoises}"
DB_NAME_SHARED="${DB_NAME_SHARED:-cryptonoises_shared}"
DB_NAME_TEZOS="${DB_NAME_TEZOS:-cryptonoises_tezos}"
DB_NAME_TEZOS_LOG="${DB_NAME_TEZOS_LOG:-cryptonoises_tezos_log}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"

BACKUP_DIR="${BACKUP_DIR:-/backup}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"

# The indexer schemas are pure chain data. The bots schema is the one with personal data in it.
SCHEMAS=("$DB_NAME_TEZOS" "$DB_NAME_TEZOS_LOG" "$DB_NAME_SHARED")
[ "$MODE" = "full" ] && SCHEMAS+=("$DB_NAME_MAIN")

#-----------------------------------------------------------------------------------------------------------------------
# Refuse to put personal data in object storage in the clear. A seed dump has none, so it may go up unencrypted.

if [ "$MODE" = "full" ] && [ -n "${BACKUP_S3_URL:-}" ] && [ -z "${BACKUP_PASSPHRASE:-}" ]; then
    echo "db-dump: refusing to upload a full dump without BACKUP_PASSPHRASE." >&2
    echo "         A full dump contains users, their wallets, their Telegram and Discord ids and the message" >&2
    echo "         logs. Set BACKUP_PASSPHRASE, or dump with 'seed' which contains no personal data." >&2
    exit 1
fi

#-----------------------------------------------------------------------------------------------------------------------

wait-for-db

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="cryptonoises-$MODE-$STAMP.sql.gz"
[ -n "${BACKUP_PASSPHRASE:-}" ] && NAME="$NAME.enc"

mkdir -p "$BACKUP_DIR"
OUT="$BACKUP_DIR/$NAME"

echo "==> dumping [$MODE]: ${SCHEMAS[*]}"

# --no-tablespaces : the application user has no global PROCESS privilege, which mysqldump otherwise wants
# --hex-blob       : ipfs_uri and the token tags are varbinary holding arbitrary bytes from the chain; hex
#                    literals survive a text dump where raw bytes would not
# --routines       : sql/schema/tezos.sql defines ADDR2ADID() and ADID2ADDR()
# --single-transaction : a consistent snapshot without locking out the running workers
dump() {
    mariadb-dump \
        --protocol=TCP --ssl-verify-server-cert=0 \
        -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD" \
        --single-transaction \
        --no-tablespaces \
        --hex-blob \
        --routines \
        --default-character-set=utf8mb4 \
        --databases "${SCHEMAS[@]}"
}

if [ -n "${BACKUP_PASSPHRASE:-}" ]; then
    # AES-256-CBC with a PBKDF2-derived key. The passphrase is passed by environment, never on the command line.
    dump | gzip -6 | openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -md sha512 -salt -pass env:BACKUP_PASSPHRASE > "$OUT"
else
    dump | gzip -6 > "$OUT"
fi

echo "==> wrote $OUT ($(du -h "$OUT" | cut -f1))"

#-----------------------------------------------------------------------------------------------------------------------

if [ -n "${BACKUP_S3_URL:-}" ]; then
    S3_ARGS=()
    [ -n "${BACKUP_S3_ENDPOINT:-}" ] && S3_ARGS+=(--endpoint-url "$BACKUP_S3_ENDPOINT")

    echo "==> uploading to ${BACKUP_S3_URL%/}/$NAME"
    s5cmd "${S3_ARGS[@]}" cp "$OUT" "${BACKUP_S3_URL%/}/$NAME"

    if [ "$RETENTION_DAYS" != "0" ]; then
        # Prune by the timestamp in the name rather than by object mtime: the name is what this script controls,
        # and re-uploading an old artifact must not make it look fresh.
        CUTOFF="$(date -u -d "$RETENTION_DAYS days ago" +%Y%m%dT%H%M%SZ)"
        echo "==> pruning $MODE artifacts older than $CUTOFF"

        s5cmd "${S3_ARGS[@]}" ls "${BACKUP_S3_URL%/}/cryptonoises-$MODE-*" 2>/dev/null \
        | awk '{print $NF}' \
        | while read -r obj; do
            ts="$(printf '%s' "$obj" | sed -nE "s/^cryptonoises-$MODE-([0-9]{8}T[0-9]{6}Z).*/\1/p")"
            [ -z "$ts" ] && continue
            if [[ "$ts" < "$CUTOFF" ]]; then
                echo "    rm $obj"
                s5cmd "${S3_ARGS[@]}" rm "${BACKUP_S3_URL%/}/$obj" >/dev/null
            fi
        done
    fi
fi

# Keep the local copy of the newest few only; S3 is the archive
if [ "$RETENTION_DAYS" != "0" ]; then
    ls -1t "$BACKUP_DIR"/cryptonoises-"$MODE"-*.sql.gz* 2>/dev/null | tail -n +4 | while read -r f; do
        echo "==> removing local $f"
        rm -f "$f"
    done
fi

echo "==> done"
