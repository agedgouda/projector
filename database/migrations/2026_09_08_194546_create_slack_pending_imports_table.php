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
        // A spreadsheet dropped in a bound Slack channel that AI classification couldn't
        // confidently turn into a task/event import on its own (see ImportSlackFile) — the
        // original file is kept (via Spatie media) so a human can resolve it later on the
        // Import Wizard landing page without needing to re-download it from Slack.
        Schema::create('slack_pending_imports', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_pending_imports');
    }
};
