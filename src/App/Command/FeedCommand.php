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
 * Fetch data from API websockets and then publish each trade to amq.topic with key binance:market.SYMBOL
 */
class FeedCommand extends Command
{
    public const EXCHANGE = 'binance';
    private const TIMEOUT = 10;

    private Client $ws;
    private int $id = 0;

    public function getName() : string
    {
        return 'feed';
    }

    public function __construct(protected Channel $ch, protected WebsocketsApi $binance, protected Logger $log)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('symbols', InputArgument::IS_ARRAY | InputArgument::OPTIONAL);
    }

    public function execute(InputInterface $input, OutputInterface $output) : int
    {
        $symbols = $input->getArgument('symbols');
        foreach ($symbols as $symbol) {
            $this->subscribe($symbol);
        }

        //
        while (isset($this->ws) && $this->ws->isConnected())
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
}
