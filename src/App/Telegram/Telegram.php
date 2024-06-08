<?php
namespace App\Telegram;

use Laminas\ServiceManager\ServiceManager;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Commands\Command;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\TelegramLog;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Telegram extends \Longman\TelegramBot\Telegram
{
    public readonly ServiceManager $sm;

    /**
     * Container is required to build bot commands.
     *
     * @param ServiceManager $sm
     * @return static
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws TelegramException
     */
    static public function factory(ServiceManager $sm): self
    {
        $config = $sm->get('config');

        $key = $config['telegram']['api-key'];
        if (!$key) throw new \RuntimeException('set API KEY in config/local/');

        $username = $config['telegram']['username'];
        if (!$username) throw new \RuntimeException('set username in config/local/');

        $self = new static($key, $username);
        $sm->setService(static::class, $self);
        $self->sm = $sm;

        $mysql = array_merge($config['db'], $config['telegram']['db']);
        $self->enableMySql([
            'host'      => $mysql['hostname'],
            'user'      => $mysql['username'],
            'password'  => $mysql['password'],
            'database'  => $mysql['database']
        ]);

        $self->addCommandClasses($config['telegram']['handlers']);
        $self->enableAdmins($config['telegram']['admins'] ?? []);

        return $self;
    }

    public function getCommandObject(string $command, string $filepath = ''): ?Command
    {
        if (isset($this->commands_objects[$command])) {
            return $this->commands_objects[$command];
        }

        $which = [Command::AUTH_SYSTEM];
        $this->isAdmin() && $which[] = Command::AUTH_ADMIN;
        $which[] = Command::AUTH_USER;

        foreach ($which as $auth) {
            $command_class = $this->getCommandClassName($auth, $command, $filepath);

            if ($command_class) {
                if (!str_starts_with($command_class, 'Longman')) {
                    $command_obj = $this->sm->get($command_class);
                    $command_obj->setUpdate($this->update);
                } else {
                    $command_obj = new $command_class($this, $this->update);
                }

                if ($auth === Command::AUTH_SYSTEM && $command_obj instanceof SystemCommand) {
                    return $command_obj;
                }
                if ($auth === Command::AUTH_ADMIN && $command_obj instanceof AdminCommand) {
                    return $command_obj;
                }
                if ($auth === Command::AUTH_USER && $command_obj instanceof UserCommand) {
                    return $command_obj;
                }
            }
        }

        return null;
    }
    public function addCommandClass(string $command_class): \Longman\TelegramBot\Telegram
    {
        if (!$command_class || !class_exists($command_class)) {
            $error = sprintf('Command class "%s" does not exist.', $command_class);
            TelegramLog::error($error);
            throw new \InvalidArgumentException($error);
        }

        if (!is_a($command_class, Command::class, true)) {
            $error = sprintf('Command class "%s" does not extend "%s".', $command_class, Command::class);
            TelegramLog::error($error);
            throw new \InvalidArgumentException($error);
        }

        // Dummy object to get data from.
        $command_object = $this->sm->get($command_class);

        $auth = null;
        $command_object->isSystemCommand() && $auth = Command::AUTH_SYSTEM;
        $command_object->isAdminCommand() && $auth = Command::AUTH_ADMIN;
        $command_object->isUserCommand() && $auth = Command::AUTH_USER;

        if ($auth) {
            $command = mb_strtolower($command_object->getName());

            $this->command_classes[$auth][$command] = $command_class;
        }

        return $this;
    }
}
