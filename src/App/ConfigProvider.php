<?php
namespace App;

class ConfigProvider
{
    public function __invoke() : array
    {
        ini_set('bcmath.scale', 8);

        return [];
    }
}
