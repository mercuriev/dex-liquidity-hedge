<?php

namespace Trader\Command;

use Binance\MarketDataApi;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LoadTradesCommand extends Command
{
    public function __construct(private Logger $log)
    {
        parent::__construct('trader:load-trades');
    }

    public function configure()
    {
        $this->addArgument('symbol', InputArgument::REQUIRED);
        $this->addOption('since',   null,    InputOption::VALUE_REQUIRED, 'Start time', "1 hours ago");
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = strtoupper($input->getArgument('symbol'));

        try {
            $since      = new \DateTime($input->getOption('since'));
        } catch (\Exception $e) {
            $this->log->crit('Invalid "since" option.');
            $this->log->crit($e->getMessage());
            return Command::INVALID;
        }

        $file = fopen('php://stdout', 'a');

        $api = new MarketDataApi([]);
        $params = [
            'symbol' => $symbol,
            'limit' => 1000,
        ];
        do {
            $req = $api::buildRequest('GET', 'historicalTrades', $params);
            $res = $api->request($req, $api::SEC_NONE);
            if (!$res) throw new \RuntimeException('Empty response');
            $res = array_reverse($res);
            foreach ($res as $trade) {
                fputcsv($file, $trade);

                if ($since->getTimestamp() * 1000 > $trade['time']) {
                    return Command::SUCCESS;
                }
            }
            /** @noinspection PhpUndefinedVariableInspection */
            $params['fromId'] = (@$params['fromId'] ?: $trade['id']) - $params['limit'];
        }
        while(true);
    }
}
