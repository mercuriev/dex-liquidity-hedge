<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Amqp\Channel;
use Laminas\Db\Adapter\Adapter;
use Laminas\Log\Logger;

/**
 * Persist trades to db.
 */
class DbCommand extends Command
{
    private Adapter $db;
    private Logger $log;
    private Channel $ch;

    const BATCH_SIZE = 50;
    private array $batch = [];

    public function getName(): string
    {
        return 'db';
    }

    public function __construct(Adapter $db, Logger $log, Channel $ch)
    {
        $this->db = $db;
        $this->log = $log;
        $this->ch = $ch;

        parent::__construct();
    }

    public function __invoke(\Bunny\Message $req, \Bunny\Channel $ch): bool
    {
        $msg = unserialize($req->content);

        $time = (string) $msg['T'] / 1000;
        $time = sprintf('%.3F', $time);
        $time = \Datetime::createFromFormat('U.v', $time);
        $time = $time->format('Y-m-d H:i:s.v');

        $this->batch[] = [
            (int) $msg['t'],
            $time,
            $msg['s'],
            (string) $msg
        ];

        if (count($this->batch) >= self::BATCH_SIZE) {
            $sql = 'INSERT IGNORE INTO trade VALUES ';
            foreach ($this->batch as $row) {
                $sql .= '(?, ?, ?, ?),';
            }
            $sql = substr($sql, 0, -1);

            $values = [];
            foreach ($this->batch as $row) {
                foreach ($row as $value) {
                    $values[] = $value;
                }
            }

            $this->db->query($sql, $values);
            $this->batch = [];
        }

        return $ch->ack($req);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $q = 'db';

        $this->ch->queueDeclare($q);
        $this->ch->bind($q, WatchCommand::EXCHANGE, [], 'trade.*');
        $this->ch->bunny->consume($this, $q);
        $this->ch->bunny->qos(0, self::BATCH_SIZE);

        $this->log->debug("Saving market...");
        $this->ch->run();

        return 100; // restart by supervisor
    }
}
