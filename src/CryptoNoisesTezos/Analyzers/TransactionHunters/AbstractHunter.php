<?php

namespace CryptoNoisesTezos\Analyzers\TransactionHunters;

abstract class AbstractHunter
{
    // Set by the analyzer before analyze(); declared here because PHP 8.2 deprecates dynamic properties
    public bool $verbose = false;

    //------------------------------------------------------------------------------------------------------------------

    abstract public function analyze(array $recInit, array $records): bool;

    // @override if you need
    public function finalize(): void
    {
    }

    //------------------------------------------------------------------------------------------------------------------

    public static function ExtractInitRecord(array &$records): ?array
    {
        foreach ($records as $k => $record)
        {
            // Raw DB rows: the init record is the one without initiator (see GatewayTezos\Gateway for the remapped variant)
            if ($record['initiator_address'] === null)
            {
                unset($records[$k]);
                return $record;
            }
        }

        return null;
    }

    protected static function ExtractRecordByEntrypoint(array &$records, string $entrypoint): ?array
    {
        foreach ($records as $k => $record)
        {
            if ($record['entrypoint'] == $entrypoint)
            {
                unset($records[$k]);
                return $record;
            }
        }

        return null;
    }

    public static function ExtractRecordByTargetAddress(array &$records, string $address): ?array
    {
        foreach ($records as $key => $record)
        {
            if ($record['target_address'] == $address)
            {
                unset($records[$key]);
                return $record;
            }
        }

        return null;
    }
}
