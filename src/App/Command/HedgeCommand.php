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
use Bunny\Message;
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

    /**
     * Entry point.
     * Define control 'hedge' queue and wait for commands.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // control queue
        $ch = $this->ch->bunny;
        $ch->exchangeDeclare('hedge', 'topic');
        $ch->queueDelete('hedge'); // FIXME this blocks multiple consumers
        $ch->queueDeclare('hedge');
        $ch->queueBind('hedge', 'hedge', 'sell');
        $ch->queueBind('hedge', 'hedge', 'buy');
        $ch->queueBind('hedge', 'hedge', 'cancel');
        $ch->consume($this, 'hedge');

        $this->log->debug("Waiting for commands...");
        $this->ch->run();
        return Command::FAILURE; // restart
    }

    /**
     * Consumer for the main control queue.
     *
     * @param Message $msg
     * @param \Bunny\Channel $ch
     * @return bool
     */
    public function __invoke(Message $msg, \Bunny\Channel $ch): bool
    {
        $this->log->debug('Got message ' . $msg->routingKey . ': ' . $msg->content);

        switch ($msg->routingKey) {
            case 'sell':
            case 'buy':
                if (isset($this->hedge)) {
                    $this->log->err('Already hedging. Cancel first.');
                    return $ch->reject($msg,false);
                }

                list($symbol, $low, $high) = explode(' ', $msg->content);
                try {
                    $this->loadApi($symbol);
                    $class = $msg->routingKey == 'sell' ? UnitaryHedgeSell::class : UnitaryHedgeBuy::class;
                    $this->hedge = new $class($this->log, $this->api, $low, $high);
                    $this->consumeTrades($symbol);

                    // start notifying user about price deviations
                    $ch->publish(implode(' ', [$this->api->symbol, $low, $high]), 'monitor', 'start');
                }
                catch (\Throwable $e) {
                    $this->log->err($e->getMessage());
                    $this->log->debug($e);
                    return $ch->reject($msg, false);
                }
                break;

            case 'cancel':
                if (isset($this->hedge)) {
                    $this->hedge->shutdown();
                    unset($this->hedge);
                    $ch->queueDelete('hedge.' . $this->api->symbol);
                    $this->ch->bunny->publish($this->api->symbol, 'feed', 'unsub');
                    $ch->publish($this->api->symbol, 'monitor', 'stop');
                }
                break;
        }
        return $ch->ack($msg);
    }

    /**
     * Feed trades to existing Hedge.
     *
     * @param Message $msg
     * @param \Bunny\Channel $ch
     * @return bool
     */
    public function trade(Message $msg, \Bunny\Channel $ch): bool
    {
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
        }
        return $ch->ack($msg);
    }

    private function loadApi(string $symbol) : void
    {
        $this->api->symbol = strtoupper($symbol);
        // TODO validate symbol with API

        // chart data to calculate technical analysis values
        $this->log->debug('Fetching klines...');
        $this->api->s = new Seconds();
        $klines = (new MarketDataApi([]))->getKlines([
            'symbol' => $this->api->symbol,
            'interval' => '1s',
            'startTime' => (time() - 60) * 1000, // last minute
            'endTime' => time() * 1000
        ]);
        if ($klines) $this->api->s->withKlines($klines);
        else throw new \RuntimeException('Failed to load seconds klines.');

        $this->api->m = new Minutes();
        $klines = (new MarketDataApi([]))->getKlines([
            'symbol' => $this->api->symbol,
            'interval' => '1m',
            'startTime' => (time() - 660) * 1000, // last 10 minutes
            'endTime' => time() * 1000
        ]);
        if ($klines) $this->api->m->withKlines($klines);
        else throw new \RuntimeException('Failed to load minutes klines.');
    }

    private function consumeTrades(string $symbol) : void
    {
        // this hedge queue that receives trades and cancel request
        $q = "hedge.$symbol";
        $this->ch->queueDeclare($q, flags: MQ_EXCLUSIVE);
        $this->ch->bind($q, 'hedge', [], $this->api->symbol.'.*');
        $this->ch->bind($q, 'hedge', [], 'cancelAll');
        $this->ch->bind($q, 'binance', rkey: strtolower("trade.$symbol"));
        $this->ch->bunny->consume([$this, 'trade'], $q);
        #$this->ch->bunny->qos(0, 1);

        // start feeding trades
        $this->ch->bunny->publish($symbol, 'feed', 'sub');
    }
}
