<?php

namespace App\Command;

use App\Hedge\HedgeSell;
use Binance\Event\Kline;
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
    protected string $symbol;
    protected float $min;
    protected float $max;

    public function __construct(protected readonly Logger            $log,
                                protected readonly WebsocketsApi     $ws,
                                protected readonly MarginIsolatedApi $api)
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
        $this->symbol = $input->getArgument('SYMBOL');
        $this->min    = $input->getArgument('MIN');
        $this->max    = $input->getArgument('MAX');

        restart:
        // subscribe before Hedge so that we always catch Trades for our orders
        $this->ws->subscribe("$this->symbol@trade");

        $class = $this->getHedgeClass();
        $hedge = new $class($this->log, $this->api, $this->symbol, $this->min, $this->max);


        while ($trade = ($this->ws)(30)) {
            if ($trade instanceof Trade) {
                ($hedge)($trade);
            }
        }

        if (null === $trade) {
            $this->log->err('No trade received.');
            goto restart; // avoid recursion for the long-running script
        }

        return Command::FAILURE;
    }
}
