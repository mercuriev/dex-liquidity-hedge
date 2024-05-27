<?php
namespace App\Command;

use App\Hedge\UnitaryHedgeBuy;

/**
 * Send AMQP message to start hedging.
 */
class BuyCommand extends HedgeCommand
{
    public function getName() : string
    {
        return 'buy';
    }
}
