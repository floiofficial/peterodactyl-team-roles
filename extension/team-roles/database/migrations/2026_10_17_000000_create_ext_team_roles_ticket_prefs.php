<?php

// Team management - by floiofficial.
// Per-user setting "send me an e-mail when there is a new ticket message" (default: on).
// A row only exists once a user changed the setting. Idempotent.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ext_team_roles_ticket_prefs')) {
            Schema::create('ext_team_roles_ticket_prefs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();   // no foreign key on purpose
                $table->boolean('email_notifications')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_team_roles_ticket_prefs');
    }
};
