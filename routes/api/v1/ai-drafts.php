<?php

use App\Http\Controllers\Api\V1\AiDraftController;
use Illuminate\Support\Facades\Route;

// Draf Agen AI: hanya pemilik (lihat AiDraftController). Simpan lewat endpoint modul + ai_draft_id.
Route::get('ai-drafts', [AiDraftController::class, 'index'])->name('ai-drafts.index');
Route::get('ai-drafts/{uuid}', [AiDraftController::class, 'show'])->whereUuid('uuid')->name('ai-drafts.show');
Route::delete('ai-drafts/{uuid}', [AiDraftController::class, 'destroy'])->whereUuid('uuid')->name('ai-drafts.destroy');
