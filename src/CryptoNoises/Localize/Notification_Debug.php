<?php

namespace CryptoNoises\Localize;

use CryptoNoises\Config;
use CryptoNoises\Entities\Marketplace;
use CryptoNoises\Entities\Token;

trait Notification_Debug
{
    protected array $debugData = [];

    /** "Transaction\NftPurchased", "eventItem #644", ... - logged on development instances, see logDebugLine() */
    protected array $debugLine = [];

    public function getDebugStamp(array $appendData = []): array
    {
        return array_merge($this->debugData, $appendData);
    }

    public function bindDebugClassName(): self
    {
        $className = get_class($this);
        $this->debugData['className'] = $className;

        $this->debugLine[] = str_replace('CryptoNoises\\Localize\\Notification\\', '', $className);

        if (Config::cfg()->notificationDebugFooter)
        {
            $this->footer[] = Notification::EMPTY_LINE;
            $this->footer[] = end($this->debugLine);
        }
        return $this;
    }

    public function bindDebugArray(string $dataKey, ?array $data, ?string $attachKeyToNotification = null): self
    {
        $this->debugData[$dataKey] = $data;

        if ($attachKeyToNotification && isset($data[$attachKeyToNotification]))
        {
            $this->debugLine[] = "$dataKey #".$data[$attachKeyToNotification];

            if (Config::cfg()->notificationDebugFooter)
                $this->footer[] = end($this->debugLine);
        }

        return $this;
    }

    /**
     * On a development instance, one line per notification in the container log - what the message footer used to
     * carry, kept out of the message itself. A production instance logs nothing here.
     */
    public function logDebugLine(): self
    {
        if (Config::cfg()->isDevelopment() && $this->debugLine)
        {
            $line = gmdate('H:i:s').' notification '.join(' · ', $this->debugLine)."\n";

            if (defined('STDOUT'))
                fwrite(STDOUT, $line);
            else
                error_log(rtrim($line));
        }

        return $this;
    }

    public function bindDebugToken(string $dataKey, ?Token $token): self
    {
        if ($token)
        {
            $this->debugData[$dataKey] = (array) $token;
        }
        return $this;
    }

    public function bindDebugMarketplace(?Marketplace $marketplace): self
    {
        if ($marketplace)
            $this->debugData['marketplace'] = $marketplace->address;

        return $this;
    }
}
