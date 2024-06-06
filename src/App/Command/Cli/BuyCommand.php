<?php
namespace App\Command\Cli;

/**
 * Send AMQP message to start hedging.
 */
class BuyCommand extends SellCommand
{
    public function getName() : string
    {
        return 'buy';
    }
}
