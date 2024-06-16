<?php
namespace App\Command;

use Amqp\Channel;
use Amqp\Message;
use Laminas\Log\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Binance\json_encode_pretty;

class PoolMintCommand extends Command
{
    const string EXCHANGE = 'pool';
    const int RPC_TIMEOUT = 60;

    public function __construct(
        private readonly Logger $log,
        private readonly Channel $ch
    )
    {
        parent::__construct('pool:mint');
    }

    public function configure(): void
    {
        $this
            ->addArgument('pool', InputArgument::REQUIRED, 'Pool Address')
            ->addArgument('low', InputArgument::REQUIRED, 'Low price')
            ->addArgument('high', InputArgument::REQUIRED, 'High price')
            ->addArgument('amount0', InputArgument::REQUIRED, 'token0 amount')
            ->addArgument('amount1', InputArgument::REQUIRED, 'token1 amount');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $pool = $input->getArgument('pool');
        $low = $input->getArgument('low');
        $high = $input->getArgument('high');
        $amount0 = $input->getArgument('amount0');
        $amount1 = $input->getArgument('amount1');

        $content = json_encode_pretty([
            'amount0'   => $amount0,
            'amount1'   => $amount1,
            'low'       => $low,
            'high'      => $high,
        ]);
        $rkey = "$pool.mint";

        $message = new Message($content);

        $this->log->info($content);
        $reply = $this->ch->call($message, self::EXCHANGE, $rkey, self::RPC_TIMEOUT);

        if ($reply) {
            $reply = json_decode($reply->content, true, 512, JSON_THROW_ON_ERROR);
            $this->log->notice("Minted token {$reply['tokenId']}");
        }
        else $this->log->error("No reply from $rkey");

        return Command::SUCCESS;
    }
}
