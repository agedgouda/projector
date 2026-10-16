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
        Schema::table('organization_invitations', function (Blueprint $table) {
            // The Resend email id of the most recent invitation email, which Resend's webhook
            // events refer back to, plus the furthest delivery status reported for it.
            $table->string('resend_email_id')->nullable()->index();
            $table->string('email_status')->nullable();
            $table->timestamp('email_status_at')->nullable();
            $table->string('email_status_detail', 500)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table) {
            $table->dropIndex(['resend_email_id']);
            $table->dropColumn(['resend_email_id', 'email_status', 'email_status_at', 'email_status_detail']);
        });
    }
};
