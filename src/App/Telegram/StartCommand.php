<?php

namespace App\Telegram;

use App\Telegram\Callback\CancelCallbackHandler;
use App\Telegram\Handler\CallbackHandler;
use Laminas\Db\Adapter\Adapter;
use Laminas\Log\Logger;
use Longman\TelegramBot\Commands\SystemCommands\CallbackqueryCommand;
use Longman\TelegramBot\Entities\Update;
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

    public function __construct(protected Adapter $db, protected Logger $log, protected Telegram $tg)
    {
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->log->info('Started telegram bot.');

        TelegramLog::initialize($this->log, $this->log);

        CallbackqueryCommand::addCallbackHandler(new CancelCallbackHandler());

        $allowed = [Update::TYPE_MESSAGE, Update::TYPE_CALLBACK_QUERY];
        do {
            try {
                $res = $this->tg->handleGetUpdates(['allowed_updates' => $allowed]);
            }
            catch (\Throwable $e) {
                $this->log->err($e);
                // keep running
            }
        }
        while(!sleep(1));

        return 0;
    }
}
