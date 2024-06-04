<?php

namespace App\Binance;

use Binance\Chart\Minutes;
use Binance\Chart\Seconds;

/**
 * Temporary class just to avoid deprecation errors while storing Charts.
 * TODO Chart object in Binance library
 */
class MarginIsolatedApi extends \Binance\MarginIsolatedApi
{
    public Seconds $s;
    public Minutes $m;
}
