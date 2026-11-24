# Reference data

Rows the code needs to work properly on a fresh install (marketplace names and URLs, known mimetypes, currencies, ...).
No personal data; `main/exceptions.sql` keeps the tokens and collections excluded from offer notifications on the
original install.

Layout: `sql/data/<db>/<table>.sql`, where `<db>` matches the schema file name in `sql/schema/` (`main`, `shared`,
`tezos`). One file per table, plain `INSERT` statements only.

## Apply

First create the schema (see the main README), then load each database with `load.sh`:

```bash
sql/data/load.sh main   cryptonoises        -u root -p
sql/data/load.sh shared cryptonoises_shared -u root -p
sql/data/load.sh tezos  cryptonoises_tezos  -u root -p
```

Use your database names (the same ones as in `config/consts-secrets.php`); everything after the database name is passed
to `mysql` as is.

`load.sh` runs one `mysql` session: it turns foreign key checks off, inserts all files of the folder (so their order
does not matter), turns the checks back on and then runs `check-foreign-keys.sql`, which looks for rows that reference
missing rows. If it finds any, it prints them and exits with an error.

Load into empty tables only, once: running it again fails on duplicate keys.

## Indexer start block

The indexer scans the chain from block 1. The start point is set by the `tezos` data: an `'idle'` row with
`level = 1` in `fixer_transactions_sync`, `fixer_bigmap_updates_sync` and `fixer_bigmap_updates_analyze`, and
`TezosAnalyzer.LastSyncedLevel = 0` in `config` (always start level - 1). To start later, change all four values
together before loading. With Docker, `TEZOS_START_LEVEL` in `.env` does this for you.

Starting from block 1 gives the database the whole history, and takes days to reach the chain head. A later start
level catches up in minutes, with these consequences:

- Tokens minted before the start level are unknown to the indexer, so the bots cannot report on them.
- Balances and operators are built from the bigmap updates the indexer has seen. For a token that existed before
  the start level, a holder only appears once their balance changes, so ownership data is incomplete until then.
- Bigmaps allocated before the start level have no `allocate` action to create their `bigmaps` row. The bigmap
  analyzer creates the row from TzKT when it first sees an update for an unknown bigmap, so this no longer stops
  the analysis; only `bigmaps.first_level` is then the block where this indexer first saw the bigmap, not the
  block it was allocated in.
