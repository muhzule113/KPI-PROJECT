<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_feedback_id')->unique()->constrained('customer_feedbacks')->cascadeOnDelete();
            $table->foreignId('service_ticket_id')->constrained('service_tickets')->cascadeOnDelete();
            $table->foreignUlid('assigned_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('first_contacted_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('contact_channel', 30)->nullable();
            $table->string('outcome', 30)->nullable();
            $table->text('response_summary')->nullable();
            $table->json('evidence_json')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestamps();

            $table->index(['assigned_employee_id', 'status', 'due_at']);
            $table->index(['service_ticket_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_follow_ups');
    }
};
