<?php

namespace CryptoNoisesTezos\Vendors;

use CryptoCore\Process\Shutdown;
use CryptoNoisesTezos\Config;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use Throwable;

/**
 * TzKT's head, pushed over its WebSocket API (SignalR, JSON protocol) instead of polled - what TzKT recommends for
 * real-time data. A sync that has caught up waits here for the next block: it learns of it within a fraction of a
 * second, and asks TzKT nothing while it waits.
 *
 * Optional by design: when the socket cannot be opened or drops, waitForHeadAbove() answers null and the caller polls
 * /head as before; the socket is tried again kRETRY_AFTER_SEC later. The connection is opened on the first wait and
 * kept between waits; SignalR closes a client it has not heard from for 30 s, so a periodic ping goes out while
 * waiting, and a connection that closed between waits is simply opened again.
 *
 * SignalR's JSON protocol: every message is JSON ended by the record separator 0x1E. The client opens with
 * {"protocol":"json","version":1}, the server answers {}. An invocation (type 1) of SubscribeToHead gets a completion
 * (type 3) and then `head` invocations, whose argument is {type: 0 = state, 1 = data, 2 = reorg; state: level; data}.
 * Type 6 is a ping, type 7 a close.
 */
final class TzktHeadStream
{
    const kCONNECT_TIMEOUT_SEC  = 5;
    const kRETRY_AFTER_SEC      = 60;
    const kPING_EVERY_SEC       = 10;
    const kIDLE_REOPEN_SEC      = 25;           // SignalR drops a client it has not heard from for 30 s
    const RS                    = "\x1e";

    private ?WebSocket $conn = null;
    private ?int $head = null;
    private string $buffer = '';
    private int $failedAt = 0;
    private bool $wasDown = false;
    private int $lastWaitAt = 0;

    /** @var callable(string): void */
    private $log;

    public function __construct(callable $log)
    {
        $this->log = $log;
    }

    /** ws(s)://.../v1/ws from the TzKT API URL (.../v1) */
    public static function Url(): string
    {
        return preg_replace('#^http#', 'ws', Config::cfg()->tzktApiUrl).'/ws';
    }

    /**
     * Waits until TzKT's head is above $level, at most $timeoutSec, and returns the head level then - which may still
     * be $level or below on a timeout. Null when the socket is not available: the caller polls instead.
     */
    public function waitForHeadAbove(int $level, float $timeoutSec): ?int
    {
        // Not waited on for a while (the sync was busy catching up): the server has likely dropped it, so open a
        // new one rather than find out by a failure
        if ($this->conn !== null && time() - $this->lastWaitAt > self::kIDLE_REOPEN_SEC)
            $this->close();

        if ($this->conn === null && !$this->connect())
            return null;

        $this->lastWaitAt = time();

        if ($this->head !== null && $this->head > $level)
            return $this->head;

        $loop  = Loop::get();
        $done  = false;
        $stop  = function () use (&$done, $loop) { $done = true; $loop->stop(); };

        $onHead = function (int $head) use ($level, $stop) {
            if ($head > $level)
                $stop();
        };

        $this->onHead = $onHead;
        $this->onClose = $stop;

        $timeout = $loop->addTimer($timeoutSec, $stop);
        $ping    = $loop->addPeriodicTimer(self::kPING_EVERY_SEC, fn () => $this->send(['type' => 6]));
        $signals = $loop->addPeriodicTimer(1, function () use ($stop) {
            if (Shutdown::Requested())
                $stop();
        });

        $loop->run();

        foreach ([$timeout, $ping, $signals] as $timer)
            $loop->cancelTimer($timer);

        $this->lastWaitAt = time();

        $this->onHead = $this->onClose = null;

        if ($this->conn === null)
            return $this->down('the socket closed');

        return $this->head;
    }

    public function close(): void
    {
        $conn = $this->conn;
        $this->conn = null;
        $conn?->close();
    }

    //------------------------------------------------------------------------------------------------------------------

    /** @var ?callable(int): void */
    private $onHead = null;
    /** @var ?callable(): void */
    private $onClose = null;

    /** Opens the socket and subscribes to the head; false (and polling for kRETRY_AFTER_SEC) when that fails */
    private function connect(): bool
    {
        if (time() - $this->failedAt < self::kRETRY_AFTER_SEC)
            return false;

        $loop       = Loop::get();
        $subscribed = false;
        $error      = null;

        $headers = Config::cfg()->tzktApiKey !== '' ? ['apikey' => Config::cfg()->tzktApiKey] : [];
        $headers['User-Agent'] = \CryptoCore\Http\AbstractVendor::kUserAgent;

        \Ratchet\Client\connect(self::Url(), [], $headers, $loop)->then(
            function (WebSocket $conn) use (&$subscribed, $loop) {
                $this->conn   = $conn;
                $this->buffer = '';
                $this->head   = null;

                $conn->on('message', function ($message) use (&$subscribed, $loop) {
                    if ($this->receive((string) $message) && !$subscribed)
                    {
                        $subscribed = true;
                        $loop->stop();
                    }
                });

                $conn->on('close', function () use (&$subscribed, $loop) {
                    $this->conn = null;

                    if (!$subscribed)
                        $loop->stop();

                    ($this->onClose)?->__invoke();
                });

                $this->send(['protocol' => 'json', 'version' => 1]);
            },
            function (Throwable $e) use (&$error, $loop) {
                $error = $e->getMessage();
                $loop->stop();
            }
        );

        $timeout = $loop->addTimer(self::kCONNECT_TIMEOUT_SEC, fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($timeout);

        if ($this->conn === null || !$subscribed)
        {
            $this->close();
            $this->down($error ?? 'no head within '.self::kCONNECT_TIMEOUT_SEC.' s');
            return false;
        }

        if ($this->wasDown)
            ($this->log)("TzKT WebSocket: connected again, head {$this->head}");

        $this->wasDown = false;
        return true;
    }

    /** Logs the first failure of a run of them, and polls for kRETRY_AFTER_SEC */
    private function down(string $reason): ?int
    {
        $this->conn     = null;
        $this->failedAt = time();

        if (!$this->wasDown)
            ($this->log)("TzKT WebSocket: $reason; polling /head, retrying every ".self::kRETRY_AFTER_SEC." s");

        $this->wasDown = true;
        return null;
    }

    private function send(array $message): void
    {
        $this->conn?->send(json_encode($message).self::RS);
    }

    /**
     * Handles what arrived; true once the head is known (the subscription's first state message). A WebSocket
     * message may carry several SignalR messages, and in principle end inside one.
     */
    private function receive(string $data): bool
    {
        $this->buffer .= $data;
        $frames = explode(self::RS, $this->buffer);
        $this->buffer = array_pop($frames);

        foreach ($frames as $frame)
        {
            $message = json_decode($frame, true);

            if ($message === [])                                            // handshake answered
                $this->send(['type' => 1, 'invocationId' => '0', 'target' => 'SubscribeToHead', 'arguments' => []]);
            else if (($message['type'] ?? null) === 7)                      // the server closes
                $this->close();
            else if (($message['type'] ?? null) === 1 && ($message['target'] ?? null) === 'head')
            {
                // state (0), data (1) or reorg (2): `state` is the head level in all three
                $state = $message['arguments'][0]['state'] ?? null;

                if (is_int($state))
                {
                    $this->head = $state;
                    ($this->onHead)?->__invoke($state);
                }
            }
        }

        return $this->head !== null;
    }
}
