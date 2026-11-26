<?php

namespace CryptoNoises\Bots\Gates;

use CryptoNoises\Entities\OptionsNotifier;
use CryptoNoises\Entities\User;
use CryptoNoises\Localize\Notification;
use CryptoNoises\Localize\Phrase;

/**
 * User-level settings as text commands (Matrix has no bot buttons). Per-wallet filters stay on Telegram/Discord.
 */
trait MatrixBotGate_Settings
{
    /** @return Notification|null null when the command is not a settings command */
    protected function settingsCommand(User $user, string $word, ?string $query): ?Notification
    {
        $options = $user->options();
        $args    = preg_split('/\s+/', strtolower(trim((string) $query)), -1, PREG_SPLIT_NO_EMPTY);

        return match ($word) {
            'settings'      => $this->settingsSummary($options),
            'on', 'off'     => $this->settingsToggle($options, $word === 'on', $args[0] ?? ''),
            'digest'        => $this->settingsPick($options, $args[0] ?? '', ['off' => 0, '15m' => 15, '1h' => 60, '3h' => 180],
                                    fn ($v) => $options->setDigestMinutes($v), 'Digest', fn () => Phrase::Digest($options->getDigestMinutes())),
            'reminder'      => $this->settingsPick($options, $args[0] ?? '', ['off' => -1, 'now' => 0, '1d' => 86400, '3d' => 3 * 86400, '7d' => 7 * 86400],
                                    fn ($v) => $options->setFinalizeReminderSec($v), 'Finalize reminder', fn () => Phrase::FinalizeReminder($options->getFinalizeReminderSec())),
            'attestations'  => $this->settingsPick($options, $args[0] ?? '', ['off' => 0, '1' => 1, '3' => 3, '10' => 10, '25' => 25, '50' => 50],
                                    fn ($v) => $options->setMissedAttestationsAlert($v), 'Missed attestations', fn () => Phrase::MissedAttestations($options->getMissedAttestationsAlert())),
            'threshold'     => $this->settingsThreshold($options, $args),
            default         => null,
        };
    }

    private function settingsSummary(OptionsNotifier $options): Notification
    {
        $lines = [];

        foreach ($options->getNotificationTypes() as $type)
            $lines[] = ($options->isNotify($type['cfg_key']) ? '✅' : '⛔').' '.$type['title'].' - <code>'.self::TypeKey($type['cfg_key']).'</code>';

        $tez = fn ($mutez) => $mutez > 0 ? rtrim(rtrim(number_format($mutez / 1e6, 3), '0'), '.').' tez' : 'none';

        return Notification\Custom::I('⚙️ <b>Settings</b>')->setDescription(array_merge($lines, [
            ' ',
            '<code>!on</code> / <code>!off</code> + a name above (or <code>all</code>)',
            '📬 Digest: '.Phrase::Digest($options->getDigestMinutes()).' - <code>!digest off|15m|1h|3h</code>',
            '💰 Minimum transaction: '.$tez($options->getThreshold(OptionsNotifier::kThresholdTransaction)).' - <code>!threshold 0.5</code>',
            '🟠 Minimum offer: '.$tez($options->getThreshold(OptionsNotifier::kThresholdOffer)).' - <code>!threshold offer 1</code>',
            '⏳ Finalize reminder: '.Phrase::FinalizeReminder($options->getFinalizeReminderSec()).' - <code>!reminder off|now|1d|3d|7d</code>',
            '🚨 Missed attestations: '.Phrase::MissedAttestations($options->getMissedAttestationsAlert()).' - <code>!attestations off|1|3|10|25|50</code>',
            ' ',
            'Per-wallet filters, set on Telegram or Discord, still apply.',
        ]));
    }

    private function settingsToggle(OptionsNotifier $options, bool $state, string $key): Notification
    {
        $types   = $options->getNotificationTypes();
        $matched = $key === 'all' ? array_keys($types) : array_filter(array_keys($types), fn ($cfgKey) => self::TypeKey($cfgKey) === $key);

        if (!$matched)
            return Notification\Custom::I("Unknown type <code>".Notification::SafeText($key)."</code>. Send <code>!settings</code> for the list.");

        foreach ($matched as $cfgKey)
            $options->setNotify($cfgKey, $state);

        $what = $key === 'all' ? 'All notifications' : $types[reset($matched)]['title'];

        return Notification\Custom::I(($state ? '✅ ' : '⛔ ').$what.($state ? ': on' : ': off'));
    }

    private function settingsPick(OptionsNotifier $options, string $arg, array $choices, callable $set, string $name, callable $current): Notification
    {
        if (!array_key_exists($arg, $choices))
            return Notification\Custom::I("$name: ".$current().'. Choose one of: <code>'.join('</code> <code>', array_keys($choices)).'</code>');

        $set($choices[$arg]);

        return Notification\Custom::I("✅ $name: ".$current());
    }

    private function settingsThreshold(OptionsNotifier $options, array $args): Notification
    {
        $scope = ($args[0] ?? '') === 'offer' ? OptionsNotifier::kThresholdOffer : OptionsNotifier::kThresholdTransaction;
        $value = $scope === OptionsNotifier::kThresholdOffer ? ($args[1] ?? null) : ($args[0] ?? null);
        $name  = $scope === OptionsNotifier::kThresholdOffer ? 'Minimum offer' : 'Minimum transaction';

        $allowed = array_values(OptionsNotifier::GetThresholdValues());
        $mutez   = is_numeric($value) ? (int) round((float) $value * 1e6) : null;
        $list    = join(' ', array_map(fn ($m) => rtrim(rtrim(number_format($m / 1e6, 3), '0'), '.'), $allowed));

        if ($mutez === null || !in_array($mutez, $allowed, true))
            return Notification\Custom::I("$name in tez, one of: <code>$list</code> (0 = no minimum)");

        $options->setThreshold($scope, $mutez);

        return Notification\Custom::I("✅ $name: ".($mutez ? rtrim(rtrim(number_format($mutez / 1e6, 3), '0'), '.').' tez' : 'none'));
    }

    /** notify_teia_events => teia-events */
    private static function TypeKey(string $cfgKey): string
    {
        return str_replace('_', '-', preg_replace('/^notify_/', '', $cfgKey));
    }
}
