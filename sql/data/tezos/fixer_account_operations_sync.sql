-- CryptoNoises: reference data for `fixer_account_operations_sync` (tezos database). Load with: sql/data/load.sh tezos <database>

-- Start point of `run-tezos /blockchain/account-operations/sync-new`: an 'idle' row is the next level to sync (here block 1),
-- moved to TEZOS_START_LEVEL by docker/bin/db-init.sh together with the other cursors. An install that predates this
-- table starts it where the transaction sync stands (see BlockchainAccountOperations::seedIfNeverRan()).
INSERT INTO `fixer_account_operations_sync` (`level`, `state`) VALUES
  (1, 'idle');
