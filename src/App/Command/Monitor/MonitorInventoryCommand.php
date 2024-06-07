<?php

namespace App\Command\Monitor;

use Amqp\Channel;
use App\Binance\MarginIsolatedApi;
use Bunny\Message;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Notify user when an asset is available to borrow again.
 */
class MonitorInventoryCommand extends Command
{
    public const QUEUE_NAME = 'monitor-inventory';
    public const FREQUENCY = 40; // less than heartbeat

    public function getName(): string { return 'monitor:inventory'; }

    public function __construct(
        protected Logger $log,
        protected Channel $ch,
        protected MarginIsolatedApi $api
    )
    {
        parent::__construct();

        $this->ch->exchangeDeclare('monitor', type: 'topic');
        $this->ch->queueDeclare(self::QUEUE_NAME);
        $this->ch->bind(self::QUEUE_NAME, 'monitor', rkey: 'inventory');
        $this->ch->bunny->consume($this, self::QUEUE_NAME);
    }

    public function __invoke(Message $msg, \Bunny\Channel $ch) : bool
    {
        $asset = strtoupper($msg->content);
        if (empty($asset)) {
            $this->log->info('Message body must be asset name only');
            return $ch->reject($msg, false);
        }

        $req = $this->api::buildRequest('GET', 'available-inventory', ['type' => 'ISOLATED']);
        $res = $this->api->request($req);
        if (array_key_exists($asset, $res['assets'])) {
            $vol = $res['assets'][$asset];
            if ($vol > 0) {
                $this->log->notice("$asset is available to borrow: $vol");
            }
            else {
                $this->log->info("No $asset available.");
                sleep(self::FREQUENCY);
                // republish (no requeue) so that multiple assets queried in round-robin
                $ch->publish($asset, 'monitor', 'inventory');
            }
            return $ch->ack($msg);
        }
        else {
            $this->log->info('Asset ' . $asset . ' not found');
            return $ch->reject($msg, false);
        }
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ch->run();
        return Command::SUCCESS;
    }
}
