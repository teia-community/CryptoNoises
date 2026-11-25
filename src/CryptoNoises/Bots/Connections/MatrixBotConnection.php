<?php

namespace CryptoNoises\Bots\Connections;

use CryptoNoises\Bots\Core\NotificationSendFailedException;
use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\NotificationButton;
use CryptoNoises\Models;
use CryptoNoisesTezos\Core\Http\SafeCurl;
use Throwable;

/**
 * Sends notifications to one Matrix user, in the direct-message room the bot shares with them.
 *
 * Notifications are rendered from the Telegram variant of each template (Config::usesTelegramLayout()): Telegram's
 * HTML subset is valid Matrix HTML, once line breaks become <br>. Link buttons become links under the message;
 * callback buttons (menus) have no Matrix counterpart and are left out - the text commands cover what the bot does
 * on Matrix. A preview image is uploaded to the media repository and sent as an m.image with the text as its caption.
 */
class MatrixBotConnection
{
    const kDELIVERY_RETRY_DELAY             = 3;    // seconds
    const kPACE_ROOM_SEC                    = 1.05; // notifier path, per room; the client waits out 429s on top
    const kMAX_IMAGE_BYTES                  = 8 * 1024 * 1024;

    public User $user;
    public ?string $roomId = null;
    public ?string $matrixUserId = null;

    private ?string $lastSendError = null;

    /** @var array<string, float> room => when the last message went to it (this process) */
    private static array $lastSentAt = [];

    /** @var array<string, array> image URL => uploaded m.image fields, per process */
    private static array $uploaded = [];

    //------------------------------------------------------------------------------------------------------------------

    /** A known user, by users.user_id (the notifier) */
    public function initUserById(int $userId): bool
    {
        $user = User::GetByUserId($userId);

        if ($user === null)
            return false;

        $matrix = Models\MatrixUsers::model()->getByRef((int) $user->platformUserId);

        if ($matrix === null || empty($matrix['room_id']))
            return false;

        $this->user         = $user;
        $this->roomId       = $matrix['room_id'];
        $this->matrixUserId = $matrix['matrix_user_id'];
        return true;
    }

    /** The user writing in a room (the sync loop): created on first contact, and that room becomes theirs */
    public function initUserByMatrixIds(string $matrixUserId, string $roomId): bool
    {
        $ref  = Models\MatrixUsers::model()->getOrCreateRef($matrixUserId, $roomId);
        $user = User::GetOrCreatePlatformUser(Config::kPLATFORM_Matrix, $ref, $ref);

        if ($user === null)
            return false;

        $this->user         = $user;
        $this->roomId       = $roomId;
        $this->matrixUserId = $matrixUserId;
        return true;
    }

    //------------------------------------------------------------------------------------------------------------------

    /** Interactive path: answers to the user's own command, not paced */
    public function sendBotNotifications(?Notification $notification): void
    {
        for (; $notification !== null; $notification = $notification->next)
            $this->sendNotification($notification, false);
    }

    /**
     * Notifier path: paced per room, and a failed message is sent once more after a short delay.
     *
     * @throws NotificationSendFailedException
     */
    public function deliverBotNotifications(?Notification $notification): void
    {
        for (; $notification !== null; $notification = $notification->next)
        {
            if (!$this->sendNotification($notification, true))
            {
                sleep(self::kDELIVERY_RETRY_DELAY);

                if (!$this->sendNotification($notification, true))
                    throw new NotificationSendFailedException('Matrix: '.($this->lastSendError ?? 'unknown error'));
            }
        }
    }

    private function sendNotification(Notification $notification, bool $paced): bool
    {
        if ($notification instanceof Notification\System_Skip || $notification instanceof Notification\System_Delete)
            return true;

        $this->lastSendError = null;

        try
        {
            [$html, $imageUrl] = $this->buildMessage($notification);

            if (trim(strip_tags($html)) === '' && $imageUrl === null)
                return true;

            if ($paced)
                $this->waitForSendSlot();

            $content = null;

            if ($imageUrl !== null)
            {
                $image = $this->uploadImage($imageUrl);

                if ($image !== null)
                    $content = $image + self::Caption($html);
            }

            $content ??= ['msgtype' => 'm.text'] + self::Text($html);

            MatrixClient::I()->sendMessage($this->roomId, $content);
            self::$lastSentAt[$this->roomId] = microtime(true);

            return true;
        }
        catch (Throwable $e)
        {
            $this->lastSendError = $e->getMessage();

            // Not in the room any more (the user left the DM): stop notifying them, as for a blocked Telegram bot
            if (str_contains($e->getMessage(), 'M_FORBIDDEN'))
                $this->user->deactivate('Matrix: '.$e->getMessage());

            Log::Error(get_class($this), __FUNCTION__, $e->getMessage());
            return false;
        }
    }

