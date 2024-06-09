<?php

namespace Trader;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'commands' => [
                'trader' => TraderCommand::class
            ],
        ];
    }
}
