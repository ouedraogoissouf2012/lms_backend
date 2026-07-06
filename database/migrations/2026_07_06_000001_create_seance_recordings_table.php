<?php

use App\Enums\SeanceRecordingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seance_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seance_id')->constrained('seances')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained('chapters')->nullOnDelete();

            $table->string('provider', 64)->nullable();
            $table->string('external_recording_id')->nullable();
            $table->string('status', 32)->default(SeanceRecordingStatus::Idle->value);
            $table->string('recording_url', 2048)->nullable();
            $table->string('storage_disk', 64)->nullable();
            $table->string('storage_path', 2048)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stopped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('consent_policy_version', 64)->nullable();
            $table->timestamp('expires_at')->nullable();

            // Nullable unique: only active lifecycle rows receive this value.
            $table->string('active_lock_key')->nullable()->unique();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['seance_id', 'status']);
            $table->index(['lesson_id', 'chapter_id']);
            $table->index(['provider', 'external_recording_id']);
            $table->index(['status', 'processed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seance_recordings');
    }
};
