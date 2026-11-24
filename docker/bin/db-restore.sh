#!/usr/bin/env bash
#
# Restores a dump produced by db-dump.sh.
#
# Usage: db-restore.sh [latest|<artifact-name>|<path>]
#
#   latest          newest artifact (prefers S3 when BACKUP_S3_URL is set, otherwise BACKUP_DIR)
#   <artifact-name> a name as db-dump.sh produced it; fetched from S3 if not already local
#   <path>          an explicit local path
#
# Restoring is destructive: the dump recreates the schemas it contains. This refuses to run over databases
# that already hold indexer data unless BACKUP_RESTORE_FORCE=1, so seeding a fresh deployment is the easy
# path and overwriting a working one is deliberate.
#
# Environment: as db-dump.sh, plus
#   BACKUP_RESTORE_FORCE=1   allow restoring over non-empty databases
#   BACKUP_RESTORE_KIND      seed|full, only to pick the newest of that kind for "latest" (default: either)

set -euo pipefail

WANT="${1:-latest}"

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME_TEZOS="${DB_NAME_TEZOS:-cryptonoises_tezos}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"

BACKUP_DIR="${BACKUP_DIR:-/backup}"
KIND="${BACKUP_RESTORE_KIND:-}"

S3_ARGS=()
[ -n "${BACKUP_S3_ENDPOINT:-}" ] && S3_ARGS+=(--endpoint-url "$BACKUP_S3_ENDPOINT")

mysql_cmd=(mariadb --protocol=TCP --ssl-verify-server-cert=0 -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASSWORD")

wait-for-db

#-----------------------------------------------------------------------------------------------------------------------
# Work out which artifact to restore

ARTIFACT=""

if [ -f "$WANT" ]; then
    ARTIFACT="$WANT"
elif [ -f "$BACKUP_DIR/$WANT" ]; then
    ARTIFACT="$BACKUP_DIR/$WANT"
elif [ "$WANT" = "latest" ]; then
    pattern="cryptonoises-${KIND:+$KIND-}"

    if [ -n "${BACKUP_S3_URL:-}" ]; then
        echo "==> looking for the newest $pattern* in $BACKUP_S3_URL"
        newest="$(s5cmd "${S3_ARGS[@]}" ls "${BACKUP_S3_URL%/}/$pattern*" 2>/dev/null \
                  | awk '{print $NF}' | sort | tail -1)"
        if [ -n "$newest" ]; then
            echo "==> downloading $newest"
            s5cmd "${S3_ARGS[@]}" cp "${BACKUP_S3_URL%/}/$newest" "$BACKUP_DIR/$newest"
            ARTIFACT="$BACKUP_DIR/$newest"
        fi
    fi

    if [ -z "$ARTIFACT" ]; then
        ARTIFACT="$(ls -1t "$BACKUP_DIR"/$pattern*.sql.gz* 2>/dev/null | head -1 || true)"
    fi
else
    echo "==> fetching $WANT"
    s5cmd "${S3_ARGS[@]}" cp "${BACKUP_S3_URL%/}/$WANT" "$BACKUP_DIR/$WANT"
    ARTIFACT="$BACKUP_DIR/$WANT"
fi

if [ -z "$ARTIFACT" ] || [ ! -f "$ARTIFACT" ]; then
    echo "db-restore: no artifact found for [$WANT]" >&2
    exit 1
fi

echo "==> restoring from $ARTIFACT ($(du -h "$ARTIFACT" | cut -f1))"

#-----------------------------------------------------------------------------------------------------------------------
# Refuse to clobber a database that is already indexing

existing="$("${mysql_cmd[@]}" --batch --skip-column-names -e "
    SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME_TEZOS'" 2>/dev/null || echo 0)"

if [ "${existing:-0}" != "0" ] && [ "${BACKUP_RESTORE_FORCE:-0}" != "1" ]; then
    rows="$("${mysql_cmd[@]}" --batch --skip-column-names "$DB_NAME_TEZOS" \
            -e 'SELECT COUNT(*) FROM fixer_transactions_sync' 2>/dev/null || echo 0)"
    echo "db-restore: $DB_NAME_TEZOS already has $existing tables and $rows sync rows." >&2
    echo "            Restoring would replace them. Set BACKUP_RESTORE_FORCE=1 if that is what you want," >&2
    echo "            or bring the stack up on empty volumes first." >&2
    exit 1
fi

#-----------------------------------------------------------------------------------------------------------------------
# Decrypt if needed, decompress, feed to the server

feed() {
    if [[ "$ARTIFACT" == *.enc ]]; then
        : "${BACKUP_PASSPHRASE:?the artifact is encrypted, so BACKUP_PASSPHRASE is required}"
        openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -md sha512 -pass env:BACKUP_PASSPHRASE -in "$ARTIFACT"
    else
        cat "$ARTIFACT"
    fi | gunzip
}

# The dump carries CREATE DATABASE ... / USE ... for each schema it contains, so no database is named here.
feed | "${mysql_cmd[@]}"

echo "==> restored"

#-----------------------------------------------------------------------------------------------------------------------

"${mysql_cmd[@]}" "$DB_NAME_TEZOS" -e '
    SELECT "tx sync head" AS stage, IFNULL(MAX(level),0) AS level FROM fixer_transactions_sync WHERE state="success"
    UNION ALL SELECT "bigmap sync head", IFNULL(MAX(level),0) FROM fixer_bigmap_updates_sync WHERE state="success"
    UNION ALL SELECT "bigmap analyze head", IFNULL(MAX(level),0) FROM fixer_bigmap_updates_analyze WHERE state="success"
    UNION ALL SELECT "tokens", COUNT(*) FROM tokens' 2>/dev/null || true

cat <<'NOTE'

The indexer will carry on from the cursors above, so it only has to catch up the gap since the dump.

If this is a deployment that is about to run the bots, two things still need doing - see "Enabling the bots"
in the README:

  1. DELETE FROM <tezos db>.queue_tokens_events;
     A full-history dump carries one mint event per token that ever existed. /events/nfts-mint/tezos has no
     age filter, so without this the first users are notified about mints from years ago.

  2. INSERT INTO <main db>.fixer_tezos_transactions_analyze (level, state) VALUES (<current level>, 'idle');
     The bots start from the current block; they do not need history.

The media previews are NOT in this dump. ipfs_media rows say a token has a thumbnail, and the file lives in
the ipfs-previews volume. Without those files the preview URLs in bot messages will 404, so copy that volume
too, or accept that older tokens have no image.
NOTE
