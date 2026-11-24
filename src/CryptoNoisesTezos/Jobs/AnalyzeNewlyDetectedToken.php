<?php

namespace CryptoNoisesTezos\Jobs;

use CryptoNoisesTezos\Analyzers;
use CryptoNoisesTezos\Core\Bigmap;
use CryptoNoisesTezos\Entities;
use CryptoNoisesTezos\Models;
use CryptoCore\Enum\JobResult;
use Exception;

class AnalyzeNewlyDetectedToken extends AbstractJob
{
    public ?Entities\Token $token;

    private ?array $struct;
    private ?string $structMask;

    public ?int $bigmap;
    public ?int $bigmapExkey;
    public ?int $level;

    public function execute(array $taskItem): JobResult
    {
        $this->initJob();

        $this->token       = null;
        $this->struct      = null;
        $this->structMask  = null;

        $this->bigmap      = $taskItem['bigmap'];
        $this->bigmapExkey = $taskItem['bigmap_exkey'];
        $this->level       = $taskItem['level'];

        return $this->analyze($taskItem) ? JobResult::Completed() : JobResult::FailedAndClose();
    }

    private function analyze(array $taskItem): bool
    {
        try
        {
            $contractAddress = Models\Bigmaps::model()->getContractByBigmap($this->bigmap);
            $tokenId         = Bigmap::ExkeyToKey($this->bigmapExkey);

            $value = Bigmap::GetValueByBigmapAndKey($this->bigmap, $tokenId);

            if (empty($value))
                throw new Exception('ERROR: Cannot find/unpack data in bigmaps');

            if (!is_array($value))
                throw new Exception('ERROR: Value data.value is not array');

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // Must run before the default token instance is created
            // (to prevent generate fake/unused token_unids values!)

            if (Analyzers\Tokens\TezosDomains::AnalyzeNewlyDetectedToken($this, $contractAddress, $value))
                return true;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->token = Entities\Token::DummyByAddressAndTokenId($contractAddress, $tokenId);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if (Analyzers\Tokens\EightBidou::AnalyzeNewlyDetectedToken($this, $value))
                return true;

            if (Analyzers\Tokens\FxHash_Issuer::AnalyzeNewlyDetectedToken($this, $value))
                return true;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->struct     = $this->buildParameterMask($value);
            $this->structMask = json_encode($this->struct);

            if (($this->struct['token_id'] ?? null) === 'val')
            {
                // {"token_id":"val",{"token_info":{"":"uri"}}
                if (($this->struct['token_info'][''] ?? null) === 'uri')
                    return $this->openToken($value['token_info'][''] ?? null);

                // {"token_id":"val",{"token_info":{"metadataHash":"uri"}}
                if (($this->struct['token_info']['metadataHash'] ?? null) === 'uri')
                    return $this->openToken($value['token_info']['metadataHash'] ?? null);

                // {"token_id":"val",{"token_metadata_map":{"":"uri"}}
                if (($this->struct['token_metadata_map'][''] ?? null) === 'uri')
                    return $this->openToken($value['token_metadata_map'][''] ?? null);

                // {"token_id":"val","metadata":{"":"uri"}}
                if (($this->struct['metadata'][''] ?? null) === 'uri')
                    return $this->openToken($value['metadata'][''] ?? null);

                // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

                // {"token_id":"val",{"token_info":{"":""}}
                if (($this->struct['token_info'][''] ?? null) === '')
                    return  $this->openTokenPlaceholder('Metadata is empty');

                // {"token_id":"val",{"token_info":[]}
                if (($this->struct['token_info'] ?? null) === [])
                    return  $this->openTokenPlaceholder('Metadata is empty');
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
            // Rare

            // {"token_id":"val",{"token_info":{"cid":"uri"...}}
            if (($this->struct['token_info']['cid'] ?? null) === 'uri')
                return $this->openToken($value['token_info']['cid'] ?? null);

            // {"token_id":"val","token_info":{"ipfs":"uri"}}
            if (($this->struct['token_info']['ipfs'] ?? null) === 'uri')
                return $this->openToken($value['token_info']['ipfs'] ?? null);

            // {"token_id":"val","token_info":{"token_url":"uri"}}
            if (($this->struct['token_info']['token_url'] ?? null) === 'uri')
                return $this->openToken($value['token_info']['token_url'] ?? null);

            // {.. "map":{"":"uri" ..} ..}
            if (($this->struct['map'][''] ?? null) === 'uri')
                return $this->openToken($value['map'][''] ?? null);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            if ($this->tryParseInnerData($value, null))
                return true;

            if ($this->tryParseInnerData($value['token_info'] ?? null, 'token_info'))
                return true;

            if ($this->tryParseInnerData($value['map'] ?? null, 'map'))
                return true;

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->openTokenPlaceholder('Unknown structure: '.$this->structMask);

            throw new Exception('Unknown structure');
        }
        catch (Exception $e)
        {
            $this->lastActionNotice = "ERR [{$e->getMessage()}] $this->structMask";
            return false;
        }
    }

    //------------------------------------------------------------------------------------------------------------------

    /**
     * @param array|mixed $tokenInfo
     * @param string|null $tokenInfoKey
     * @return bool
     */
    private function tryParseInnerData($tokenInfo, ?string $tokenInfoKey): bool
    {
        if (!is_array($tokenInfo))
            return false;

        if (!isset($tokenInfo['decimals']) || (!isset($tokenInfo['name']) && !isset($tokenInfo['symbol'])))
            return false;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $metadataUri = 'tezos-bigmaps:direct:' . ($tokenInfoKey ?? '~');

        // parsed later by the 'sync-tokens-metadata' job, so bigmap data can be re-synced

        $this->token->open($metadataUri, $this->bigmap, $this->bigmapExkey, $this->level);

        $this->lastActionNotice = "{$this->token->contractAdid}:{$this->token->tokenUnid} = $metadataUri";
        return true;
    }


    //------------------------------------------------------------------------------------------------------------------

    /**
     * @param string|null $metadataUriHex
     * @return bool
     * @throws Exception
     */
    private function openToken(?string $metadataUriHex): bool
    {
        if (empty($metadataUriHex))
            throw new Exception('$metadataUriHex is empty');

        $metadataUri = $this->token->unpackHex2Bin($metadataUriHex);

        if ($metadataUri === null)
            throw new Exception('Cannot unpack $metadataUriHex');

        $this->token->open($metadataUri, $this->bigmap, $this->bigmapExkey, $this->level);

        $this->lastActionNotice = "{$this->token->contractAdid}:{$this->token->tokenUnid} = $metadataUri";
        return true;
    }

    /**
     * @param string|null $notice
     * @return bool
     * @throws Exception
     */
    private function openTokenPlaceholder(?string $notice): bool
    {
        $this->token->open(null, $this->bigmap, $this->bigmapExkey, $this->level, $notice);

        $this->lastActionNotice = "{$this->token->contractAdid}:{$this->token->tokenUnid} = [placeholder]";
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    private function buildParameterMask($data)
    {
        if (is_array($data))
        {
            $result = [];
            foreach ($data as $k => $v)
            {
                $result[$k] = $this->buildParameterMask($v);
            }
            ksort($result);
            return $result;
        }

        if ($data === '')
            return '';

        if (strlen($data)%2 == 0)
        {
            if (0 === strpos($data, '697066733a2f2f'))                  return 'uri';   # ipfs://
            if (0 === strpos($data, '687474703a2f2f'))                  return 'uri';   # http://
            if (0 === strpos($data, '68747470733a2f2f'))                return 'uri';   # https://
            if (0 === strpos($data, '7368613235363a2f2f'))              return 'uri';   # sha256://0xd923869773fdf6d1088b80ea17ed7d4a5a521c4906bd60475ce2d7304c3f7414/https:%2F%2Fcollectible.sweet.io%2Fseries%2F811%2F110%2Ftezos
            if (0 === strpos($data, '74657a6f732d73746f726167653a'))    return 'uri';   # tezos-storage:

            $unpacked = $this->token->unpackHex2Bin($data);

            if ($unpacked == null)
                return 'val';

            if (strlen($unpacked) == 46 && strpos($unpacked, 'Qm') === 0)
                return 'uri';

            [$scheme] = explode(':', $unpacked);

            if (in_array($scheme, ['ipfs', 'http', 'https', 'sha256', 'tezos-storage']))
                return 'uri';
        }

        return 'val';
    }
}
