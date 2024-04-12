<?php
namespace App\Command;

use App\Hedge\HedgeSell;

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
        return HedgeSell::class;
    }
}
