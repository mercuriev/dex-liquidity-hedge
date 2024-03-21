<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DownCommand extends Command
{
    public function __construct()
    {
        parent::__construct('down');
    }

    protected function configure(): void
    {
        $this->setDescription('SELL borrowed asset when price goes lower.')
            ->addArgument('SYMBOL', InputArgument::REQUIRED, )
            ->addArgument('PRICE', InputArgument::REQUIRED, )
            ->addArgument('AMOUNT', InputArgument::OPTIONAL, 'Max borrowable if empty');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Hello World');

        return Command::SUCCESS;
    }
}
