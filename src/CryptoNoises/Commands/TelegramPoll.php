<?php

namespace CryptoNoises\Commands;

use CryptoNoises\Config;
use CryptoNoises\ControllersApi\TelegramBotController;
use CryptoNoises\Models;
use CryptoCore\Process\Shutdown;
use CryptoCore\Time;
use CryptoCore\Vss\Vss;
use TuriBot\Client;
use const TELEGRAM_BOT_API;

/**
 * Long-polls Telegram for the main bot's updates (getUpdates) and routes each one through the same code as the
 * webhook, TelegramBotController::HandleUpdate().
 *
 * This is for deployments Telegram cannot reach - a test stand, a laptop - since a webhook needs a public HTTPS
 * endpoint. Production keeps using the webhook. Telegram allows one or the other per bot (getUpdates is refused
 * with 409 while a webhook is set), so this refuses to poll a bot that has a webhook instead of deleting it: the
 * same token may well be the one a live deployment depends on.
 */
class TelegramPoll extends AbstractCommand
{
    // Under the 10 s transfer and inactivity timeouts of amphp's default HTTP client, which TuriBot uses, and under
    // Docker's 10 s stop grace period: a stop waits for one poll at most
    const kPOLL_TIMEOUT_SEC = 8;
    const kBATCH_LIMIT      = 100;
    const kERROR_PAUSE_SEC  = 5;

    // Next update_id to ask for, kept across restarts
    const kOFFSET_KEY       = 'telegram.poll.offset';

    public function poll(): void
    {
        $tsStart = Time::Microtime();

        set_time_limit(Config::runTimeLimit() + 60);

        if (!$this->checkLockAndWaitUntilNoOtherInstanceRuns('command-lock.telegram.poll', Config::kJOBS_TIMEOUT_DEFAULT))
        {
            $this->idle($tsStart);
            return;
        }

        if (TELEGRAM_BOT_API == '')
        {
            $this->logErr('TELEGRAM_BOT_API is not set: nothing to poll');
            $this->idle($tsStart);
            return;
        }

        Config::cfg()->initializeByPlatform(Config::kPLATFORM_Telegram);

        $client = new Client(TELEGRAM_BOT_API, false);

        $info = $client->getWebhookInfo();

        if (!empty($info->ok) && !empty($info->result->url))
        {
            $this->logErr('This bot has a webhook set, so Telegram delivers its updates there and refuses getUpdates. '
                . 'Not polling. Delete the webhook only if no deployment relies on it.');
            $this->idle($tsStart);
            return;
        }

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $stored  = Models\Config::model()->getValue(self::kOFFSET_KEY);
        $offset  = $stored !== null && $stored !== '' ? (int) $stored : null;
        $handled = 0;

        $this->logMsg('Polling for updates' . ($offset !== null ? " from update $offset" : ''), "\n", true);

        while (Time::Microtime() - $tsStart <= Config::runTimeLimit() && !Shutdown::Requested())
        {
            Vss::dbCloseAll();

            $response = $client->getUpdates($offset, self::kBATCH_LIMIT, self::kPOLL_TIMEOUT_SEC);

            if (empty($response->ok))
            {
                $this->logErr('getUpdates failed: ' . ($response->description ?? 'no answer'));
                sleep(self::kERROR_PAUSE_SEC);
                continue;
            }

            foreach ($response->result as $update)
            {
                $offset = $update->update_id + 1;

                // Stored before handling: an update that ends the process (Fat-Free turns any warning into a fatal
                // error) is then skipped on restart instead of being fetched and crashing the poller forever.
                // Telegram also forgets everything below the offset passed to the next getUpdates.
                Models\Config::model()->setValue(self::kOFFSET_KEY, (string) $offset);

                TelegramBotController::HandleUpdate(json_encode($update));
                $handled++;
            }
        }

        $this->logMsg("Done, $handled update(s) handled", "\n", true);
    }

    /**
     * Waits out the process lifetime instead of returning: an immediate exit would have "restart: always" start
     * the command again at once, in a loop.
     */
    private function idle(float $tsStart): void
    {
        while (Time::Microtime() - $tsStart <= Config::runTimeLimit() && !Shutdown::Requested())
            sleep(1);
    }
}
