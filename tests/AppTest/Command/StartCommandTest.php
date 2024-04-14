<?php

namespace AppTest\Command;

use Amqp\Channel;
use App\Command\StartCommand;
use Binance\Event\Trade;
use Binance\MarginIsolatedApi;
use Binance\WebsocketsApi;
use Laminas\Log\Logger;
use PHPUnit\Framework\TestCase;

class StartCommandTest extends TestCase
{
    private $log;
    private $ws;
    private $api;
    private $mq;

    protected function setUp(): void
    {
        $this->log = $this->createMock(Logger::class);
        $this->ws = $this->createMock(WebsocketsApi::class);
        $this->api = $this->createMock(MarginIsolatedApi::class);
        $this->mq = $this->createMock(Channel::class);
    }

    public function testNotifyCrossLowerLimit(): void
    {
        $startCommand = new StartCommand($this->log, $this->ws, $this->api, $this->mq);
        $startCommand->min = 100;
        $startCommand->max = 200;
        $trade = new Trade(['price' => 70]);

        // Expect log to get a info message
        $this->log->expects($this->once())->method('info')->with($this->stringContains('Price has crossed the lower limit'));

        $startCommand->notify($trade);
    }

    public function testNotifyCrossHigherLimit(): void
    {
        $startCommand = new StartCommand($this->log, $this->ws, $this->api, $this->mq);
        $startCommand->min = 100;
        $startCommand->max = 200;
        $trade = new Trade(['price' => 250]);

        // Expect log to get an info message
        $this->log->expects($this->once())->method('info')->with($this->stringContains('Price has crossed the higher limit'));

        $startCommand->notify($trade);
    }
}
