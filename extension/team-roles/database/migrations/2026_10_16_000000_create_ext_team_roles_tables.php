<?php

// Team management - by floiofficial.
// Creates only two tables of its own (prefix ext_team_roles_). Existing panel tables are NOT
// changed here. The default roles are created on first access.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'root_admin')) {
            throw new RuntimeException(
                'Team management: table "users" or column "root_admin" not found. '
                . 'Installation aborted, nothing was changed.'
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
                // Deliberately no foreign key: independent of the type of users.id,
                // orphaned entries are cleaned up by the extension itself.
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
