<?php

namespace Trader\Model;

use Binance\Order\AbstractOrder;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\RowGateway\RowGateway;
use Laminas\Db\Sql\Replace;
use Laminas\Db\Sql\Sql;

/**
 * Deal is ultimate result of Trader activity which consist of entry order and exit order to make profit.
 * Persisted in the database for state recovery and later analysis.
 *
 * @property int $id
 * @property string $trader
 * @property string $status
 * @property float $amount
 * @property float $outcome
 */
class Deal extends RowGateway
{
    #public AbstractStrategy $strategy;
    public ?AbstractOrder $orderIn = null;
    public ?AbstractOrder $orderOut = null;

    public function __construct(Adapter $adapterOrSql = null)
    {
        // this model saves with REPLACE
        $sql = new class($adapterOrSql, 'deal') extends Sql {
            public function insert($table = null)
            {
                return new Replace('deal');
            }
        };
        parent::__construct('id', 'deal', $sql);
    }

    public function save()
    {
        $this->id = $this->orderOut ? $this->orderOut->getId() : $this->orderIn->getId();

        #$this->data['strategy'] = serialize($this->strategy);
        $this->data['orderIn'] = serialize($this->orderIn);
        if ($this->orderOut) {
            $this->data['orderOut'] = serialize($this->orderOut);
        }
        return parent::save();
    }

    public function populate(array $rowData, $rowExistsInDatabase = false)
    {
        $this->orderIn = unserialize($rowData['orderIn']);
        if ($rowData['orderOut']) {
            $this->orderOut = unserialize($rowData['orderOut']);
        }
        #$this->strategy = unserialize($rowData['strategy']);
        return parent::populate($rowData, $rowExistsInDatabase);
    }

    public function getProfit() : float
    {
        if (!$this->orderOut) throw new \RuntimeException('Deal is not finished.');

        $in = $this->orderIn->getExecutedAmount();
        $out = $this->orderOut->getExecutedAmount();

        return round($out - $in, 2);
    }
}
