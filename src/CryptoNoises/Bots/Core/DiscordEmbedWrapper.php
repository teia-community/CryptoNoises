<?php

namespace CryptoNoises\Bots\Core;

use Discord\Discord;
use Discord\Parts\Channel\Message\Embed\Embed;

class DiscordEmbedWrapper
{
    private Discord $discord;
    private ?Embed $embed = null;

    public function __construct(Discord $discord)
    {
        $this->discord = $discord;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    /*
     * Main idea: the embed is created lazily, only when something is set
     *  And if embed is empty -> no need to attach it to MessageBuilder
     */
    private function embedInstance(): Embed
    {
        if ($this->embed === null)
            $this->embed = new Embed($this->discord);

        return $this->embed;
    }

    public function getEmbed(): ?Embed
    {
        return $this->embed;
    }

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public function setTitleAndDescription(?string $title, ?string $descr): self
    {
        $title = trim((string) $title);
        $descr = trim((string) $descr);

        if ($title && $descr)
        {
            if (mb_strlen($title) > 200)
            {
                $this->embedInstance()->setDescription($title.PHP_EOL.$descr);
            }
            else
            {
                $this->embedInstance()->setTitle($title);
                $this->embedInstance()->setDescription($descr);
            }
        }
        else if ($title)
        {
            if (mb_strlen($title) > 200)
                $this->embedInstance()->setDescription($title);
            else
                $this->embedInstance()->setTitle($title);
        }
        else if ($descr)
        {
            $this->embedInstance()->setDescription($descr);
        }

        return $this;
    }

    public function setFooter(?string $footer): self
    {
        $footer = trim((string) $footer);

        if ($footer)
        {
            $embed = $this->embedInstance();
            $embed->setFooter($footer);

            // setFooter() always writes an "icon_url" key, null included, and Discord rejects a null icon_url.
            // Re-set the footer with the text only (this is what the manual vendor/ patch used to do).
            $embed->footer = ['text' => $footer];
        }

        return $this;
    }

    public function addField(?string $label, ?string $value, bool $isInline): self
    {
        $label = trim((string) $label);
        $value = trim((string) $value);

        if ($label && $value)
            $this->embedInstance()->addField([
                'name'   => $label,
                'value'  => $value,
                'inline' => $isInline,
            ]);

        return $this;
    }

    public function setUrl(?string $url): self
    {
        $url = trim((string) $url);

        if ($url)
            $this->embedInstance()->setURL($url);

        return $this;
    }

    public function setImage(?string $imageUrl): self
    {
        $imageUrl = trim((string) $imageUrl);

        if ($imageUrl)
            $this->embedInstance()->setImage($imageUrl);

        return $this;
    }

    public function setThumbnail(?string $thumbnail): self
    {
        $thumbnail = trim((string) $thumbnail);

        if ($thumbnail)
            $this->embedInstance()->setThumbnail($thumbnail);

        return $this;
    }

    public function setColor(?int $color): self
    {
        if ($color !== null)
            $this->embedInstance()->setColor($color);

        return $this;
    }
}
