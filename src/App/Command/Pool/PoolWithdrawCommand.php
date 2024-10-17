<?php
namespace App\Command\Pool;

use Amqp\Channel;
use Amqp\Message;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Binance\json_encode_pretty;

class PoolWithdrawCommand extends Command
{
    const string EXCHANGE = 'arbitrum';
    const int RPC_TIMEOUT = 30;

    public function __construct(
        private readonly Logger $log,
        private readonly Channel $ch
    )
    {
        parent::__construct('pool:withdraw');
    }

    public function configure(): void
    {
        $this->addArgument('tokenId', InputArgument::REQUIRED, 'Token ID');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $tokenId = $input->getArgument('tokenId');

        $message = new Message(['tokenId' => $tokenId]);
        $rkey = "withdraw";
        $reply = $this->ch->call($message, self::EXCHANGE, $rkey, self::RPC_TIMEOUT);
        if ($reply) {
            $reply = json_decode($reply->content, true, 512, JSON_THROW_ON_ERROR);
            $this->log->debug(json_encode_pretty($reply));
        }
        else $this->log->error("No reply from $rkey");

        return Command::SUCCESS;
    }
}
