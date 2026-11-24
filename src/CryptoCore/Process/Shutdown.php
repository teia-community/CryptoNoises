<?php

namespace CryptoCore\Process;

/**
 * Cooperative stop handling (SIGQUIT / SIGTERM / SIGINT) for the long-running commands.
 *
 * Why this is needed at all: in a container the command is PID 1 (docker/bin/entrypoint.sh execs it, through
 * setpriv when privileges are dropped), and the kernel does NOT apply default signal dispositions to PID 1.
 * A signal is only delivered to PID 1 if that process installed a handler for it, so a PHP worker that handles
 * nothing silently ignores its stop signal: `docker compose stop` - and with it every restart, recreate and
 * reboot - waits out the stop grace period and then SIGKILLs the container, which exits 137. Any batch that was
 * open is rolled back and replayed on the next run.
 *
 * Which signal that is matters, and it is not SIGTERM: the runtime image is built on php-fpm, whose image sets
 * STOPSIGNAL SIGQUIT (fpm's graceful shutdown), and every service inherits it. So `docker stop` sends SIGQUIT,
 * while `docker kill -s TERM`, podman, Kubernetes and Ctrl+C send SIGTERM or SIGINT. All three are handled the
 * same way; none of them means anything else to a CLI process. Handling only SIGTERM was tested and looked
 * right with `docker kill -s TERM` while `docker stop` still ended in SIGKILL.
 *
 * Installing a handler is what makes the signal arrive. The workers then stop where stopping is free, between
 * batches and between levels, and exit 0.
 *
 * ext-pcntl is required to install the handler. Without it Requested() simply stays false, which is exactly the
 * behaviour before this class existed, so nothing here is load-bearing for correctness.
 */
final class Shutdown
{
    private static bool $installed = false;
    private static bool $requested = false;
    private static ?int $signal    = null;

    /**
     * Installs the handler once. Safe to call from anywhere, including processes that never check Requested().
     */
    public static function Install(): void
    {
        if (self::$installed)
            return;

        self::$installed = true;

        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal'))
            return;

        // Asynchronous delivery: the handler runs between VM instructions, so no pcntl_signal_dispatch() call
        // has to be sprinkled through the loops. The handler only sets a flag - it must not touch the database
        // or the open transaction, which is why the loops do the actual stopping.
        pcntl_async_signals(true);

        $handler = static function (int $signo): void
        {
            self::$requested = true;
            self::$signal    = $signo;
        };

        // SIGQUIT first: it is what `docker stop` sends to this image (see the class comment)
        pcntl_signal(SIGQUIT, $handler);
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    public static function IsAvailable(): bool
    {
        return function_exists('pcntl_async_signals') && function_exists('pcntl_signal');
    }

    /**
     * True once a stop was asked for. Check it where the work can be left cleanly.
     */
    public static function Requested(): bool
    {
        return self::$requested;
    }

    public static function SignalName(): string
    {
        if (self::$signal === null)
            return 'none';

        if (defined('SIGQUIT') && self::$signal === SIGQUIT)
            return 'SIGQUIT';

        if (defined('SIGTERM') && self::$signal === SIGTERM)
            return 'SIGTERM';

        if (defined('SIGINT') && self::$signal === SIGINT)
            return 'SIGINT';

        return 'signal ' . self::$signal;
    }

    /**
     * For tests: forget that a stop was asked for.
     */
    public static function Reset(): void
    {
        self::$requested = false;
        self::$signal    = null;
    }
}
