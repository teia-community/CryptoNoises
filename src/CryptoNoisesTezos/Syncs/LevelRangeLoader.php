<?php

namespace CryptoNoisesTezos\Syncs;

use CryptoNoisesTezos\Vendors;

/**
 * Loads TzKT items (transactions, bigmap updates) of a block range with as few requests as possible.
 *
 * Items come sorted by id, i.e. by level. When a page is full, the last level of the page may be cut by the limit:
 * it is dropped and the range ends right before it (the caller loads it next time). When one level alone does not
 * fit into a page, its items are paged by id.
 */
final class LevelRangeLoader
{
    const kPAGE_LIMIT = 10000;

    /** @var array<int, array> level => items, only levels that have items */
    public array $itemsByLevel = [];

    /** Last level whose items are fully loaded (<= the requested levelTo) */
    public int $levelTo;

    public int $itemsCount = 0;

    /**
     * Loads the range for a sync, through every endpoint it reads.
     */
    public static function LoadFor(LevelSyncInterface $sync, int $levelFrom, int $levelTo): ?self
    {
        if ($sync instanceof MultiPathLevelSyncInterface)
            return self::LoadMany($sync->tzktRangePaths(), $levelFrom, $levelTo);

        return self::Load($sync->tzktRangePath(), $levelFrom, $levelTo);
    }

    /**
     * Loads several endpoints for the same range and merges their items by level, in chain order (TzKT operation
     * ids are global across operation kinds). Every item gets the key of its endpoint in `__kind`.
     *
     * Each endpoint may end early when a page fills up. The merged range ends at the earliest of those ends and
     * drops the levels after it, so a level is never returned with the items of only some endpoints - the cursor
     * would step over the missing ones for good.
     *
     * @param array<string, string> $tzktPaths key => TzKT list endpoint
     */
    public static function LoadMany(array $tzktPaths, int $levelFrom, int $levelTo): ?self
    {
        $merged = new self();
        $merged->levelTo = $levelTo;

        $loaded = [];

        foreach ($tzktPaths as $kind => $path)
        {
            $range = self::Load($path, $levelFrom, $levelTo);

            if ($range === null)
                return null;

            $loaded[$kind] = $range;
            $merged->levelTo = min($merged->levelTo, $range->levelTo);
        }

        foreach ($loaded as $kind => $range)
            foreach ($range->itemsByLevel as $level => $items)
            {
                if ($level > $merged->levelTo)
                    continue;

                foreach ($items as $item)
                {
                    $item['__kind'] = $kind;
                    $merged->itemsByLevel[$level][] = $item;
                }
            }

        ksort($merged->itemsByLevel);

        foreach ($merged->itemsByLevel as $level => $items)
        {
            usort($items, fn (array $a, array $b) => $a['id'] <=> $b['id']);
            $merged->itemsByLevel[$level] = $items;
            $merged->itemsCount += count($items);
        }

        return $merged;
    }

    public static function Load(string $tzktPath, int $levelFrom, int $levelTo): ?self
    {
        $range = new self();
        $idAfter = null;

        while (true)
        {
            $page = Vendors\Tzkt::GetLevelRangePage($tzktPath, $levelFrom, $levelTo, self::kPAGE_LIMIT, $idAfter);

            if ($page === null)
                return null;

            foreach ($page as $item)
                $range->itemsByLevel[(int) $item['level']][] = $item;

            if (count($page) < self::kPAGE_LIMIT)
            {
                $range->levelTo = $levelTo;
                break;
            }

            $last = end($page);
            $idAfter = $last['id'];

            if ($last['level'] > $levelFrom)
            {
                // The last level may be incomplete: keep only the levels before it
                unset($range->itemsByLevel[(int) $last['level']]);
                $range->levelTo = $last['level'] - 1;
                break;
            }

            // A single level has more items than one page: continue paging inside it
            $levelTo = $levelFrom;
        }

        foreach ($range->itemsByLevel as $items)
            $range->itemsCount += count($items);

        return $range;
    }
}
