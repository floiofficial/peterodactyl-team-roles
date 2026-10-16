<?php

// Team management - by floiofficial.
// File attachments of tickets (metadata only; the files live in storage/app/team-roles/tickets/).
// Idempotent.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ext_team_roles_ticket_attachments')) {
            Schema::create('ext_team_roles_ticket_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ticket_id')->index();
                $table->unsignedBigInteger('message_id')->nullable()->index();   // null = attached to the ticket itself
                $table->unsignedBigInteger('user_id');
                $table->string('name', 120);          // cleaned display name
                $table->string('kind', 8);            // png|jpg|gif|webp|txt (decided by content)
                $table->unsignedInteger('size');
                $table->string('stored', 64);         // random file name on disk
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_team_roles_ticket_attachments');
    }
};
