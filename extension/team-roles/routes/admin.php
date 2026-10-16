<?php

// Team management - by floiofficial.
// Loaded under /api/admin/extensions/team-roles/... Owner only (checked again in the controller).

use Illuminate\Support\Facades\Route;
use TeamRoles\Http\Controllers\TeamController;

Route::get('/health', [TeamController::class, 'health']);
Route::get('/roles', [TeamController::class, 'roles']);
Route::put('/roles/{role}', [TeamController::class, 'updateRole'])->where('role', '[a-z0-9_-]+');
Route::get('/members', [TeamController::class, 'members']);
Route::get('/members/{userId}', [TeamController::class, 'member'])->whereNumber('userId');
Route::put('/members/{userId}', [TeamController::class, 'updateMember'])->whereNumber('userId');
