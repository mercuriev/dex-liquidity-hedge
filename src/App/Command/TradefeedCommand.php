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
class TradefeedCommand extends Command
{
    public const EXCHANGE = 'binance';
    private const TIMEOUT = 10;

    private Client $ws;

    public function getName() : string
    {
        return 'tradefeed';
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
        $topics = array_map(static function ($t) {
            return strtolower($t) . '@trade';
        }, $topics);
        $this->connectAndSubscribe($topics);

        //
        while ($this->ws->isConnected())
        {
            try {
                $res = $this->ws->receive();
            }
            catch (TimeoutException $e) {
                $this->log->err('feed: '.$e->getMessage());
                return 100;
            }
            if (is_numeric($res)) {
                // TODO why is number here? remove check
                $this->log->debug("Got number: $res");
                continue;
            }
            $payload = json_decode($res, true, 512, JSON_THROW_ON_ERROR);
            if (array_key_exists('result', $payload)) {
                continue;
            }

            $trade = new Trade($payload);

            // publish
            $symbol =& $payload['s'];
            $this->ch->publish(new Message(serialize($trade)), self::EXCHANGE, strtolower("trade.$symbol"));
        }
        return 100; // Disconnected. Restart by supervisor
    }

    private function connectAndSubscribe(array $topics) : void
    {
        $this->ws = new Client('wss://stream.binance.com:9443/ws/bookTicker');
        $this->ws->setTimeout(self::TIMEOUT);

        $payload = [
            'id' => 2,
            'method' => 'SUBSCRIBE',
            'params' => $topics
        ];
        $this->ws->send(json_encode($payload, JSON_THROW_ON_ERROR));

        $resp = $this->ws->receive();
        $resp = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
        if (!array_key_exists('result', $resp) || $resp['result'] !== null) {
            throw new \RuntimeException("Failed to subscribe\n" . json_encode($resp, JSON_THROW_ON_ERROR));
        }

        $this->log->debug('Connected and subscribed to ' . implode(', ', $topics));
    }
}
