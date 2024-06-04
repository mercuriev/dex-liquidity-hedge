<?php

namespace App\Command;

use Amqp\Channel;
use Amqp\Message;
use Binance\Event\Trade;
use Binance\Exception\BinanceException;
use Binance\Exception\ExceedBorrowable;
use Binance\MarginIsolatedApi;
use Binance\WebsocketsApi;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class HedgeCommand extends Command
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
            implode(' ', [$this->min, $this->max]),
            'hedge',
            $this->symbol .'.'. $this->getName()
        );
        $this->log->debug('Message sent successfully.');

        return Command::SUCCESS;
    }
}
