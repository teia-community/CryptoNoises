<?php

namespace CryptoNoisesTezos\Syncs;

use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Models\AccountOperations;
use CryptoCore\Vss\Vss;
use Exception;

/**
 * Syncs the account-level operations that are not transactions - delegations, staking, baker parameter changes,
 * originations - from four TzKT endpoints into account_operations.
 *
 * Not batched: these are ~0.2% of the transaction volume, so almost every level of a range is empty and closed in
 * bulk by the runner, and the few levels with items are cheap one transaction at a time.
 */
class SyncAccountOperations implements MultiPathLevelSyncInterface
{
    private ?string $lastErrorMessage = null;

    /** @var array<string, int> address => adid, kept for the life of the process */
    private array $adidsCache = [];

    public bool $verbose = false;

    public function tzktRangePaths(): array
    {
        return AccountOperations::kTZKT_PATHS;
    }

    public function tzktRangePath(): string
    {
        return 'account operations';    // a label: the runner loads through tzktRangePaths()
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function sync(int $level, ?array $items = null): ?int
    {
        try
        {
            if ($items === null)
            {
                // sync-range: load this one level through every endpoint
                $range = LevelRangeLoader::LoadMany($this->tzktRangePaths(), $level, $level);

                if ($range === null)
                    throw new Exception(self::kERROR_NoData);

                $items = $range->itemsByLevel[$level] ?? [];
            }

            if (empty($items))
                return 0;

            $this->warmAddressCache($items);

            $rows = array_map(fn (array $item) => $this->buildRow($item), $items);

            Vss::dbTezos()->transactionBegin();

            AccountOperations::model()->insertMany($rows);

            // Same consistency check as the transaction sync: what is stored for the level is what TzKT returned
            $stored = AccountOperations::model()->getItemsCountInLevel($level);

            if ($stored != count($rows))
                throw new Exception("In/Out items count mismatch ".count($rows)." <> $stored");

            Vss::dbTezos()->transactionCommit();

            if ($this->verbose)
                echo "- level $level: $stored account operation(s)\n";

            return $stored;
        }
        catch (Exception $e)
        {
            Vss::dbTezos()->transactionRollback();

            $this->lastErrorMessage = $e->getMessage();
            return null;
        }
    }

    /**
     * One TzKT item, of any of the four kinds, as an account_operations row.
     *
     * @throws Exception on an item kind this does not know - a new endpoint must be mapped, not stored half-empty
     */
    private function buildRow(array $item): array
    {
        $kind = $item['__kind'] ?? null;

        $row = [
            'id'        => $item['id'],
            'type'      => $kind,
            'level'     => $item['level'],
            'timestamp' => $item['timestamp'],
            'hash'      => $item['hash'] ?? '',
            'counter'   => $item['counter'] ?? 0,
            'status'    => $item['status'] ?? 'applied',
            'sender_adid' => $this->adid($item['sender']['address'] ?? null),
            'sender_alias' => self::Alias($item['sender'] ?? null),
        ];

        switch ($kind)
        {
            case AccountOperations::kTYPE_Delegation:
                // The delegating account is the sender - a contract setting its own delegate included.
                // newDelegate is absent on an undelegation, prevDelegate on a first delegation.
                $row['account_adid']     = $row['sender_adid'];
                $row['baker_adid']       = $this->adid($item['newDelegate']['address'] ?? null);
                $row['baker_alias']      = self::Alias($item['newDelegate'] ?? null);
                $row['prev_baker_adid']  = $this->adid($item['prevDelegate']['address'] ?? null);
                $row['prev_baker_alias'] = self::Alias($item['prevDelegate'] ?? null);
                $row['amount']           = $item['amount'] ?? 0;
                break;

            case AccountOperations::kTYPE_Staking:
                // The staker, not the sender: anyone may finalize for a staker (most third-party finalizations
                // near the head come from one service, "Finn"), and the staker is whose tez moved
                $row['account_adid']     = $this->adid($item['staker']['address'] ?? $item['sender']['address'] ?? null);
                $row['baker_adid']       = $this->adid($item['baker']['address'] ?? null);
                $row['baker_alias']      = self::Alias($item['baker'] ?? null);
                $row['action']           = $item['action'] ?? null;
                $row['amount']           = $item['amount'] ?? 0;
                $row['requested_amount'] = $item['requestedAmount'] ?? null;
                break;

            case AccountOperations::kTYPE_SetDelegateParameters:
                $row['account_adid']                 = $row['sender_adid'];
                $row['baker_adid']                   = $row['sender_adid'];
                $row['baker_alias']                  = $row['sender_alias'];
                $row['limit_of_staking_over_baking'] = $item['limitOfStakingOverBaking'] ?? null;
                $row['edge_of_baking_over_staking']  = $item['edgeOfBakingOverStaking'] ?? null;
                $row['activation_cycle']             = $item['activationCycle'] ?? null;
                break;

            case AccountOperations::kTYPE_Origination:
                // A factory contract is the sender when a wallet calls it to deploy: the wallet is the initiator
                $row['account_adid']  = $this->adid($item['initiator']['address'] ?? $item['sender']['address'] ?? null);
                $row['contract_adid'] = $this->adid($item['originatedContract']['address'] ?? null);
                $row['amount']        = $item['contractBalance'] ?? 0;
                break;

            default:
                throw new Exception("Unknown account operation kind '$kind' (operation {$item['id']})");
        }

        if ($row['sender_adid'] === null || $row['account_adid'] === null)
            throw new Exception("Operation {$item['id']} ($kind) has no sender or account address");

        // Merged over a fixed template so every kind yields the same columns in the same order: insertMany()
        // groups rows by key shape, order included, and one shape keeps it at one statement per chunk
        return array_merge(self::kROW_TEMPLATE, $row);
    }

    const kROW_TEMPLATE = [
        'id' => null, 'type' => null, 'level' => null, 'timestamp' => null, 'hash' => '', 'counter' => 0,
        'status' => 'applied', 'sender_adid' => null, 'account_adid' => null, 'baker_adid' => null,
        'prev_baker_adid' => null, 'contract_adid' => null, 'action' => null, 'amount' => 0, 'requested_amount' => null,
        'limit_of_staking_over_baking' => null, 'edge_of_baking_over_staking' => null, 'activation_cycle' => null,
        'sender_alias' => null, 'baker_alias' => null, 'prev_baker_alias' => null,
    ];

    /**
     * TzKT's alias of an address object, cut to the column width. Null when it has none.
     */
    private static function Alias(?array $addressObject): ?string
    {
        $alias = $addressObject['alias'] ?? null;

        return ($alias === null || $alias === '') ? null : mb_substr((string) $alias, 0, 255);
    }

    private function adid(?string $address): ?int
    {
        if ($address === null || $address === '')
            return null;

        if (!isset($this->adidsCache[$address]))
        {
            $known = Models\Address::model()->getAdidsByAddresses([$address]);

            if (!isset($known[$address]))
            {
                Models\Address::model()->createMany([$address]);
                $known = Models\Address::model()->getAdidsByAddresses([$address]);
            }

            if (!isset($known[$address]))
                throw new Exception("Cannot resolve address $address");

            $this->adidsCache[$address] = (int) $known[$address];
        }

        return $this->adidsCache[$address];
    }

    /**
     * Resolves every address of the level in two queries rather than one or two per address.
     */
    private function warmAddressCache(array $items): void
    {
        $wanted = [];

        foreach ($items as $item)
            foreach (['sender', 'initiator', 'staker', 'baker', 'newDelegate', 'prevDelegate', 'originatedContract'] as $role)
            {
                $address = $item[$role]['address'] ?? null;

                if ($address !== null && !isset($this->adidsCache[$address]))
                    $wanted[$address] = true;
            }

        if (empty($wanted))
            return;

        $wanted  = array_keys($wanted);
        $known   = Models\Address::model()->getAdidsByAddresses($wanted);
        $missing = array_values(array_diff($wanted, array_keys($known)));

        if ($missing)
        {
            Models\Address::model()->createMany($missing);
            $known += Models\Address::model()->getAdidsByAddresses($missing);
        }

        foreach ($known as $address => $adid)
            $this->adidsCache[$address] = (int) $adid;
    }
}
