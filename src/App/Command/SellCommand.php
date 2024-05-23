<?php
namespace App\Command;

use App\Hedge\HedgeSell;
use App\Hedge\UnitaryHedgeSell;

/**
 * Send AMQP message to start hedging.
 */
class SellCommand extends StartCommand
{
    public function getName() : string
    {
        return 'sell';
    }

    public function getHedgeClass() : string
    {
        #return HedgeSell::class;
        return UnitaryHedgeSell::class;
    }
}
