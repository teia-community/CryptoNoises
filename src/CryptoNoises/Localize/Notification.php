<?php

namespace CryptoNoises\Localize;

use CryptoNoises\Bots\Core\SlashCommand;
use CryptoNoises\Config;
use CryptoNoises\Core\Log;
use CryptoNoises\Core\URL;
use CryptoNoises\Entities\CallbackAction;
use CryptoNoises\Entities\CoinExchange;
use CryptoNoises\Entities\User;
use CryptoNoises\Entities\UserWallet;
use CryptoNoises\Helpers\Helper;

// Markup:
//  - https://core.telegram.org/bots/api#html-style
//  - https://gist.github.com/matthewzring/9f7bbfd102003963f9be7dbcf7d40e51

// Limits:
//  - https://birdie0.github.io/discord-webhooks-guide/other/field_limits.html

abstract class Notification
{
    use Notification_Debug;
    use Notification_NftExtends;
    use Notification_Presets;

    const EMPTY_LINE                        = ' ';
    const HIDE_ME                           = '{###HIDE_ME###}';

    const COLOR_RED                         = 0xCC0000;
    const COLOR_GREEN                       = 0x00FF00;
    const COLOR_BLUE                        = 0x0058D1;
    const COLOR_YELLOW                      = 0xFFDA02;
    const COLOR_ORANGE                      = 0xFF8000;
    const COLOR_VIOLET                      = 0xAA26FF;
    const COLOR_WHITE                       = 0xFFFFFF;
    const COLOR_BLACK                       = 0x000000;

    //------------------------------------------------------------------------------------------------------------------
    // Discord Notifications params
    //
    // null = not decided yet (treated as false); every subclass should set true or false explicitly.

    public ?bool $discord_isAllowEphemeralResponse  = null;

    //------------------------------------------------------------------------------------------------------------------

    protected ?User $user                   = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private array $binds                    = [];

    // This will allow to send a sequence of notifications!
    public ?Notification $next              = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    protected string $plainText             = '';               # -         discord

    protected array $title                  = [];               # telegram  discord
    protected array $description            = [];               # telegram  discord
    protected array $fields                 = [];               # -         discord     *ps: key => lines[]
    protected bool  $isFieldsInline         = false;            # -         discord
    protected array $footer                 = [];               # telegram  discord

    /** @var NotificationButton[][]|null */
    protected array $buttons                = [];               # telegram  discord

    protected ?string $previewThumbnailUrl  = null;             # telegram  discord
    protected ?string $previewAnimationUrl  = null;             # -         discord
    protected ?string $previewMovieClipUrl  = null;             # telegram  -
    protected ?string $thumbnailUrl         = null;             # -         discord
    protected ?string $url                  = null;             # -         discord

    protected ?int $color                   = null;             # -         discord

    protected ?string $warning              = null;             # telegram  discord
    protected ?string $error                = null;             # telegram  discord

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function __construct(?User $user)
    {
        $this->user = $user;

        if (!self::IsDiscord() && !self::IsTelegram())
        {
            Log::Fatal('Notification', get_called_class(), "Unknown PLATFORM value", $this->getDebugStamp());
            throw new \RuntimeException(get_called_class().": Unknown PLATFORM value");
        }
    }

    //------------------------------------------------------------------------------------------------------------------
    // Helpers

    protected static function IsDiscord(): bool
    {
        return Config::cfg()->isDiscordPlatform();
    }

    /** Telegram, and Matrix, which renders the Telegram variant (Config::usesTelegramLayout()) */
    protected static function IsTelegram(): bool
    {
        return Config::cfg()->usesTelegramLayout();
    }

    /**
     * Escapes untrusted text (on-chain names, aliases, domains, user-defined names) for the current platform:
     * Telegram messages are sent with parse_mode=HTML, so "<a href=...>" in a name must not become a real link.
     */
    public static function SafeText(string $value): string
    {
        return self::IsTelegram() ? htmlspecialchars($value, ENT_QUOTES) : $value;
    }

