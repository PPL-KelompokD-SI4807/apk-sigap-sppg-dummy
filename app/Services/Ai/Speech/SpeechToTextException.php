<?php

namespace App\Services\Ai\Speech;

use RuntimeException;

/** Transkripsi gagal/tidak tersedia. Pesan aman ditampilkan ke pengguna (tanpa kunci/isi audio). */
class SpeechToTextException extends RuntimeException {}
