<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use CryptoNoises\Models;
use CryptoNoises\Networks;

class CoinGecko extends AbstractCommand
{
    /**
     * How long to wait between passes. Rates do not move fast enough for a finer grain to be worth anything,
     * and the point is to stay well inside CoinGecko's free-tier allowance: a pass is one request per coin.
     */
    const kPASS_INTERVAL_SEC = 300;

    public function sync(): void
    {
        $tsStart = Time::Microtime();

        set_time_limit(Config::runTimeLimit() + Config::kJOBS_TIMEOUT_DEFAULT);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.coin-gecko.sync', Config::kJOBS_TIMEOUT_DEFAULT))
            return;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        // This runs as a long-lived service (bots-coingecko), so it syncs and waits rather than returning after
        // one pass. Returning made the container exit 0 and the restart policy start it again immediately, which
        // is a loop asking CoinGecko for the same handful of coins as fast as it can answer.
        while (true)
        {
            $this->syncOnce();

            if (Time::Microtime() - $tsStart > Config::runTimeLimit() || Shutdown::Requested())
                break;

            $this->logMsg("Sleep for ".self::kPASS_INTERVAL_SEC." sec");
            sleep(self::kPASS_INTERVAL_SEC);
        }

        $this->logMsg("Done");
    }

    private function syncOnce(): void
    {
        $coins = Models\CurrencyIn::model()->selectEnabled();

        foreach ($coins as $coinItem)
        {
            $this->logMsg("Sync: {$coinItem['currency_in_id']} / {$coinItem['coin_gecko_id']}");

            $data = Networks\Globals\CoinGecko::GetCoinDetails($coinItem['coin_gecko_id']);

            if ($data == null)
            {
                // Saying so matters: when every call fails, the only other symptom is coin rates rendering as
                // "?.???" in a notification, with nothing anywhere to explain why.
                $this->logErr("{$coinItem['coin_gecko_id']}: no answer from CoinGecko, skipped");
                continue;
            }

            if (!isset($data['market_data']['current_price']))
            {
                $this->logErr("{$coinItem['coin_gecko_id']}: no current_price data");
                continue;
            }

            foreach ($data['market_data']['current_price'] as $currencyId => $value)
            {
                $currencyInId   = $coinItem['currency_in_id'];
                $currencyOutId  = strtoupper($currencyId);

                $currencyItem = Models\CurrencyOut::model()->getItemById($currencyOutId);
                if ($currencyItem == null)
                {
                    Log::Error(get_class($this), __FUNCTION__, "CurrencyOut record does not exist [$currencyOutId] = [$value]");
                    continue;
                }

                Models\CurrencyValues::model()->save($currencyInId, $currencyOutId, $value);

                $this->logMsg("- $currencyInId > $currencyOutId > $value");
            }
        }
    }
}
