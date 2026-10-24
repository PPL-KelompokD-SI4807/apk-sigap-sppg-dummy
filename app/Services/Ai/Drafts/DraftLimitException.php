<?php

namespace App\Services\Ai\Drafts;

use RuntimeException;

/** Pengguna sudah mencapai batas draf pending (config ai.drafts.max_pending_per_user). */
class DraftLimitException extends RuntimeException {}
