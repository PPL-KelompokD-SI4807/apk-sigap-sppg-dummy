<?php

namespace App\Events;

/** Pengeluaran periode > dana yang dicairkan (sisa anggaran negatif). */
class AnggaranMelampaui extends AnggaranEvent
{
    public function ambang(): string
    {
        return 'melampaui';
    }
}
