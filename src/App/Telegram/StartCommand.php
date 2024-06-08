<?php

namespace App\Telegram;

use Amqp\Channel;
use App\Telegram\Callback\CancelCallbackHandler;
use Bunny\Message;
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
        TelegramLog::initialize($this->log);
        CallbackqueryCommand::addCallbackHandler(new CancelCallbackHandler());

        $this->channel->queueDeclare('telegram');
        $this->channel->bind('telegram', 'log', [], '*');

        $this->log->debug('Polling telegram updates...');
        do {
            try {
                $this->tg->handleGetUpdates([
                    'allowed_updates' => [Update::TYPE_MESSAGE, Update::TYPE_CALLBACK_QUERY],
                    'timeout' => 5 // long-polling. must be lower than amqp heartbeat (60s), but small enough to let logging
                ]);

                do {
                    $msg = $this->channel->bunny->get('telegram');
                    if ($msg) {
                        $this->sendLogMessage($msg, $this->channel->bunny);
                    }
                } while ($msg);
            }
            catch (\Throwable $e) {
                $this->log->err($e);
                // keep running
            }
        }
        while(!sleep(1));

        return 100; // restart
    }

    private function sendLogMessage(Message $message, \Bunny\Channel $channel): void
    {
        $admins = $this->tg->getAdminList();
        foreach ($admins as $admin) {
            Request::sendMessage([
                'chat_id' => $admin,
                'text' => $message->content
            ]);
        }
        $channel->ack($message);
    }
}
