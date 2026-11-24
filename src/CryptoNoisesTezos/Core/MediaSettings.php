<?php

namespace CryptoNoisesTezos\Core;

use CryptoCore\Env\Environment;

/**
 * Media preview settings (environment.php: tezos.media). Values go into ImageMagick / ffmpeg command lines,
 * so they are validated here: numbers are clamped, a bad bitrate falls back to the default.
 */
final class MediaSettings
{
    public int $thumbnailWidth;         // JPEG preview of images / first video frame, px
    public int $thumbnailQuality;       // JPEG quality, %

    public bool $videoPreview;          // mp4 preview for videos
    public int $videoWidth;             // px
    public string $videoBitrate;        // ffmpeg -b:v, e.g. "1M", "800k"

    public bool $animatedPreview;       // GIF preview for animations (when the token has a movie clip)
    public int $animatedWidth;          // px
    public int $animatedFps;

    public int $maxDurationSec;         // input video / animation is cut to this length
    public int $toolTimeoutSec;         // ImageMagick / ffmpeg run time limit (ffmpeg via coreutils timeout: tezos.cmd_timeout)

    /**
     * Days a generated preview is kept on disk; 0 keeps everything.
     *
     * This is not an archive: a preview exists so that a notification can carry an image. Telegram downloads
     * the file once when the message is sent and serves its own copy from then on, so the local file has done
     * its job as soon as the notification is delivered. Keeping every preview for the whole chain is not
     * feasible, and a missing preview degrades gracefully - see /jobs/clean/old-previews.
     */
    public int $previewRetentionDays;

    public static function FromEnvironment(): self
    {
        $s = new self();

        $s->thumbnailWidth      = self::Int('thumbnail_width', 256, 16, 4096);
        $s->thumbnailQuality    = self::Int('thumbnail_quality', 60, 1, 100);

        $s->videoPreview        = (bool) Environment::get('tezos.media.video_preview', false);
        $s->videoWidth          = self::Int('video_width', 512, 16, 4096);
        $bitrate                = (string) Environment::get('tezos.media.video_bitrate', '1M');
        $s->videoBitrate        = preg_match('/^\d{1,6}[kKmM]?$/', $bitrate) ? $bitrate : '1M';

        $s->animatedPreview     = (bool) Environment::get('tezos.media.animated_preview', false);
        $s->animatedWidth       = self::Int('animated_width', 240, 16, 4096);
        $s->animatedFps         = self::Int('animated_fps', 30, 1, 60);

        $s->maxDurationSec      = self::Int('max_duration_sec', 30, 1, 600);
        $s->toolTimeoutSec      = self::Int('tool_timeout_sec', 60, 5, 3600);

        // 0 = keep everything. The default does not delete anything; pruning is opt-in.
        $s->previewRetentionDays = self::Int('preview_retention_days', 0, 0, 36500);

        return $s;
    }

    private static function Int(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) Environment::get("tezos.media.$key", $default)));
    }
}
