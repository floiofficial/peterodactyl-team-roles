<?php

// Teamverwaltung - von floiofficial.
// Legt nur zwei eigene Tabellen an (Prefix ext_team_roles_). Bestehende Panel-Tabellen
// werden hier NICHT veraendert. Die Standard-Rollen werden beim ersten Zugriff angelegt.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'root_admin')) {
            throw new RuntimeException(
                'Teamverwaltung: Tabelle "users" bzw. Spalte "root_admin" nicht gefunden. '
                . 'Installation abgebrochen, es wurde nichts verändert.'
            );
        }

        if (! Schema::hasTable('ext_team_roles_roles')) {
            Schema::create('ext_team_roles_roles', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 32)->unique();
                $table->string('name', 64);
                $table->unsignedSmallInteger('rank');
                $table->json('permissions');
                $table->boolean('is_system')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ext_team_roles_members')) {
            Schema::create('ext_team_roles_members', function (Blueprint $table) {
                $table->id();
                // Bewusst ohne Fremdschluessel: unabhaengig vom Typ von users.id,
                // Waisen-Eintraege raeumt die Extension selbst auf.
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('role_slug', 32)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_team_roles_members');
        Schema::dropIfExists('ext_team_roles_roles');
    }
};
