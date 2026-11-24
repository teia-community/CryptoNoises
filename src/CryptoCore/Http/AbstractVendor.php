<?php

namespace CryptoCore\Http;

class AbstractVendor
{
    public static function GetData(string $url): SyncResult
    {
        return self::RequestData($url, 'GET');
    }

    public static function GetDataJson(string $url): SyncResult
    {
        $result = self::GetData($url);

        if ($result->notSuccess())
            return $result;

        $result->data = json_decode($result->data, true);
        return $result;
    }

    //------------------------------------------------------------------------------------------------------------------

    //------------------------------------------------------------------------------------------------------------------

    /** Sent with every outbound request; see CURLOPT_USERAGENT below for why it has to be there. */
    const kUserAgent = 'CryptoNoises/1.0 (+https://github.com/teia-community/CryptoNoises)';

    private static function RequestData(string $url, string $method, $postData=null, ?array $headers=null): SyncResult
    {
        $iteration = 1;
        $maxIterations = 3;

        $curlOptions = [
            CURLOPT_URL                         => $url,
            CURLOPT_HEADER                      => 0,
            CURLOPT_RETURNTRANSFER              => true,
            CURLOPT_FOLLOWLOCATION              => true,
            CURLOPT_TIMEOUT                     => 60,

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

            if ($iteration == $maxIterations)
                break;

            $iteration++;
            sleep(3);
        }
        return $result;
    }
}
