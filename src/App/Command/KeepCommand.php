<?php
namespace App\Command;

use App\Hedge\HedgeSell;
use Binance\Event\Trade;
use Binance\MarginIsolatedApi;
use Binance\WebsocketsApi;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Send AMQP message to start hedging.
 */
class KeepCommand extends Command
{
    public function __construct(private readonly Logger            $log,
                                private readonly WebsocketsApi     $ws,
                                private readonly MarginIsolatedApi $api)
    {
        parent::__construct('keep');
    }

    protected function configure(): void
    {
        $this->setDescription('')
            ->addArgument('SYMBOL', InputArgument::REQUIRED, )
            ->addArgument('TOKEN', InputArgument::REQUIRED, )
            ->addArgument('MIN', InputArgument::REQUIRED, )
            ->addArgument('MAX', InputArgument::REQUIRED, )
            ->addArgument('AMOUNT', InputArgument::OPTIONAL, 'Max borrowable if empty');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $input->getArgument('SYMBOL');
        $token  = $input->getArgument('TOKEN');
        $min    = $input->getArgument('MIN');
        $max    = $input->getArgument('MAX');
        $amount = $input->getArgument('AMOUNT');

        $hedge = new HedgeSell($this->log, $this->api, $symbol, $token, $min, $max, $amount);

        // read price from websockets and pass to business logic class
/*        $this->ws->kline($symbol, WebsocketsApi::INTERVAL_1S);
        while(true) {
            $kline = $this->ws->receive(1);
            if ($kline instanceof Kline) {
                ($this->hedge)($kline->close);
            }
            else {
                // empty feed, wait?
            }
        }*/

        $this->ws->subscribe("$symbol@trade");
        while ($trade = $this->ws->receive()) {
            if ($trade instanceof Trade) {
                ($hedge)($trade);
            }
        }

        /** @noinspection PhpUnreachableStatementInspection Timeout */
        return Command::FAILURE;
    }
}
