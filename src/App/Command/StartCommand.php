<?php

namespace App\Command;

use Amqp\Channel;
use App\Hedge\UnitaryHedgeBuy;
use App\Hedge\UnitaryHedgeSell;
use Binance\Event\Trade;
use Binance\MarginIsolatedApi;
use Bunny\Message;
use Laminas\Log\Filter\Priority;
use Laminas\Log\Logger;
use Laminas\Log\Writer\AbstractWriter;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    /** @var string Active running hedge class */
    private string $tag = '';

    public function __construct(protected readonly Logger            $log,
                                protected readonly MarginIsolatedApi $api,
                                protected readonly Channel           $ch
    )
    {
        parent::__construct();
    }

    public function getName() : string
    {
        return 'start';
    }

    protected function configure(): void
    {
        // send important log entries to telegram
        $this->log->addWriter(new class($this->ch) extends AbstractWriter
        {
            public function __construct(private readonly Channel $ch) {
                parent::__construct([
                    'filters' => [
                        new Priority(Logger::INFO)
                    ]
                ]);
                $ch->exchangeDeclare('log', type: 'topic');
            }
            protected function doWrite(array $event): void
            {
                $this->ch->bunny->publish($event['message'], 'log', strtolower($event['priorityName']));
            }
        });
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $q = 'control';
        $this->ch->exchangeDeclare('hedge', type: 'topic');
        $this->ch->queueDeclare($q);
        $this->ch->bind('control', 'hedge', [], '*');
        $this->ch->bunny->consume($this, $q);
        $this->ch->bunny->qos(0, 1);

        $this->log->debug("Waiting for commands...");
        $this->ch->run();

        return Command::FAILURE; // restart by supervisor
    }

    public function __invoke(\Bunny\Message $msg, \Bunny\Channel $ch): bool
    {
        $this->log->debug('Got message ' . $msg->routingKey . ': ' . $msg->content);

        if ($msg->content) {
            list($symbol, $low, $high) = explode(' ', $msg->content);
            $symbol = strtolower($symbol);
            $this->api->symbol = strtoupper($symbol);
        }

        switch ($msg->routingKey) {
            case 'sell':
                $command = new UnitaryHedgeSell($this->log, $this->api, $low, $high);
                $q = $this->ch->queueDeclare('hedge.sell');
                $ch->queuePurge('hedge.sell'); // if existed
                break;

            case 'buy':
                $command = new UnitaryHedgeBuy($this->log, $this->api, $low, $high);
                $q = $this->ch->queueDeclare('hedge.buy');
                $ch->queuePurge('hedge.buy'); // if existed
                break;

            case 'cancel':
                $ch->cancel($this->tag);
                $ch->queueDelete('hedge.sell');
                $ch->queueDelete('hedge.buy');
                // proceed to ack

            default: return $ch->ack($msg);
        }

        // start processing trades
        $ch->queueBind($q, 'binance', routingKey: "trade.$symbol");
        $handler = function(Message $msg, \Bunny\Channel $ch) use ($command) {
            $trade = unserialize($msg->content);
            if (!$trade instanceof Trade) throw new \InvalidArgumentException(gettype($trade));
            $command($trade, $ch);
            return $ch->ack($msg);
        };
        $this->tag = $ch->consume($handler, $q);

        return $ch->ack($msg);
    }
}
