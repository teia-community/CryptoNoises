<?php

namespace CryptoNoisesTezos\Syncs;

use Exception;

/**
 * A sync that can write a whole range of levels under one database transaction.
 *
 * Implementing this is purely an optimisation: BatchSyncRunner uses it when it is there and falls back to sync()
 * per level when it is not, and it falls back per level anyway when a batch fails, so one bad level is still
 * marked on its own. Every row and every consistency check of the per-level path has to survive in the batch
 * path - the point is to stop paying for round trips one level at a time, not to do less work.
 */
interface BatchedLevelSyncInterface extends LevelSyncInterface
{
    /**
     * Prepares whatever the batch needs outside a transaction (log tables are created with DDL, which commits
     * implicitly), then opens the batch transaction.
     *
     * @param array<int, array> $itemsByLevel level => items, every level the batch will add
     */
    public function batchBegin(array $itemsByLevel): void;

    /**
     * Adds one level's items to the open batch.
     *
     * @throws Exception when the level cannot be built; the runner rolls the batch back
     */
    public function batchAdd(int $level, array $items): void;

    /**
     * Runs the consistency checks and commits.
     *
     * @return array<int, int> level => items stored
     * @throws Exception       with nothing committed
     */
    public function batchCommit(): array;

    public function batchRollback(): void;
}
