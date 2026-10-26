<?php

namespace App\Services\Storage;

use RuntimeException;

/** Berkas unggahan tidak lolos validasi keamanan; pesan aman ditampilkan ke pengguna. */
class BerkasDitolak extends RuntimeException {}
