<?php

namespace CryptoCore\Setapp;

/** Teia's community events (teia.art/events): a contract listing them, each event a JSON document on IPFS. */
class TeiaEvents
{
    public const ContractKTID                       = 'KT1KDXXwq8bHKr5f1cM6E66tzxYNuZ2x913o';
    public const EventsPageUrl                      = 'https://teia.art/events';

    // Teia's node for these documents; the usual gateways are tried after it
    public const IpfsGateway                        = 'https://ipfsmsg.teia.art/ipfs/';
}
