<?php

namespace CryptoCore\Setapp;

/** Teia's core team multisig (also the DAO treasury), the DAO governance contract and community polls */
class TeiaGovernance
{
    public const MultisigKTID                       = 'KT1J9FYz29RBQi1oGLw8uXyACrzXzV1dHuvb';
    public const MultisigUrl                        = 'https://teia-multisig.teia.art/';

    public const DaoKTID                            = 'KT1GHX73W5BcjbYRSZSrUJcnZE3Uw92VYF66';
    public const DaoUrl                             = 'https://teia.art/dao';

    public const PollsKTID                          = 'KT1SUExZfkmxf2fafrVgYjZGEKDete2siWoU';
    public const PollUrl                            = 'https://teia.art/poll/';     // + poll id

    // Tokens the multisig sends, for readable amounts: contract => [symbol, decimals]
    public const Tokens = [
        'KT1QrtA753MSv8VGxkDrKKyJniG5JtuHHbtV' => ['TEIA', 6],
        'KT1XnTn74bUtxHfDtBmm2bGZAQfhPbvKWR8o' => ['USDt', 6],
    ];
}
