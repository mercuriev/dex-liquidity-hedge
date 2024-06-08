<?php
namespace App\Command;

use Amqp\Channel;
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
 * Fetch data from API websockets and then publish each trade to amq.topic with key binance:market.SYMBOL
 *
 * To sub/unsub send a message to feed exchange with rkey sub/unsub and body of symbol.
 */
class FeedCommand extends Command
{
    public const EXCHANGE = 'binance';
    private const TIMEOUT = 45;  // timeout must be high because there might be just no trades happening
    public const QUEUE = 'feed';

    private Client $ws;
    private int $id = 0;
    private int $lastGet = 0;

    public function getName() : string
    {
        return 'feed';
    }

    public function __construct(private array $config, protected Channel $ch, protected WebsocketsApi $binance, protected Logger $log)
    {
        parent::__construct();
        $this->config = $this->config['feed'] ?? [];
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('symbols', InputArgument::IS_ARRAY | InputArgument::OPTIONAL);
    }

    public function execute(InputInterface $input, OutputInterface $output) : int
    {
        // define control queue
        $ch = $this->ch->bunny; // back-compat
        $ch->exchangeDeclare(self::EXCHANGE, 'topic');
        $ch->exchangeDeclare('feed', 'topic');
        $ch->queueDelete(self::QUEUE);
        $ch->queueDeclare(self::QUEUE);
        $ch->queueBind(self::QUEUE, 'feed', 'sub');
        $ch->queueBind(self::QUEUE, 'feed', 'unsub');

        // subscribe to startup symbols, if any
        $symbols = array_merge(
            $this->config['symbols'] ?? [],
            $input->getArgument('symbols')
        );
        foreach ($symbols as $symbol) {
            $this->ch->bunny->publish($symbol, 'feed', 'sub');
        }

        // main loop
        do {
            // poll queue (rate limited)
            if (time() != $this->lastGet) {
                $msg = $ch->get(self::QUEUE);
                if ($msg) {
                    $this->processMessage($msg);
                }
            }

            // fetch websockets
            if (isset($this->ws) && $this->ws->isConnected())
            {
                try {
                    $res = $this->ws->receive();
                    $payload = json_decode($res, true, 512, JSON_THROW_ON_ERROR);
                    switch (@$payload['e']) {
                        case 'trade':
                            $payload = new Trade($payload);
                            break;

                        default: continue 2;
                    }

                    // publish
                    $this->ch->bunny->publish(
                        serialize($payload),
                        self::EXCHANGE,
                        strtolower("trade.{$payload['s']}")
                    );
                }
                catch (TimeoutException $e) {
                    $this->log->err('feed: '.$e->getMessage());
                    break;
                }
            }
            else {
                // wait for messages if not subscribed yet
                usleep(500000);
            }
        }
        while (true);

        return 100; // Disconnected. Restart by supervisor
    }

    private function subscribe(string $symbol, bool $unsub = false) : void
    {
        if (!isset($this->ws)) {
            $this->ws = new Client('wss://stream.binance.com:9443/ws/bookTicker', [
                'timeout' => self::TIMEOUT,
                #'logger'  => $this->log // lots of debug data
            ]);
        }

        $payload = [
            'id' => ++$this->id,
            'method' => $unsub ? 'UNSUBSCRIBE' : 'SUBSCRIBE',
            'params' => [strtolower($symbol) . '@trade']
        ];
        $this->ws->send(json_encode($payload, JSON_THROW_ON_ERROR));

        $msg = $unsub ? 'Canceled' : 'Subscribed to';
        $this->log->debug("$msg {$payload['params'][0]}");
    }

    private function unsubscribe(string $symbol) : void
    {
        $this->subscribe($symbol, true);
    }

    private function processMessage(\Bunny\Message $msg) : void
    {
        switch ($msg->routingKey) {
            case 'sub':
            case 'unsub':
                $this->subscribe($msg->content, $msg->routingKey === 'unsub');
        }
        $this->ch->bunny->ack($msg);
    }
}
