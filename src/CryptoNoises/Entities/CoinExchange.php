<?php

namespace CryptoNoises\Entities;

use CryptoNoises\Core\Log;
use CryptoNoises\Models;

class CoinExchange
{
    protected int $userId;
    protected string $currency;
    protected ?array $quote = null;

    private static array $quotes = [];

    private function __construct($userId, $currency)
    {
        $this->userId = $userId;
        $this->currency = $currency;
    }

    public static function Create($userId, $currency): CoinExchange
    {
        return new CoinExchange($userId, $currency);
    }

    public static function CreateByWallet(UserWallet $wallet): CoinExchange
    {
        return self::Create($wallet->getUserId(), $wallet->getCurrency());
    }

    /**
     * Converts XTZ at the price of that level (TzKT quotes) instead of today's. Fiat currencies TzKT has no quote
     * for follow the USD quote; other coins keep today's rate.
     */
    public function atLevel(?int $level): self
    {
        if ($level > 0 && $this->currency == 'XTZ')
        {
            if (!array_key_exists($level, self::$quotes))
            {
                if (count(self::$quotes) > 100)
                    self::$quotes = [];

                self::$quotes[$level] = \CryptoNoisesTezos\Vendors\Tzkt::GetQuote($level);
            }

            $this->quote = self::$quotes[$level];
        }

        return $this;
    }

    private function quotedValue(string $currencyOutId, float $value): float
    {
        $key = strtolower($currencyOutId);

        if (isset($this->quote[$key]))
            return (float) $this->quote[$key];

        $usdNow = Models\CurrencyValues::model()->getItemByIds($this->currency, 'USD')['value'] ?? 0;

        if ($usdNow > 0 && (Models\CurrencyOut::model()->getItemById($currencyOutId)['type'] ?? null) == 'fiat')
            return $value * $this->quote['usd'] / $usdNow;

        return $value;
    }

    /**
     * @return Coin[]
     */
    protected function convertToValues($amount): array
    {
        $currencyInId = $this->currency;
        $results = [];

        if ($currencyInId == '')
            return $results;

        $currencyOutIds = Models\UsersCurrencyConvert::model()->selectIds($this->userId);

        $coinItem = Models\CurrencyIn::model()->getItem($currencyInId);

        foreach ($currencyOutIds as $currencyOutId)
        {
            $coinsValue = Models\CurrencyValues::model()->getItemByIds($currencyInId, $currencyOutId);

            if ($coinsValue == null)
            {
                Log::Warning(get_class($this), __FUNCTION__, "Failed to get CoinsValue for pair [$currencyInId]-[$currencyOutId]", [
                    'userId' => $this->userId,
                    'currencyOutIds' => $currencyOutIds,
                ]);
                continue;
            }

            $value = $this->quote !== null ? $this->quotedValue($currencyOutId, (float) $coinsValue['value']) : $coinsValue['value'];

            $newAmount = $amount * $value * $coinItem['exchange_normalize_mult'] / $coinItem['exchange_normalize_div'];

            $results[$currencyOutId] = new Coin($newAmount, $currencyOutId);
        }

        return $results;
    }

    public function convertToString($amount): string
    {
        if ($amount == 0)
            return '';

        $converts = $this->convertToValues($amount);

        if (empty($converts))
            return '';

        $results = [];

        foreach ($converts as $coin)
        {
            $results[] = $coin->getFriendlyValue();
        }

        return '('.join(' / ',$results).')';
    }
}
