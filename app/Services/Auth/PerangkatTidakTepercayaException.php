<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Jalur login tanpa token bot dipanggil untuk perangkat yang (kini) tidak tepercaya: dicabut, kedaluwarsa,
 * atau tidak ada. Pemanggil wajib meminta token bot; kepercayaan tidak pernah diperbarui di jalur ini.
 */
final class PerangkatTidakTepercayaException extends RuntimeException {}
