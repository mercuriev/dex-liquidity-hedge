<?php
namespace App\Command;

use App\Hedge\HedgeBuy;

/**
 * Send AMQP message to start hedging.
 */
class BuyCommand extends StartCommand
{
    public function getName() : string
    {
        return 'buy';
    }

    public function getHedgeClass() : string
    {
        return HedgeBuy::class;
    }
}
