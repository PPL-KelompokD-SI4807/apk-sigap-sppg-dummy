<?php

namespace App\Services\Ai\Tools;

use RuntimeException;

/**
 * Dilempar tool BACA dari handle() bila argumen lolos skema tetapi ditolak aturan Form Request modul (mis. tanggal
 * dan bulan diisi bersamaan). ReadTool::execute() memetakannya ke ToolResult::invalid (bukan `gagal` + report()).
 * Pesan WAJIB disusun server (pesan validasi Laravel/teks tetap), tanpa menyalin nilai masukan mentah.
 */
class ArgumenTidakValid extends RuntimeException {}
