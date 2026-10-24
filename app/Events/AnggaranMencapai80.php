<?php

namespace App\Events;

/** Pengeluaran periode >= 80% dana yang dicairkan. */
class AnggaranMencapai80 extends AnggaranEvent
{
    public function ambang(): string
    {
        return '80';
    }
}
