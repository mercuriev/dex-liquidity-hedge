<?php
namespace App\Command;

use App\Hedge\UnitaryHedgeSell;

/**
 * Send AMQP message to start hedging.
 */
class SellCommand extends HedgeCommand
{
    public function getName() : string
    {
        return 'sell';
    }

    public function getHedgeClass() : string
    {
        return UnitaryHedgeSell::class;
    }
}
