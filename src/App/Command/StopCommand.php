<?php
namespace App\Command;

use Amqp\Channel;
use App\Hedge\UnitaryHedgeSell;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Send AMQP message to start hedging.
 */
class StopCommand extends Command
{
    public function getName() : string
    {
        return 'stop';
    }

    public function __construct(protected readonly Logger            $log,
                                protected readonly Channel           $ch
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ch->bunny->publish('', 'hedge', $this->getName());
        $this->log->info('Message sent successfully.');

        return Command::SUCCESS;
    }
}
