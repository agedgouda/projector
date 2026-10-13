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
        Schema::table('tus_uploads', function (Blueprint $table) {
            // The recording document these chunks are being assembled into. Set the moment a
            // recording is finished (or recovered), and kept until the audio is safely attached
            // to that document — see AssembleBrowserRecording. Nulled when the document itself
            // is deleted, which together with claimed_at tells the cleanup command the chunks
            // were deliberately thrown away rather than abandoned.
            $table->foreignUuid('document_id')->nullable()->after('creator_id')->constrained()->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tus_uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
            $table->dropColumn('claimed_at');
        });
    }
};
