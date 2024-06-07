<?php

namespace App\Command\Monitor;

use Amqp\Channel;
use Binance\Event\Trade;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorRangeCommand extends Command
{
    const QUEUE_NAME = 'monitor-range';
    const FREQ = 60; // check every FREQ seconds
    private int $last;

    // symbol => [$low, $high, $status]
    protected array $ranges = [];

    public function getName(): string
    {
        return 'monitor:range';
    }

    public function __construct(protected Logger $log, protected Channel $ch)
    {
        parent::__construct();
    }

    /**
     * Example:
     *   monitor:start
     *   BTCFDUSD 70000 71000
     */
    public function __invoke(\Bunny\Message $msg, \Bunny\Channel $ch): bool
    {
        // control messages
        if ($msg->exchange == 'monitor')
        {
            if ($msg->routingKey == 'start') {
                list($symbol, $low, $high) = explode(' ', $msg->content);
                $symbol = strtolower($symbol);
                // TODO input check
                // yes, rewrite existing without check so that it is simple to update
                $this->ranges[$symbol] = [$low, $high];
                $ch->queueBind(self::QUEUE_NAME, 'binance', "trade.$symbol");
                $this->log->info(sprintf('Monitoring %s between %.2f and %2.f', $symbol, $low, $high));
                return $ch->ack($msg);
            }
            if ($msg->routingKey == 'stop') {
                $symbol = strtolower($msg->content);
                unset($this->ranges[$symbol]);
                $ch->queueUnbind(self::QUEUE_NAME, 'binance', "trade.$symbol");
                $this->log->info("Stopped monitoring $symbol.");
                return $ch->ack($msg);
            }
        }

        // watch prices
        if ($msg->exchange = 'trade')
        {
            if (!isset($this->last) || $this->last <= time() - self::FREQ) {
                $this->last = time();
            }
            else return $ch->ack($msg);

            $symbol = explode('.', $msg->routingKey);
            $symbol = array_pop($symbol);
            if (!isset($this->ranges[$symbol])) return $ch->reject($msg, false);
            else $range =& $this->ranges[$symbol];

            $trade = unserialize($msg->content);
            if (!$trade instanceof Trade) {
                return $ch->reject($msg, false);
            }

            $check = function(float $price, $range) {
                return match(true) {
                    $price < $range[0]  => 'below',
                    $price > $range[1]  => 'above',
                    default             => 'in',
                };
            };
            $now = $check($trade->price, $range);
            if (!isset($range[2]) || $range[2] != $now) {
                $range[2] = $now;
                $this->log->notice(sprintf(
                    '%s price is %s range: %.2f', $symbol, $range[2], $trade->price)
                );
            }
        }

        return $ch->ack($msg);
    }

    public function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->ch->exchangeDeclare('monitor', type: 'topic');
        $this->ch->queueDelete(self::QUEUE_NAME);
        $this->ch->queueDeclare(self::QUEUE_NAME);
        $this->ch->bind(self::QUEUE_NAME, 'monitor', rkey: 'start');
        $this->ch->bind(self::QUEUE_NAME, 'monitor', rkey: 'stop');
        $this->ch->bunny->consume($this, self::QUEUE_NAME);
        $this->log->debug('Started on queue: ' . self::QUEUE_NAME);
        $this->ch->run();
        return 0;
    }
}
