<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('pending_assignee_invitation_id')
                ->nullable()
                ->after('assignee_id')
                ->constrained('organization_invitations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\OrganizationInvitation::class, 'pending_assignee_invitation_id');
            $table->dropColumn('pending_assignee_invitation_id');
        });
    }
};
