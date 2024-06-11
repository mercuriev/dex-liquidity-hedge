<?php
namespace Trader\Command;

use Binance\Chart\Chart;
use Binance\Event\Trade;
use Binance\Mock\MockSpotApi;
use Binance\MockBinance;
use Laminas\Db\Adapter\Adapter;
use Laminas\Log\Formatter\Simple;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Trader\Trader;

class BacktestCommand extends Command
{
    private \DateTime $since;

    public function __construct(
        protected Logger $log,
        protected Adapter $db,
     )
     {
         parent::__construct('trader:backtest');
     }

    public function configure()
    {
        parent::configure();

        $this->setDescription('Simulate bids with trades history from DB and output would-be balance.');
        $this->addArgument('symbol', InputArgument::REQUIRED);
        $this->addArgument('tradesFile', InputArgument::REQUIRED, 'It is important to sort it from oldest to newest. (trade:load | tac > file)');
        $this->addOption('balance', 'b',    InputOption::VALUE_REQUIRED, 'Starting balance in fiat.', 10000);
        #$this->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'FQ class name or service alias', TrendFollowingMinutesStrategy::class);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // params
        $symbol = strtoupper($input->getArgument('symbol'));
        $balance    = $input->getOption('balance');
        $tradesFile = $input->getArgument('tradesFile');

        // output initial account balance (phony)
        #$account    = $api->getBalance($symbol);
        #$log->info($account);

        // build Trader
        $log = $this->buildLogger();
        $db         = $GLOBALS['container']->build(Adapter::class, ['database' => 'backtest']);
        $api        = new MockSpotApi();
        $chart      = new Chart;
        $options    = ['symbol' => $symbol, 'strategy' => null];
        $trader = new Trader($log, $db, $api, $chart, $options);

        // run through history
        $count = 0;
        $file = fopen($tradesFile == '-' ? 'php://stdin' : $tradesFile, 'r');
        while (($row = fgetcsv($file)) !== FALSE) {
            $trade = [
                'id'            => $row[0],
                'price'         => $row[1],
                'qty'           => $row[2],
                'time'          => $row[3],
                'isBuyerMaker'  => $row[4],
            ];
            $trade = Trade::fromHistorical($trade);

            $log->getWriters()->top()->time = $trade->time;

            $trade = ($api)($trade);  // match on server-side
            try {
                $trader($trade);         // match on client-side
                $count++;
            }
            catch(\UnderflowException) {
                 // filling chart data yet
            }
        }
        fclose($file);
        $log->info("Processed $count trades.");

        $count = $db->query(
            'SELECT COUNT(id) AS total,
                COUNT(CASE WHEN outcome >  0 THEN 1 END) AS positive,
                COUNT(CASE WHEN outcome <= 0 THEN 1 END) AS negative
            FROM deal WHERE `status` != "NEW"'
        )->execute()->current();
        $log->info("Done {$count['total']} deals. Profits: {$count['positive']}. Losses: {$count['negative']}.");
        $db->query('DELETE FROM deal')->execute();

        // convert any BTC balance so that it is quickly visible outcome
/*        try {
            while(true) $this->out(true);
        }
        catch (\LogicException|InsuficcientBalance) {} // all sold
        finally {
            $this->fetchBalance();
        }*/

        return Command::SUCCESS;
    }

    /**
     * @return Logger Creates Logger with `timestamp` property to sync log output with trades time.
     */
    private function buildLogger(): Logger
    {
        return new Logger(['writers' => [['name' =>
            new class() extends Stream {
                public \DateTime $time;
                public function __construct($streamOrUrl = 'php://stdout', $mode = null, $logSeparator = null, $filePermissions = null)
                {
                    $this->time = new \DateTime(); // until trade time is set
                    $this->formatter = new Simple(dateTimeFormat: 'm-d H:i:s.v');
                    parent::__construct($streamOrUrl, $mode, $logSeparator, $filePermissions);
                }
                protected function doWrite(array $event)
                {
                    $event['timestamp'] = $this->time->format('Y-m-d H:i:s.v');
                    parent::doWrite($event);
                }
            }
        ]]]);
    }
}
