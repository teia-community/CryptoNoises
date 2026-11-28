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