    private function waitForSendSlot(): void
    {
        $wait = (self::$lastSentAt[$this->roomId] ?? 0) + self::kPACE_ROOM_SEC - microtime(true);

        if ($wait > 0)
            usleep((int) ($wait * 1e6));
    }

    //------------------------------------------------------------------------------------------------------------------
    // Message

    /** @return array{string, ?string} the message as Matrix HTML, and the preview image URL if any */
    private function buildMessage(Notification $notification): array
    {
        $blocks = array_filter([
            $notification->getTitle(),
            $notification->getDescription(),
            $notification->getFooterWithAttentions(),
        ], fn ($block) => trim((string) $block) !== '');

        $text = join("\n".Notification::EMPTY_LINE."\n", $blocks);

        // Link buttons as links, one row per line
        $rows = [];

        foreach ($notification->getButtons() as $buttonsInRow)
        {
            $links = [];

            foreach ($buttonsInRow as $button)
                if ($button->type == NotificationButton::kTYPE_Link && preg_match('#^https?://#', (string) $button->url))
                    $links[] = '<a href="'.htmlspecialchars($button->url, ENT_QUOTES).'">'.htmlspecialchars($button->caption, ENT_QUOTES).'</a>';

            if ($links)
                $rows[] = join(' · ', $links);
        }

        if ($rows)
            $text .= "\n".Notification::EMPTY_LINE."\n".join("\n", $rows);

        return [self::TelegramHtmlToMatrix($text), $notification->getPreviewThumbnailUrl()];
    }

    /** Telegram's HTML subset as Matrix HTML: line breaks, spoilers and strikethrough differ */
    public static function TelegramHtmlToMatrix(string $text): string
    {
        $text = str_replace(['<tg-spoiler>', '</tg-spoiler>', '<s>', '</s>'], ['<span data-mx-spoiler>', '</span>', '<del>', '</del>'], $text);

        return str_replace("\n", "<br>\n", trim($text));
    }

    /** A plain-text fallback of Matrix HTML, for clients that do not render HTML */
    public static function PlainText(string $html): string
    {
        $text = preg_replace('#<a href="([^"]*)">(.*?)</a>#s', '$2 ($1)', $html);
        $text = preg_replace('#<br\s*/?>#i', '', $text);

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    }

    private static function Text(string $html): array
    {
        return ['body' => self::PlainText($html), 'format' => 'org.matrix.custom.html', 'formatted_body' => $html];
    }

    /** A media caption (MSC2530): the body is the caption, the file name says what the file is */
    private static function Caption(string $html): array
    {
        return trim(strip_tags($html)) === '' ? [] : self::Text($html);
    }

    //------------------------------------------------------------------------------------------------------------------
    // Images

    /** @return array|null m.image fields (msgtype, url, filename, info), or null when the image cannot be had */
    private function uploadImage(string $imageUrl): ?array
    {
        if (isset(self::$uploaded[$imageUrl]))
            return self::$uploaded[$imageUrl];

        try
        {
            $bytes = self::ReadImage($imageUrl);

            if ($bytes === null || $bytes === '')
                return null;

            $mimetype = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';

            if (!str_starts_with($mimetype, 'image/'))
                return null;

            $filename = basename(parse_url($imageUrl, PHP_URL_PATH) ?: 'preview') ?: 'preview';
            $size     = @getimagesizefromstring($bytes) ?: [null, null];

            $info = ['mimetype' => $mimetype, 'size' => strlen($bytes)];

            if ($size[0])
                $info += ['w' => $size[0], 'h' => $size[1]];

            return self::$uploaded[$imageUrl] = [
                'msgtype'   => 'm.image',
                'url'       => MatrixClient::I()->upload($bytes, $mimetype, $filename),
                'filename'  => $filename,
                'info'      => $info,
            ];
        }
        catch (Throwable $e)
        {
            Log::Warning(self::class, __FUNCTION__, "No preview for Matrix ($imageUrl): ".$e->getMessage());
            return null;
        }
    }

    /** Previews this install serves are read from disk; anything else is downloaded like any untrusted URL */
    private static function ReadImage(string $url): ?string
    {
        $localBase = rtrim(\CryptoNoisesTezos\Config::cfg()->ipfsLocalBaseUrl, '/');
        $localPath = rtrim(\CryptoNoisesTezos\Config::cfg()->ipfsLocalBasePath, '/');

        if ($localBase !== '' && $localPath !== '' && str_starts_with($url, $localBase.'/'))
        {
            $relative = rawurldecode(substr(parse_url($url, PHP_URL_PATH) ?? '', strlen((string) parse_url($localBase, PHP_URL_PATH))));
            $file     = realpath($localPath.'/'.ltrim($relative, '/'));

            if ($file !== false && str_starts_with($file, realpath($localPath).'/') && filesize($file) <= self::kMAX_IMAGE_BYTES)
                return file_get_contents($file);
        }

        $response = SafeCurl::Fetch($url, 20, self::kMAX_IMAGE_BYTES);

        return $response->isOk() ? $response->body : null;
    }
}
