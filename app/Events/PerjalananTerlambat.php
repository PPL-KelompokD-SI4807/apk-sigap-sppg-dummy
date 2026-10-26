<?php

namespace App\Events;

/** Estimasi tiba terlewati tetapi mobil belum tiba (dipancarkan sekali per perjalanan oleh sigap:cek-keterlambatan). */
class PerjalananTerlambat extends PerjalananEvent {}
