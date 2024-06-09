<?php

namespace Trader;

use Amqp\Channel;
use App\Command\FeedCommand;
use Bunny\Message;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 1. Build Trader based on provided startup options.
 * 2. Consume Trades from rabbit and dispatch them to Trader.
 */
class TraderCommand extends Command
{
    public function __construct(
        protected Logger  $log,
        protected Channel $ch,
    )
    {
        parent::__construct('trader');
    }

    public function configure(): void
    {
        $this->addArgument('symbol', InputArgument::REQUIRED);
        $this->addOption('strategy', null, InputOption::VALUE_REQUIRED);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = strtoupper($input->getArgument('symbol'));
        $strategy = $input->getOption('strategy');

        $trader = $GLOBALS['container']->build(Trader::class, [
            'symbol' => $symbol,
            'strategy' => $strategy,
        ]);

        // dispatch trades
        $this->declareQueueAndBindToTrades($symbol);
        $this->ch->bunny->consume(function (Message $msg, \Bunny\Channel $ch) use ($trader) {
            $trade = unserialize($msg->content);
            $trader($trade);
            $ch->ack($msg);
        });
        $this->ch->run();

        return Command::SUCCESS;
    }

    private function declareQueueAndBindToTrades(string $symbol): void
    {
        // signal to start dispatching trades if not yet started
        $this->ch->bunny->publish($symbol, 'feed', 'sub');

        $symbol = strtolower($symbol);
        $q = "trader.$symbol";
        // must be autodelete so that old trades do not confuse the trader
        $this->ch->queueDeclare($q, flags: MQ_AUTODELETE);
        $this->ch->bind($q, FeedCommand::EXCHANGE, rkey: "trade.$symbol");
    }
}
