<?php

// Team management - by floiofficial.
// Loaded under /api/client/extensions/team-roles/... Any signed-in user may query their OWN role
// and use the support ticket endpoints (visibility is enforced per ticket in the controller).

use Illuminate\Support\Facades\Route;
use TeamRoles\Http\Controllers\TeamController;
use TeamRoles\Http\Controllers\TicketController;

Route::get('/me', [TeamController::class, 'me']);

Route::get('/tickets/summary', [TicketController::class, 'summary']);
Route::get('/tickets/settings', [TicketController::class, 'settings']);
Route::put('/tickets/settings', [TicketController::class, 'updateSettings'])->middleware('throttle:30,1');
Route::get('/tickets', [TicketController::class, 'index']);
Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:10,1');
Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket');
Route::post('/tickets/{ticket}/messages', [TicketController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:30,1');
Route::get('/tickets/{ticket}/attachments/{attachment}', [TicketController::class, 'download'])->whereNumber('ticket')->whereNumber('attachment');
Route::put('/tickets/{ticket}', [TicketController::class, 'update'])->whereNumber('ticket');
Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])->whereNumber('ticket');
