<?php

namespace App\Command\Cli;

use Amqp\Channel;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SellCommand extends Command
{
    public readonly string $symbol;
    public float $min;
    public float $max;
    public float $amount;

    public function __construct(protected readonly Logger            $log,
                                protected readonly Channel           $ch
    )
    {
        parent::__construct();
    }

    public function getName() : string
    {
        return 'sell';
    }

    protected function configure(): void
    {
        $this->setDescription('')
            ->addArgument('SYMBOL', InputArgument::REQUIRED)
            ->addArgument('MIN', InputArgument::REQUIRED)
            ->addArgument('MAX', InputArgument::REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->symbol = strtoupper($input->getArgument('SYMBOL'));
        $this->min    = $input->getArgument('MIN');
        $this->max    = $input->getArgument('MAX');

        $this->ch->bunny->publish(
            $body = implode(' ', [$this->symbol, $this->min, $this->max]),
            'hedge',
            $name = $this->getName()
        );
        $this->log->debug("Send $name: $body");

        return Command::SUCCESS;
    }
}
