<?php

// Teamverwaltung - von floiofficial.
// Wird unter /api/client/extensions/team-roles/... geladen. Jeder angemeldete Nutzer darf nur
// die EIGENE Rolle abfragen.

use Illuminate\Support\Facades\Route;
use TeamRoles\Http\Controllers\TeamController;

Route::get('/me', [TeamController::class, 'me']);
