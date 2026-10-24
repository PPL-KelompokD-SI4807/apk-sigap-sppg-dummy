<?php

namespace App\Events;

/**
 * Waktu tiba melewati acuan (estimasi perjalanan / jam rencana tiba) lebih dari toleransi.
 * Dipancarkan sekali saat bukti dicatat (FR-10 "bukti distribusi terlambat").
 */
class DistribusiTerlambat extends BuktiDistribusiEvent {}
