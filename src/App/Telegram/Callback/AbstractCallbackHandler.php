<?php

namespace App\Telegram\Callback;

use App\Telegram\Handler\AbstractHandler;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\TelegramLog;

abstract class AbstractCallbackHandler
{
    /**
     * Trigger if callback_data is one of these.
     */
    protected array $callbacks = [];

    abstract public function run(CallbackQuery $query) : ?ServerResponse;

    /**
     * fter the user presses a callback button,
     * Telegram clients will display a progress bar until you call answerCallbackQuery.
     * It is, therefore, necessary to react by calling answerCallbackQuery
     * even if no notification to the user is needed
     * (e.g., without specifying any of the optional parameters).
     *
     * Empty response if null is returned.
     *
     * @param CallbackQuery $query
     * @return ServerResponse|null
     */
    public function __invoke(CallbackQuery $query): ?ServerResponse
    {
        $name = $query->getData();
        if (in_array($name, $this->callbacks)) {
            return $this->run($query);
        }
        else {
            TelegramLog::notice("No callback handler for: $name");
            return null;
        }
    }
}
