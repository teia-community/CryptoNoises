<?php

namespace CryptoNoises\Models;

use CryptoCore\Vss\Vss;
use CryptoCore\Vss\VssModel;

class EventsFlow extends VssModel
{
    public static function model($className=__CLASS__): self
    {
        return parent::model($className);
    }

    public function tableName(): string
    {
        return 'events_flow';
    }

    /**
     * While set, insert() keeps blockchain events (network_block > 0) for flushDeferred() and returns a placeholder
     * id: for events nobody is notified about, whose id is never used. One check and one multi-row insert per block
     * instead of two queries per event.
     */
    public bool $defer = false;

    const kDEFERRED_ID = 1;

    private array $deferred = [];

    /** Drops what was kept: the block is not saved after all (an error), and will be analyzed again */
    public function discardDeferred(): void
    {
        $this->deferred = [];
    }

    public function flushDeferred(): void
    {
        $rows = $this->deferred;
        $this->deferred = [];

        if (empty($rows))
            return;

        // The same duplicate check as insert(): an operation id + subid is one event, whatever its type. The
        // first of repeats within the batch wins, as it would have been inserted first.
        $existing = [];
        $uids     = [];

        foreach ($rows as $row)
            $uids[$row['network_id']][$row['entity_uid']] = true;

        foreach ($uids as $networkId => $networkUids)
        {
            foreach (array_chunk(array_keys($networkUids), 500) as $chunk)
            {
                $params = ['network_id' => $networkId];
                foreach ($chunk as $i => $uid)
                    $params["u$i"] = $uid;

                $in = ':'.join(', :', array_keys(array_slice($params, 1, null, true)));

                foreach (Vss::db()->query("SELECT entity_uid, entity_subid FROM {$this->tableName()}
                                           WHERE network_id = :network_id AND entity_uid IN ($in) AND network_block > 0", $params) as $found)
                    $existing["$networkId|{$found['entity_uid']}|{$found['entity_subid']}"] = true;
            }
        }

        $new = [];

        foreach ($rows as $row)
        {
            $key = "{$row['network_id']}|{$row['entity_uid']}|{$row['entity_subid']}";

            if (isset($existing[$key]))
                continue;

            $existing[$key] = true;
            $new[] = $row;
        }

        Vss::db()->insertMany($this->tableName(), $new, 'IGNORE');
    }

    public function getEventById($eventFlowId)
    {
        $sql = "SELECT * FROM {$this->tableName()} WHERE event_flow_id = :event_flow_id";

        return Vss::db()->queryRow($sql, ['event_flow_id'=>$eventFlowId]);
    }

    /**
     * Returns a negative number if the unique record already exists (abs(value) is its id).
     *
     * Blockchain events (network_block > 0) use the operation id as entity_uid: one id + subid is one event, whatever
     * its type (a second event of another type for the same operation is a duplicate detection and is dropped).
     * The two id spaces are checked separately: a time() value may equal a TzKT operation id.
     * Commands that number their events by time() (mints, metadata updates, domain expiry; network_block = 0) may
     * reuse the same ids in the same second, so for them the event type is part of the check.
     *
     * @param $item
     * @return int
     */
    public function insert($item)
    {
        $isTimeBasedId = (int) $item['network_block'] === 0;

        if ($this->defer && !$isTimeBasedId)
        {
            $this->deferred[] = $item;
            return self::kDEFERRED_ID;
        }

        $sql = "
            SELECT event_flow_id
            FROM {$this->tableName()}
            WHERE network_id = :network_id
              AND entity_uid = :entity_uid
              AND entity_subid = :entity_subid
              ".($isTimeBasedId ? "AND network_block = 0 AND event_type = :event_type" : "AND network_block > 0")."
        ";

        $params = [
            'network_id'    => $item['network_id'],
            'entity_uid'    => $item['entity_uid'],
            'entity_subid'  => $item['entity_subid'],
        ];

        if ($isTimeBasedId)
            $params['event_type'] = $item['event_type'];

        $eventId = Vss::db()->queryScalar($sql, $params);

        if ($eventId > 0)
            return -$eventId;

        return Vss::db()->insertAndGetId($this->tableName(), $item, 'IGNORE');
    }
}
