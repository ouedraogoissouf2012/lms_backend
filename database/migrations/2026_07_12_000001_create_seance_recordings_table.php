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
            $table->foreignId('seance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 50)->default('external');
            $table->string('provider_recording_id')->nullable();
            $table->string('status', 20)->default(SeanceRecordingStatus::Idle->value);
            $table->string('recording_url', 2048)->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('active_lock_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['seance_id', 'status']);
            $table->index(['provider', 'provider_recording_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seance_recordings');
    }
};
