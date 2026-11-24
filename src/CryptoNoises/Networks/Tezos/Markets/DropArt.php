<?php

namespace CryptoNoises\Networks\Tezos\Markets;

use CryptoCore\Http\AbstractVendor;
use CryptoCore\Setapp;
use CryptoNoises\GatewayTezos;

class DropArt extends AbstractVendor
{
    /**
     * The token contract and creator behind a drop.art project, or null when the project is not indexed here.
     *
     * @return array{fa2: string|null, creator: string|null}|null
     */
    public static function GetProjectDetails(?string $projectId): ?array
    {
        if ($projectId === null || $projectId === '')
            return null;

        $value = GatewayTezos\BigmapGate::GetValueByBigmapAndKey(Setapp\DropArt::Bigmap_Projects, $projectId);

        if (!is_array($value))
            return null;

        return [
            'fa2'     => $value['fa2'] ?? null,
            'creator' => $value['creator'] ?? null,
        ];
    }
}
