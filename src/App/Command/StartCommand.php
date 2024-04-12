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
            ->addArgument('SYMBOL', InputArgument::REQUIRED, )
            ->addArgument('MIN', InputArgument::REQUIRED, )
            ->addArgument('MAX', InputArgument::REQUIRED, )
            ->addArgument('AMOUNT', InputArgument::OPTIONAL, 'Max borrowable if empty');
    }

    /**
     * @throws BadOpcodeException
     * @throws ExceedBorrowable
     * @throws BinanceException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $input->getArgument('SYMBOL');
        $min    = $input->getArgument('MIN');
        $max    = $input->getArgument('MAX');
        $amount = $input->getArgument('AMOUNT');

        // subscribe before Hedge so that we always catch Trades for our orders
        $this->ws->subscribe("$symbol@trade");

        $class = $this->getHedgeClass();
        $hedge = new $class($this->log, $this->api, $symbol, $min, $max, $amount);

        while ($trade = $this->ws->receive()) {
            if ($trade instanceof Trade) {
                ($hedge)($trade);
            }
        }

        return Command::FAILURE;
    }
}
