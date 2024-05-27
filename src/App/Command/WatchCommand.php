<?php
namespace App\Command;

use Amqp\Channel;
use Amqp\Message;
use Binance\Event\Trade;
use Binance\WebsocketsApi;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WebSocket\Client;
use WebSocket\TimeoutException;

/**
 * Fetch trade data from API websockets and then publish each trade to amq.topic with key binance:market.SYMBOL
 */
class WatchCommand extends Command
{
    const EXCHANGE = 'binance';

    private Client $ws;

    public function getName() : string
    {
        return 'watch';
    }

    public function __construct(protected Channel $ch, protected WebsocketsApi $binance, protected Logger $log)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('symbols', InputArgument::IS_ARRAY | InputArgument::REQUIRED);
    }

    public function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->ch->exchangeDeclare(self::EXCHANGE, type: 'topic');

        //
        $topics = $input->getArgument('symbols');
        foreach ($topics as &$t) {
            $t = strtolower($t) . '@trade';
        }
        $this->connectAndSubscribe($topics);

        //
        while ($this->ws->isConnected())
        {
            try {
                $res = $this->ws->receive();
            }
            catch (TimeoutException $e) {
                $this->log->err($e->getMessage());
                return 100;
            }
            if (is_numeric($res)) continue;
            $payload = json_decode($res, true);
            if (array_key_exists('result', $payload)) continue;

            $trade = new Trade($payload);

            // publish
            $symbol =& $payload['s'];
            $this->ch->publish(new Message(serialize($trade)), self::EXCHANGE, "trade.$symbol");
        }
        return 100; // Disconnected. Restart by supervisor
    }

    private function connectAndSubscribe(array $topics) : void
    {
        $this->ws = new Client('wss://stream.binance.com:9443/ws/bookTicker');

        $payload = [
            'id' => 2,
            'method' => 'SUBSCRIBE',
            'params' => $topics
        ];
        $this->ws->send(json_encode($payload));

        $resp = $this->ws->receive();
        $resp = json_decode($resp, true);
        if (!array_key_exists('result', $resp) || $resp['result'] !== null) {
            throw new \RuntimeException("Failed to subscribe\n" . json_encode($resp));
        }

        $this->log->info('Connected and subscribed to ' . implode(', ', $topics));
    }
}
