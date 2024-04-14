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
use WebSocket\BadOpcodeException;

class StartCommand extends Command
{
    public readonly string $symbol;
    public float $min;
    public float $max;
    public float $amount;
    public bool $crossedLowerLimit;
    public bool $crossedHigherLimit;

    public function __construct(protected readonly Logger            $log,
                                protected readonly WebsocketsApi     $ws,
                                protected readonly MarginIsolatedApi $api,
                                protected readonly Channel           $mq
    )
    {
        parent::__construct();
    }

    public function getName() : string
    {
        return 'start';
    }

    public function getHedgeClass() : string
    {
        throw new \LogicException('Must override');
    }

    protected function configure(): void
    {
        $this->setDescription('')
            ->addArgument('SYMBOL', InputArgument::REQUIRED)
            ->addArgument('MIN', InputArgument::REQUIRED)
            ->addArgument('MAX', InputArgument::REQUIRED)
        ;
    }

    /**
     * @throws BadOpcodeException
     * @throws ExceedBorrowable
     * @throws BinanceException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->symbol = strtoupper($input->getArgument('SYMBOL'));
        $this->min    = $input->getArgument('MIN');
        $this->max    = $input->getArgument('MAX');

        restart:
        // subscribe before Hedge so that we always catch Trades for our orders
        $this->ws->subscribe("$this->symbol@trade");

        $this->api->symbol = $this->symbol;
        $class = $this->getHedgeClass();
        $hedge = new $class($this->log, $this->api, $this->min, $this->max);

        while ($trade = ($this->ws)(30)) {
            if ($trade instanceof Trade) {
                ($hedge)($trade);

                // notify user that price is too away of the range
                $this->notify($trade);
            }
        }

        if (null === $trade) {
            $this->log->err('No trade received.');
            goto restart; // avoid recursion for the long-running script
        }

        return Command::FAILURE;
    }

    public function notify(Trade $trade) : void
    {
        $range = $this->max - $this->min;
        $excessPercentageLimit = $range * 0.2;
        $lowerPriceLimit = $this->min - $excessPercentageLimit;
        $higherPriceLimit = $this->max + $excessPercentageLimit;

        $this->crossedLowerLimit = false;
        $this->crossedHigherLimit = false;

        if ($trade->price < $lowerPriceLimit) {
            $this->crossedLowerLimit = true;
            $msg = "Price has crossed the lower limit of the range by more than 20%. Trade Price: $trade->price, Range: $this->min-$this->max";
        } elseif ($trade->price > $higherPriceLimit) {
            $this->crossedHigherLimit = true;
            $msg = "Price has crossed the higher limit of the range by more than 20%. Trade Price: $trade->price, Range: $this->min-$this->max";
        }
        if (isset($msg)) {
            $this->log->info($msg);

            $msg = new Message($msg);
            $this->mq->publish($msg, 'amq.topic', 'log.notice');
        }
    }
}
