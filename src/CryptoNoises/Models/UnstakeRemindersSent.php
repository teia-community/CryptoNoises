<?php

namespace CryptoNoises\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

/**
 * Which finalize reminders went out: one row per (unstake request, user).
 *
 * The reminder event alone cannot say so for long. EventsFlow::insert() only finds an existing event in events_flow,
 * and Lifecycles::cleanUpEventsFlow() moves every event older than ten minutes out of it, into the monthly table - so
 * a reminder job that relied on the event, and runs every ten minutes, sent the same reminder again on every pass
 * until the tez were finalized.
 */
class UnstakeRemindersSent extends VssModel
{
    public static function model($className = __CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'unstake_reminders_sent';
    }

    /**
     * Records that this user is being reminded about this request.
     *
     * @return bool true the first time only. Claimed before the event is saved: should saving fail, the reminder is
     *              lost rather than repeated - for a nudge, at most once is the right side to err on.
     */
    public function claim(int $requestId, int $userId): bool
    {
        return (int) Vss::db()->insert($this->tableName(), ['request_id' => $requestId, 'user_id' => $userId], 'IGNORE') === 1;
    }
}
