<?php

namespace App\Command;

use Amqp\Channel;
use App\Binance\MarginIsolatedApi;
use App\Hedge\UnitaryHedge;
use App\Hedge\UnitaryHedgeBuy;
use App\Hedge\UnitaryHedgeSell;
use Binance\Chart\Minutes;
use Binance\Chart\Seconds;
use Binance\Event\Trade;
use Binance\MarketDataApi;
use Laminas\Log\Filter\Priority;
use Laminas\Log\Logger;
use Laminas\Log\Writer\AbstractWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class HedgeCommand extends Command
{
    /** @var string Active running hedge consumer tag. */
    private string $tag = '';

    private UnitaryHedge $hedge;

    public function __construct(protected readonly Logger            $log,
                                protected readonly MarginIsolatedApi $api,
                                protected readonly Channel           $ch
    )
    {
        parent::__construct();
    }

    public function getName() : string
    {
        return 'hedge';
    }

    protected function configure(): void
    {
        // one thread for each symbol so that multiple symbols can be in work
        $this->addArgument('SYMBOL', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->api->symbol = strtoupper($input->getArgument('SYMBOL'));
        // TODO validate symbol with API

        // chart data to calculate technical analysis values
        $this->log->debug('Fetching klines...');
        $this->api->s = new Seconds();
        $this->api->m = new Minutes();
        $klines = (new MarketDataApi([]))->getKlines([
            'symbol' => $this->api->symbol,
            'interval' => '1m',
            'startTime' => (time() - 660) * 1000, // last 10 minutes
            'endTime' => time() * 1000
        ]);
        if ($klines) $this->api->m->withKlines($klines);
        else throw new \RuntimeException('Failed to load minutes klines.');

        $q = 'hedge.' . $this->api->symbol;
        $this->ch->exchangeDeclare('hedge', type: 'topic');
        $this->ch->queueDeclare($q);
        $this->ch->queuePurge($q);
        $this->ch->bind($q, 'hedge', [], $this->api->symbol.'.*');
        $this->ch->bind($q, 'hedge', [], 'cancel');
        $this->ch->bind($q, 'binance', rkey: strtolower("trade.{$this->api->symbol}"));
        $this->ch->bunny->consume($this, $q);
        $this->ch->bunny->qos(0, 1);

        $this->log->debug("Waiting for commands...");
        $this->ch->run();

        return Command::FAILURE; // restart by supervisor
    }

    public function __invoke(\Bunny\Message $msg, \Bunny\Channel $ch): bool
    {
        // control messages
        if ($msg->exchange == 'hedge')
        {
            $this->log->debug('Got message ' . $msg->routingKey . ': ' . $msg->content);

            $cmd = explode('.', $msg->routingKey);
            $cmd = array_pop($cmd);
            switch ($cmd) {
                case 'sell':
                case 'buy':
                    if ($this->tag) {
                        $this->log->err('Already hedging. Cancel first.');
                        return $ch->reject($msg,false);
                    }

                    list($low, $high) = explode(' ', $msg->content);
                    $class = $cmd == 'sell' ? UnitaryHedgeSell::class : UnitaryHedgeBuy::class;
                    try {
                        $this->hedge = new $class($this->log, $this->api, $low, $high);
                    } catch (\Throwable $e) {
                        $this->log->err($e->getMessage());
                        $this->log->debug($e);
                        return $ch->reject($msg, false);
                    }

                    // start notifying price deviations
                    $ch->publish(implode(' ', [$this->api->symbol, $low, $high]), 'monitor', 'start');
                    break;

                case 'cancel':
                    if (isset($this->hedge)) {
                        $this->hedge->cancel();
                        unset($this->hedge);
                        $ch->publish($this->api->symbol, 'monitor', 'stop');
                    }
                    return $ch->ack($msg);

                default:
                    return $ch->reject($msg, false);
            }
        }

        // trade feed
        if ($msg->exchange == 'binance') {
            $trade = unserialize($msg->content);
            if (!$trade instanceof Trade) return $ch->reject($msg, false);

            $this->api->s->append($trade);
            $this->api->m->append($trade);

            if (isset($this->hedge)) {
                try {
                    ($this->hedge)($trade, $ch);
                } catch (\Throwable $e) {
                    $this->log->err($e->getMessage()); // this goes to telegram
                    $this->log->debug($e);
                }
                return $ch->ack($msg);
            }
        }

        return $ch->ack($msg);
    }
}
