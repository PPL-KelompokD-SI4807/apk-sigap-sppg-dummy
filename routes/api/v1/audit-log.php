<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use Illuminate\Support\Facades\Route;

// #172 (P13): log audit khusus Admin (AuditLogPolicy), hanya baca. `opsi` didaftarkan sebelum {id}.
Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
Route::get('audit-log/opsi', [AuditLogController::class, 'opsi'])->name('audit-log.opsi');
Route::get('audit-log/{id}', [AuditLogController::class, 'show'])->whereNumber('id')->name('audit-log.show');
