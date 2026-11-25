-- CryptoNoises: reference data for `config` (tezos database). Load with: sql/data/load.sh tezos <database>

-- command-lock.*: manual pause switch per command: '0' = enabled, any other value = the command exits ("Command locked!");
--   command-lock.analyze.bigmap-updates.max-level: NULL = no limit, or a block level to stop bigmap analysis at.
-- TezosAnalyzer.LastSyncedLevel: last analyzed block. '0' = start from block 1, together with the level 1 'idle' rows
--   in fixer_transactions_sync.sql, fixer_bigmap_updates_sync.sql and fixer_bigmap_updates_analyze.sql.
INSERT INTO `config` (`cfg_key`, `cfg_value`) VALUES
  ('command-lock.analyze.bigmap-updates.max-level', NULL),
  ('command-lock.analyze.bigmap-updates.new', '0'),
  ('command-lock.analyze.transactions.new', '0'),
  ('command-lock.blockchain.account-operations.sync-new', '0'),
  ('command-lock.blockchain.bigmap-updates.sync-check', '0'),
  ('command-lock.blockchain.bigmap-updates.sync-new', '0'),
  ('command-lock.blockchain.transaction.sync-new', '0'),
  ('command-lock.job.detect-new-tokens', '0'),
  ('command-lock.job.merge-tokens-metadata', '0'),
  ('command-lock.job.sync-contracts-metadata', '0'),
  ('command-lock.job.sync-ipfs.primary', '0'),
  ('command-lock.job.sync-ipfs.secondary', '0'),
  ('command-lock.job.sync-profiles', '0'),
  ('command-lock.job.sync-tokens-metadata', '0'),
  ('command-lock.jobs-clean.file-cache', '0'),
  ('command-lock.jobs-clean.media-convert-zombies', '0'),
  ('TezosAnalyzer.LastSyncedLevel', '0');
