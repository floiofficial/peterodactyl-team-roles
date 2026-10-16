<?php

// Team management - by floiofficial.
// Support tickets: three tables of their own (prefix ext_team_roles_). Existing panel tables are NOT
// changed. Also adds the default ticket rights to roles that already exist (update from 1.0.x).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ext_team_roles_tickets')) {
            Schema::create('ext_team_roles_tickets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();   // author; no foreign key on purpose
                $table->string('subject', 150);
                $table->text('description');
                $table->string('priority', 16)->default('normal');
                $table->string('status', 16)->default('open')->index();
                $table->unsignedInteger('activity_seq')->default(1);
                $table->unsignedBigInteger('last_actor_id')->nullable();
                $table->timestamp('last_activity_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ext_team_roles_ticket_messages')) {
            Schema::create('ext_team_roles_ticket_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ticket_id')->index();
                $table->unsignedBigInteger('user_id');
                $table->text('body');
                $table->boolean('is_staff')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ext_team_roles_ticket_reads')) {
            Schema::create('ext_team_roles_ticket_reads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedInteger('seen_seq')->default(0);
                $table->timestamps();
                $table->unique(['ticket_id', 'user_id']);
            });
        }

        $this->upgradeExistingRoles();
    }

    /** Roles created by version 1.0.x: add the default ticket rights and English names. */
    private function upgradeExistingRoles(): void
    {
        if (! Schema::hasTable('ext_team_roles_roles')) {
            return;   // fresh install: the roles are created with the new defaults on first access
        }

        $all = ['tickets.view', 'tickets.reply', 'tickets.manage', 'tickets.delete', 'tickets.notify'];
        $staff = ['tickets.view', 'tickets.reply', 'tickets.notify'];
        $defaults = ['administrator' => $all, 'moderator' => $staff, 'friend' => $staff];

        foreach (DB::table('ext_team_roles_roles')->get(['id', 'slug', 'name', 'permissions', 'is_system']) as $role) {
            $update = [];

            $permissions = json_decode((string) $role->permissions, true);
            $permissions = is_array($permissions) ? $permissions : [];
            $hasTickets = count(array_filter($permissions, static fn ($p) => is_string($p) && str_starts_with($p, 'tickets.'))) > 0;
            if (! $hasTickets && isset($defaults[$role->slug])) {
                $update['permissions'] = json_encode(array_values(array_unique(array_merge($permissions, $defaults[$role->slug]))));
            }

            if ($role->slug === 'friend' && $role->name === 'Freund') {
                $update['name'] = 'Friend';
            }
            if ($role->slug === 'user' && $role->name === 'Benutzer / Kunde') {
                $update['name'] = 'User / Customer';
            }

            if ($update !== []) {
                $update['updated_at'] = date('Y-m-d H:i:s');
                DB::table('ext_team_roles_roles')->where('id', $role->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_team_roles_ticket_reads');
        Schema::dropIfExists('ext_team_roles_ticket_messages');
        Schema::dropIfExists('ext_team_roles_tickets');
    }
};
