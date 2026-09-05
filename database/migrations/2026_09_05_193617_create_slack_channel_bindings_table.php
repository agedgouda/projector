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
        Schema::create('slack_channel_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('slack_workspace_id')->constrained()->cascadeOnDelete();
            $table->string('channel_id');
            $table->string('channel_name');
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->unique(['slack_workspace_id', 'channel_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_channel_bindings');
    }
};
