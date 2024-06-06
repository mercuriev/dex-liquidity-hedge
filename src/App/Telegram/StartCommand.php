<?php

namespace App\Telegram;

use Amqp\Channel;
use App\Telegram\Callback\CancelCallbackHandler;
use Laminas\Db\Adapter\Adapter;
use Laminas\Log\Logger;
use Longman\TelegramBot\Commands\SystemCommands\CallbackqueryCommand;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\TelegramLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    public function getName(): ?string
    {
        return 'telegram:start';
    }

    public function __construct(
        protected Adapter $db,
        protected Logger $log,
        protected Telegram $tg,
        protected Channel $channel
    )
    {
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->log->info('Started telegram bot.');

        TelegramLog::initialize($this->log);
        CallbackqueryCommand::addCallbackHandler(new CancelCallbackHandler());

        $pid = pcntl_fork();
        if ($pid == -1) throw new \RuntimeException('Forking failed.');
        else if ($pid) {
            $this->listenToTelegram();
        }
        else {
            $this->listenToRabbit();
        }

        return 100; // restart
    }

    private function listenToTelegram() : void
    {
        $this->log->debug('Polling telegram updates...');
        do {
            try {
                $res = $this->tg->handleGetUpdates([
                    'allowed_updates' => [Update::TYPE_MESSAGE, Update::TYPE_CALLBACK_QUERY]
                ]);
            }
            catch (\Throwable $e) {
                $this->log->err($e);
                // keep running
            }
        }
        while(!sleep(1));
    }

    private function listenToRabbit(): void
    {
        $tg = $this->tg;
        $send = function (\Bunny\Message $message, \Bunny\Channel $channel) use ($tg) : void
        {
            $admins = $tg->getAdminList();
            foreach ($admins as $admin) {
                Request::sendMessage([
                    'chat_id' => $admin,
                    'text' => $message->content
                ]);
            }
            $channel->ack($message);
        };

        $this->channel->queueDeclare('telegram');
        $this->channel->bind('telegram', 'log', [], '*');
        $this->channel->bunny->consume($send, 'telegram');
        $this->channel->bunny->qos(0, 1);

        $this->log->debug('Listening to AMQP...');
        $this->channel->run();
    }
}
