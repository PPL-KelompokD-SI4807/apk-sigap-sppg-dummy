<?php

namespace App\Events;

/** Pengeluaran periode >= 100% dana yang dicairkan (anggaran habis). */
class AnggaranMencapai100 extends AnggaranEvent
{
    public function ambang(): string
    {
        return '100';
    }
}
