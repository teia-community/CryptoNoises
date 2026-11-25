<?php

namespace CryptoNoisesTezos\Syncs;

/**
 * A sync whose items come from several TzKT list endpoints, loaded for the same level range and merged by level
 * (see LevelRangeLoader::LoadMany). Each item carries the key of its endpoint in `__kind`.
 *
 * tzktRangePath() is then only a label for logs: the runner loads through tzktRangePaths().
 */
interface MultiPathLevelSyncInterface extends LevelSyncInterface
{
    /**
     * @return array<string, string> key => TzKT list endpoint, e.g. 'delegation' => 'operations/delegations'
     */
    public function tzktRangePaths(): array;
}
