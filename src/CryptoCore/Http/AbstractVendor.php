<?php

namespace CryptoCore\Http;

class AbstractVendor
{
    /**
     * @param string[] $headers     extra request headers ("Name: value")
     * @param int $attempts         tries in all; only a transient failure is retried (see IsTransient())
     * @param int $timeoutSec       per try
     */
    public static function GetData(string $url, array $headers = [], int $attempts = 3, int $timeoutSec = 60): SyncResult
    {
        return self::RequestData($url, 'GET', null, $headers, $attempts, $timeoutSec);
    }

    public static function GetDataJson(string $url, array $headers = [], int $attempts = 3, int $timeoutSec = 60): SyncResult
    {
        $result = static::GetData($url, $headers, $attempts, $timeoutSec);

        if ($result->notSuccess())
            return $result;

        $result->data = json_decode($result->data, true);
        return $result;
    }

    //------------------------------------------------------------------------------------------------------------------

    /** Longest wait between tries, whatever a 429's Retry-After asks for: a worker must not hang on one request */
    const kMaxRetryWaitSec = 30;

    /**
     * Whether a failed answer may succeed when asked again: no answer at all (connection, timeout), a timeout or a
     * rate limit from the server, or a server error. Any other code - 400, 404, a 204 "nothing" - is the answer, and
     * asking again only adds load.
     */
    public static function IsTransient(?int $code): bool
    {
        return !$code || $code == 408 || $code == 429 || $code >= 500;
    }

    /**
     * Seconds to wait before the next try: what a 429 asks for (Retry-After, or RateLimit-Reset as TzKT Pro sends),
     * else 3, 6, 12... seconds; capped at kMaxRetryWaitSec.
     */
    private static function RetryWait(int $iteration, ?int $code, array $responseHeaders): int
    {
        $wait = 3 * 2 ** ($iteration - 1);

        if ($code == 429)
            foreach (['retry-after', 'ratelimit-reset'] as $name)
                if (isset($responseHeaders[$name]) && ctype_digit($responseHeaders[$name]))
                {
                    $wait = max(1, (int) $responseHeaders[$name]);
                    break;
                }

        return min($wait, self::kMaxRetryWaitSec);
    }

    //------------------------------------------------------------------------------------------------------------------

    /** Sent with every outbound request; see CURLOPT_USERAGENT below for why it has to be there. */
    const kUserAgent = 'CryptoNoises/1.0 (+https://github.com/teia-community/CryptoNoises)';

    private static function RequestData(string $url, string $method, $postData = null, array $headers = [],
                                        int $maxIterations = 3, int $timeoutSec = 60): SyncResult
    {
        $iteration = 1;
        $responseHeaders = [];

        $curlOptions = [
            CURLOPT_URL                         => $url,
            CURLOPT_HEADER                      => 0,
            CURLOPT_RETURNTRANSFER              => true,
            CURLOPT_FOLLOWLOCATION              => true,
            CURLOPT_TIMEOUT                     => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT              => min(10, $timeoutSec),

            // Accept every compression curl supports: TzKT pages of 10,000 items shrink about tenfold
            CURLOPT_ENCODING                    => '',

            // Response headers, lower-cased, for a 429's Retry-After
            CURLOPT_HEADERFUNCTION              => static function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':'))
                {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },

            // PHP's curl sends no User-Agent at all unless one is set, and services increasingly refuse that.
            // CoinGecko answers such a request with HTTP 403 and "Please add a descriptive User-Agent to your
            // request", which is why coin rates silently stopped: every call failed and the sync skipped the
            // coin. This identifies the project and nothing about whoever runs it.
            CURLOPT_USERAGENT                   => self::kUserAgent,

            CURLOPT_SSL_VERIFYHOST              => 2,
            CURLOPT_SSL_VERIFYPEER              => true,
        ];

        if ($method == 'POST')
        {
            $curlOptions[CURLOPT_POST]          = true;
            $curlOptions[CURLOPT_POSTFIELDS]    = $postData;
        }

        if ($headers)
        {
            $curlOptions[CURLOPT_HTTPHEADER]    = $headers;
        }

        while (true)
        {
            $responseHeaders = [];

            $ch = curl_init();
            curl_setopt_array($ch,$curlOptions);

            $data = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $result = new SyncResult();
            $result->url = $url;
            $result->postData = $postData;
            $result->iteration = $iteration;
            $result->fixResult($code, $data);

            if ($result->isSuccess())
                return $result;

            // On a stop, hand back the failure rather than sleeping and retrying through an outage: callers
            // already treat a failed SyncResult as an ordinary failure
            if ($iteration >= $maxIterations || !self::IsTransient($code) || \CryptoCore\Process\Shutdown::Requested())
                break;

            sleep(self::RetryWait($iteration, $code, $responseHeaders));
            $iteration++;
        }
        return $result;
    }
}
