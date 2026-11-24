<?php

namespace CryptoNoises\Bots\Threads;

use CryptoNoises\Models;
use Discord\Discord;
use Discord\Parts\Guild\Ban;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Role\Role;
use Discord\Parts\Part;
use Discord\WebSockets\Event;

trait DiscordMainLoopThread_GuildEvents
{
    public function attachOnGuildEvents(): void
    {
        $this->discord->on(Event::GUILD_CREATE, function (Guild $guild, Discord $discord)
        {
            Models\DiscordGuilds::model()->fixJoin($guild->id, $guild->name, $guild->member_count);
            self::LogGuildEvent('guild', 'create', $guild->id, null, $guild);
        });

        $this->discord->on(Event::GUILD_UPDATE, function (Guild $guild, Discord $discord, Guild $guildPrev)
        {
            Models\DiscordGuilds::model()->fixUpdate($guild->id, $guild->name, $guild->member_count);
            self::LogGuildEvent('guild', 'update', $guild->id, null, $guild, $guildPrev);
        });

        // $unavailable = true: a Discord outage, the bot is still on the server
        $this->discord->on(Event::GUILD_DELETE, function ($guild, bool $unavailable = false)
        {
            if ($unavailable)
                return;

            Models\DiscordGuilds::model()->fixLeave($guild->id);
            Models\UsersRedirects::model()->removeItemsByGuildId((int) $guild->id);

            // the bot left the server: its logged events (members, roles, bans) are not needed any more
            Models\Log\DiscordEvents::model()->removeItemsByGuildId((int) $guild->id);
        });

        $this->discord->on(Event::CHANNEL_DELETE, function ($channel)
        {
            if (isset($channel->id))
                Models\UsersRedirects::model()->removeItemByChannelId((int) $channel->id);
        });

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        // No GUILD_MEMBER_* handlers: they need the privileged GUILD_MEMBERS intent, which this thread does not request

        $this->discord->on(Event::GUILD_ROLE_CREATE, function (Role $role, Discord $discord)
        {
            self::LogGuildEvent('guild_role', 'create', $role->guild_id, $role->id, $role);
        });

        $this->discord->on(Event::GUILD_ROLE_UPDATE, function (Role $role, Discord $discord, Role $rolePrev)
        {
            self::LogGuildEvent('guild_role', 'update', $role->guild_id, $role->id, $role, $rolePrev);
        });

        $this->discord->on(Event::GUILD_ROLE_DELETE, function (Role $role, Discord $discord)
        {
            self::LogGuildEvent('guild_role', 'delete', $role->guild_id, $role->id, $role);
        });

        // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

        $this->discord->on(Event::GUILD_BAN_ADD, function (Ban $ban, Discord $discord)
        {
            self::LogGuildEvent('guild_ban', 'add', $ban->guild_id, $ban->user_id, $ban);
        });

        $this->discord->on(Event::GUILD_BAN_REMOVE, function (Ban $ban, Discord $discord)
        {
            self::LogGuildEvent('guild_ban', 'remove', $ban->guild_id, $ban->user_id, $ban);
        });
    }

    private static function LogGuildEvent(string $eventScope, string $eventAction,
                                          ?int $guildId, ?int $entityId,
                                          $entity, $entityPrev = null): void
    {
        Models\Log\DiscordEvents::model()->save([
            'event_scope'   => $eventScope,
            'event_action'  => $eventAction,
            'guild_id'      => $guildId,
            'entity_id'     => $entityId,
            'raw_data'      => self::PackRawData($entity),
            'raw_data_prev' => self::PackRawData($entityPrev),
        ]);
    }

    private static function PackRawData($entity)
    {
        if ($entity instanceof Part)
            return $entity->getRawAttributes();

        if ($entity instanceof \stdClass)
            return var_export($entity, true);

        return null;
    }
}
