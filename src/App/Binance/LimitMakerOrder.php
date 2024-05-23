<?php

namespace App\Binance;

class LimitMakerOrder extends \Binance\Order\LimitMakerOrder
{
    public function __toString() : string
    {
        if ($this->isFilled()) {
            $msg = '▶';
            $status = 'SELL' == $this->side ? 'SOLD' : 'BGHT';
        }
        else {
            $msg = '▷';
            $status = $this->side;
        }
        $msg .= sprintf(
            '%-4s %.2f',
            $status,
            $this->price
        );
        $msg .= (isset($this->stopPrice) ? sprintf(' @ %.2f', $this->stopPrice) : '');

        $msg .= ' ('.round($this->quantity * $this->price, 2).')';
        return $msg;
    }
}