    protected function fixAddressThreeDots(string $address): string
    {
        return str_replace('...', '…', $address);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getPlainText(): string
    {
        return $this->plainText;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getTitle(): string
    {
        return $this->prepareLines($this->title);
    }

    /**
     * @param array|string $title
     * @return $this
     */
    public function setTitle($title): self
    {
        $this->title = is_array($title) ? $title : [$title];
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getDescription(): string
    {
        return $this->prepareLines($this->description);
    }

    /**
     * @param array|string $description
     * @return $this
     */
    public function setDescription($description): self
    {
        $this->description = is_array($description) ? $description : [$description];
        return $this;
    }

    public function appendDescription(string $line): self
    {
        $this->description[] = $line;
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getFooter(): string
    {
        return $this->prepareLines($this->footer);
    }

    public function getFooterWithAttentions(): string
    {
        $footer = $this->prepareLines($this->footer);
        $footer = trim($footer);

        $warning = $this->getWarning();
        $warning = $warning ? "⚠️ ".$warning.PHP_EOL : '';

        $error = $this->getError();
        $error = $error ? "❌ ".$error.PHP_EOL : '';

        return trim($footer.PHP_EOL
            .self::EMPTY_LINE.PHP_EOL
            .$warning
            .$error);
    }

    /**
     * @param array|string $footer
     * @return $this
     */
    public function setFooter($footer): self
    {
        $this->footer = is_array($footer) ? $footer : [$footer];
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getFields(): array
    {
        $result = [];

        foreach ($this->fields as $key => $lines)
        {
            $value = $this->prepareLines($lines);

            if ($value != '')
                $result[$key] = $value;
        }

        return $result;
    }

    public function appendFieldValue(string $label, string $value): self
    {
        if (count($this->fields) >= 25)
        {
            Log::Warning(get_class($this), __FUNCTION__, "Too many fields for Notification", $this->getDebugStamp());
            return $this;
        }

        $this->fields[$label][] = $value;
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function setFieldsAsInline(bool $inline): self
    {
        $this->isFieldsInline = $inline;
        return $this;
    }

    public function isFieldsInline(): bool
    {
        return $this->isFieldsInline;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    // Note: counts rows before invalid buttons are filtered out by getButtons()
    public function getButtonsCount(): int
    {
        return count($this->buttons);
    }

    public function getButtons(): array
    {
        $result = [];

        foreach ($this->buttons as $buttonsInRow)
        {
            $newButtonsRow = [];

            foreach ($buttonsInRow as $button)
            {
                $newButton = clone $button;

                if ($button->type == NotificationButton::kTYPE_Link)
                {
                    if ($newButton->caption == '')
                    {
                        $newButton->caption = 'View';
                        Log::Error('Notification', 'getButtons', 'Button caption is empty; falling back to [View]', $this->getDebugStamp());
                    }

                    $newButton->url = $this->prepareValue($newButton->url);

                    if ($newButton->url == null)
                        continue;

                    if (preg_match('#^https://#iu', $newButton->url) == false)
                    {
                        Log::Error('Notification', 'getButtons', 'Button URL is not an https URL; button skipped', $this->getDebugStamp());
                        continue;
                    }
                }
                else
                {
                    if ($newButton->caption == '')
                    {
                        $newButton->caption = 'View';
                        Log::Error('Notification', 'getButtons', 'Button.any.caption is empty. Need set some value. Now use [View]', $this->getDebugStamp());
                    }
                }

                $newButtonsRow[] = $newButton;
            }

            if ($newButtonsRow)
                $result[] = $newButtonsRow;
        }

        return $result;
    }

    /**
     * Appends a row of link buttons; entries with empty url are skipped, an empty row is not added.
     *
     * @param array<string, ?string> $captionToUrl
     */
    public function appendLinkButtonsRow(array $captionToUrl): self
    {
        $buttons = [];

        foreach ($captionToUrl as $caption => $url)
        {
            if ($url !== null && $url !== '')
                $buttons[] = NotificationButton::Link((string) $caption, $url);
        }

        if (!empty($buttons))
            $this->buttons[] = $buttons;

        return $this;
    }

    public function appendButtonsRow(array $buttons): self
    {
        $this->buttons[] = $buttons;
        return $this;
    }

    public function prependButtonsRow(array $buttons): self
    {
        array_unshift($this->buttons, $buttons);
        return $this;
    }

    public function appendButtonToLastRow(NotificationButton $button, $maxButtonsInRow): self
    {
        if (count($this->buttons) == 0)
            $this->buttons[] = [];

        $lastIndex = count($this->buttons)-1;

        if (count($this->buttons[$lastIndex]) >= $maxButtonsInRow)
        {
            $this->buttons[] = [];
            $lastIndex++;
        }

        $this->buttons[$lastIndex][] = $button;
        return $this;
    }

    public function appendButtonsAsRows(array $buttons, int $rowLength): self
    {
        while (!empty($buttons))
        {
            $this->appendButtonsRow(array_splice($buttons, 0, $rowLength));
        }
        return $this;
    }

    /** Appends a line linking the operation(s) on an explorer; grouped events carry several hashes */
    public function addTransactionLinks(array $urls, int $max = 5): self
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if (empty($urls))
            return $this;

        $link = fn (string $caption, string $url) => self::IsTelegram()
            ? '<a href="'.htmlspecialchars($url, ENT_QUOTES).'">'.$caption.'</a>'
            : '['.$caption.']('.$url.')';

        if (count($urls) == 1)
        {
            $line = '🔗 '.$link('Transaction', $urls[0]);
        }
        else
        {
            $links = [];
            foreach (array_slice($urls, 0, $max) as $i => $url)
                $links[] = $link((string) ($i + 1), $url);

            $line = '🔗 Transactions: '.join(' · ', $links).(count($urls) > $max ? ' +'.(count($urls) - $max) : '');
        }

        $this->description[] = $line;
        return $this;
    }

    public function prependButtonsAsRows(array $buttons, int $rowLength): self
    {
        $rows = [];
        while (!empty($buttons))
        {
            $rows[] = array_splice($buttons, 0, $rowLength);
        }

        $rows = array_reverse($rows);

        foreach ($rows as $row)
        {
            $this->prependButtonsRow($row);
        }

        return $this;
    }

    public function appendButton_CancelLastAction(): self
    {
        $caBuilder = CallbackAction::Builder(CallbackAction::Scope_System_CancelLastAction);

        $this->appendButtonsRow([
            NotificationButton::CallbackAction(Phrase::I()->actionCancel, $caBuilder->newEventAuid()),
        ]);
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function appendPagerControl(CallbackAction $caBuilder, int $activePageIndex, int $itemsCount, int $itemsPerPage): self
    {
        $pagesInRow = 5;
        $pagesCount = ceil($itemsCount/$itemsPerPage);

        if ($pagesCount <= 1)
            return $this;

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($pagesCount <= $pagesInRow)
        {
            $pageIndexFirstInBlock  = 0;
            $pageIndexLastInBlock   = $pagesCount-1;
        }
        else
        {
            $pageIndexFirstInBlock  = $activePageIndex - 2;
            $pageIndexLastInBlock   = $activePageIndex + 2;

            while ($pageIndexFirstInBlock < 0)
            {
                $pageIndexFirstInBlock++;
                $pageIndexLastInBlock++;
            }
            while ($pageIndexLastInBlock > $pagesCount-1)
            {
                $pageIndexFirstInBlock--;
                $pageIndexLastInBlock--;
            }
        }

        $buttons = [];

        for ($idx=$pageIndexFirstInBlock; $idx<=$pageIndexLastInBlock; $idx++)
        {
            $caption = Phrase::PageIndex($idx+1, $idx == $activePageIndex);

            $button = NotificationButton::CallbackAction($caption, $caBuilder->newEventAuid('', ['page'=>$idx]));

            if ($idx == $activePageIndex)
                $button->setStylePrimary();
            else
                $button->setStyleSecondary();

            $buttons[] = $button;
        }
        $this->appendButtonsRow($buttons);

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        if ($pagesCount > $pagesInRow)
        {
            $pageIndexJumpLeft  = max($activePageIndex-ceil($pagesInRow/2), 0);
            $pageIndexJumpRight = min($activePageIndex+ceil($pagesInRow/2), $pagesCount-1);

            $buttons = [];

            $button = NotificationButton::CallbackAction("⇤", $caBuilder->newEventAuid('', ['page'=>0]));
            if ($activePageIndex == 0)
                $button->setStyleDisabled();
            $buttons[] = $button;

            $button = NotificationButton::CallbackAction("«", $caBuilder->newEventAuid('', ['page'=>$pageIndexJumpLeft]));
            if ($activePageIndex == 0)
                $button->setStyleDisabled();
            $buttons[] = $button;

            $button = NotificationButton::CallbackAction(($activePageIndex+1).'/'.$pagesCount, $caBuilder->newEventAuid('', ['page'=>$activePageIndex]));
            $button->setStyleDisabled();
            $buttons[] = $button;

            $button = NotificationButton::CallbackAction("»", $caBuilder->newEventAuid('', ['page'=>$pageIndexJumpRight]));
            if ($activePageIndex == $pagesCount-1)
                $button->setStyleDisabled();
            $buttons[] = $button;

            $button = NotificationButton::CallbackAction("⇥", $caBuilder->newEventAuid('', ['page'=>$pagesCount-1]));
            if ($activePageIndex == $pagesCount-1)
                $button->setStyleDisabled();
            $buttons[] = $button;

            $this->appendButtonsRow($buttons);
        }
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getUrl(): ?string
    {
        return $this->prepareValue($this->url);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getPreviewThumbnailUrl(): ?string
    {
        return $this->previewThumbnailUrl;
    }

    public function setPreviewThumbnailUrl(?string $previewThumbnailUrl): self
    {
        $this->previewThumbnailUrl = $previewThumbnailUrl;
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function hasPreviewAnimationUrl(): bool
    {
        return $this->previewAnimationUrl !== null;
    }

    public function getPreviewAnimationUrl(): ?string
    {
        return $this->previewAnimationUrl;
    }

    public function setPreviewAnimationUrl(?string $previewAnimationUrl): self
    {
        $this->previewAnimationUrl = $previewAnimationUrl;
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getPreviewMovieClipUrl(): ?string
    {
        return $this->previewMovieClipUrl;
    }

    public function setPreviewMovieClipUrl(?string $previewMovieClipUrl): self
    {
        $this->previewMovieClipUrl = $previewMovieClipUrl;
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getThumbnailUrl(): ?string
    {
        return $this->prepareValue($this->thumbnailUrl);
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getColor(): ?int
    {
        return $this->color;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getWarning(): ?string
    {
        return $this->warning;
    }

    public function setWarning(?string $warning): self
    {
        $this->warning = $warning;
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    //------------------------------------------------------------------------------------------------------------------

    protected function wrapCommand(string $slashCommand, bool $fillEndSpaces = false): string
    {
        // Matrix clients take "/word" as a client command, so the bot's commands start with "!"
        if (Config::cfg()->isMatrixPlatform())
        {
            return '<code>!'.$slashCommand.'</code>';
        }
        else if (self::IsTelegram())
        {
            return '/'.$slashCommand;
        }
        else if(self::IsDiscord())
        {
            $spaces = $fillEndSpaces ? str_repeat(' ', max(0, strlen(SlashCommand::DELETE_WALLET)-strlen($slashCommand))) : '';   // align to the longest command
            return '`'.('/'.$slashCommand).$spaces.'`';
        }
        return $slashCommand;
    }

    private function prepareLines(?array $lines): string
    {
        if ($lines === null)
            return '';

        $result = [];

        foreach ($lines as $line)
        {
            $line = $this->prepareValue($line);

            if ($line !== null)
                $result[] = $line;
        }

        return join(PHP_EOL, $result);
    }

    private function prepareValue(?string $value): ?string
    {
        if ($value === null)
            return null;

        $value = strtr($value, $this->binds);

        if ($value == self::EMPTY_LINE)                                         return ' ';

        if ($value == '')                                                       return null;
        if (strpos($value, self::HIDE_ME) !== false)                            return null;

        if (preg_match('|{###[A-Z0-9_]+###}|u', $value, $m))
        {
            Log::Warning(get_class($this), __FUNCTION__, "Found un-replaced const ".$m[0], $this->getDebugStamp(['prepareValue'=>$value]));
            return null;
        }

        return $value;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function bind($key, $value): self
    {
        $this->binds[$key] = $value;
        return $this;
    }

    public function binds($binds): self
    {
        $this->binds = array_merge($this->binds, $binds);
        return $this;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function bindWallet(?UserWallet $wallet): self
    {
        if ($wallet != null)
        {
            $amountBalance = $wallet->getBalance();

            $this->bind('{###WALLET_ICON###}',                      (string) $wallet->getIconName());
            $this->bind('{###WALLET_NAME###}',                      self::SafeText($wallet->getWalletName()));
            $this->bind('{###WALLET_ADDRESS###}',                   $wallet->getWalletAddress());
            $this->bind('{###WALLET_SHORT_ADDRESS###}',             $wallet->getWalletShortAddress());
            $this->bind('{###WALLET_PROFILE_URL###}',               $wallet->getProfileUrl());

            if ($amountBalance !== null)
            {
                $this->bind('{###WALLET_BALANCE###}',               $wallet->network()->getCoinsFriendlyShortValue($amountBalance));
                $this->bind('{###WALLET_BALANCE_CONVERTED###}',     CoinExchange::CreateByWallet($wallet)->convertToString($amountBalance));
            }
            else
            {
                $this->bind('{###WALLET_BALANCE###}',               Phrase::I()->syncing);
                $this->bind('{###WALLET_BALANCE_CONVERTED###}',     self::HIDE_ME);
            }
        }
        else
        {
            $this->bind('{###WALLET_ICON###}',                      '');
            $this->bind('{###WALLET_NAME###}',                      self::HIDE_ME);
            $this->bind('{###WALLET_ADDRESS###}',                   self::HIDE_ME);
            $this->bind('{###WALLET_SHORT_ADDRESS###}',             self::HIDE_ME);
            $this->bind('{###WALLET_PROFILE_URL###}',               self::HIDE_ME);
            $this->bind('{###WALLET_BALANCE###}',                   self::HIDE_ME);
            $this->bind('{###WALLET_BALANCE_CONVERTED###}',         self::HIDE_ME);
        }

        return $this;
    }

    public static function ConvertListToQueue(array $list): ?Notification
    {
        if (empty($list))
            return null;

        $main = null;
        $last = null;

        foreach ($list as $notification)
        {
            if ($main === null)
                $main = $notification;

            if ($last !== null)
                $last->next = $notification;

            $last = $notification;
        }

        return $main;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getFriendlyQuantityValue($quantity): string
    {
        if ($quantity == 0)
            return self::HIDE_ME;

        $quantity = number_format($quantity);

        return Helper::TrimRightZerosInNumber($quantity);
    }
}
