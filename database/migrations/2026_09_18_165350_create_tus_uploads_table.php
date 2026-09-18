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
        Schema::create('tus_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('creator_id')->constrained('users')->onDelete('cascade');
            // 'partial': one MediaRecorder-interval chunk. 'final': the concatenation of an
            // ordered list of completed partials into one file, created in a single request
            // once the client has finished recording — see TusUploadController::create().
            $table->enum('kind', ['partial', 'final']);
            $table->unsignedBigInteger('upload_length');
            $table->unsignedBigInteger('upload_offset')->default(0);
            // Relative to the private tus-uploads scratch disk (config/filesystems.php) — never
            // the same disk/location as a finished Document's own 'recording' media collection.
            $table->string('storage_path');
            // For kind=partial: filename/filetype metadata sent by the client. For kind=final:
            // the ordered array of partial upload ids it was concatenated from, plus the same
            // filename/filetype/recorded_at the browser attached to the recording as a whole.
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tus_uploads');
    }
};
