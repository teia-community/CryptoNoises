<?php

namespace CryptoCore\Process;

/**
 * An occasional progress line on stdout, so `docker compose logs` shows how far a sync or an analyzer has got.
 *
 * The commands' own logMsg() only prints in verbose mode, which is right for their per-level chatter but leaves
 * the logs empty while everything works - there was no way to see from the outside how a catch-up was going
 * short of querying the fixer tables. This prints one line per interval instead:
 *
 *   21:58:04 sync operations/transactions: level 1,376,918 · +9,214 in 60s (154/s) · head 15,958,567, 14,581,649 behind, ETA 1d2h
 *
 * Once a command has caught up and stops moving it falls back to one line every kIDLE_FACTOR intervals, as a
 * heartbeat rather than noise. PROGRESS_LOG_INTERVAL_SEC sets the interval; 0 turns the lines off.
 */
final class ProgressLog
{
    const kINTERVAL_SEC = 60;
    const kIDLE_FACTOR  = 10;

    private string $label;
    private int $intervalSec;

    private ?int $levelLast = null;
    private float $tsLast   = 0.0;

    public function __construct(string $label, ?int $intervalSec = null)
    {
        $this->label = $label;

        if ($intervalSec === null)
        {
            $env = getenv('PROGRESS_LOG_INTERVAL_SEC');
            $intervalSec = ($env === false || $env === '') ? self::kINTERVAL_SEC : (int) $env;
        }

        $this->intervalSec = max(0, $intervalSec);
    }

    /**
     * Call as often as convenient - it prints at most once per interval.
     *
     * @param int                    $level      last level that is fully done
     * @param int|null|\Closure       $target     the level being chased: the chain head for a sync, the synced level
     *                                            for an analyzer. A Closure returning ?int is only called when a line
     *                                            may be printed, for a target that is costly to keep fresh per level.
     * @param string                  $targetName what $target is, for the line ("head", "synced")
     */
    public function report(int $level, int|null|\Closure $target = null, string $targetName = 'head'): void
    {
        if ($this->intervalSec == 0)
            return;

        $now = microtime(true);

        if ($this->levelLast === null)
        {
            $target = $target instanceof \Closure ? $target() : $target;

            $this->levelLast = $level;
            $this->tsLast    = $now;
            $this->write('starting at level ' . number_format($level)
                . ($target !== null ? ' · ' . $targetName . ' ' . number_format($target) : ''));
            return;
        }

        $elapsed = $now - $this->tsLast;

        if ($elapsed < $this->intervalSec)
            return;

        $target   = $target instanceof \Closure ? $target() : $target;
        $done     = $level - $this->levelLast;
        $caughtUp = $target !== null && $level >= $target;

        $wait = ($done == 0 && $caughtUp) ? $this->intervalSec * self::kIDLE_FACTOR : $this->intervalSec;

        if ($elapsed < $wait)
            return;

        $rate  = $elapsed > 0 ? $done / $elapsed : 0.0;
        $parts = ['level ' . number_format($level)];
        $parts[] = sprintf('+%s in %ds (%s/s)', number_format($done), (int) round($elapsed),
            $rate >= 10 ? number_format($rate) : number_format($rate, 1));

        if ($target !== null)
        {
            $behind = $target - $level;

            if ($behind <= 0)
                $parts[] = 'caught up with ' . $targetName . ' ' . number_format($target);
            else
                $parts[] = $targetName . ' ' . number_format($target) . ', ' . number_format($behind) . ' behind'
                    . ($rate > 0 ? ', ETA ' . self::Duration($behind / $rate) : '');
        }

        $this->write(join(' · ', $parts));

        $this->levelLast = $level;
        $this->tsLast    = $now;
    }

    private function write(string $line): void
    {
        // Straight to stdout: the commands' logMsg() is silent unless verbose, which is the point of this class
        fwrite(STDOUT, gmdate('H:i:s') . ' ' . $this->label . ': ' . $line . "\n");
    }

    public static function Duration(float $seconds): string
    {
        $seconds = (int) round($seconds);

        if ($seconds < 60)
            return $seconds . 's';

        if ($seconds < 3600)
            return intdiv($seconds, 60) . 'm';

        if ($seconds < 86400)
            return intdiv($seconds, 3600) . 'h' . sprintf('%02d', intdiv($seconds % 3600, 60)) . 'm';

        return intdiv($seconds, 86400) . 'd' . intdiv($seconds % 86400, 3600) . 'h';
    }
}
