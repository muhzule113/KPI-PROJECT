<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table): void {
            $table->text('passcode_or_pattern')->nullable()->change();
            $table->string('customer_consent_status', 20)->default('pending');
            $table->dateTime('customer_consent_at')->nullable();
            $table->foreignUlid('customer_consent_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('customer_consent_notes')->nullable();
            $table->string('payment_status', 20)->default('unpaid');
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->dateTime('payment_recorded_at')->nullable();
            $table->foreignUlid('payment_recorded_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('payment_exception_type', 30)->nullable();
            $table->text('payment_exception_reason')->nullable();
            $table->foreignId('payment_exception_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('payment_exception_approved_at')->nullable();
            $table->string('delivery_recipient_type', 20)->nullable();
            $table->string('delivery_recipient_name', 150)->nullable();
            $table->foreignUlid('delivered_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('delivery_notes')->nullable();
            $table->text('unrepairable_reason')->nullable();
            $table->text('customer_declined_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->json('technical_evidence_json')->nullable();
            $table->string('service_category', 50)->default('general');
            $table->string('service_complexity', 20)->default('light');
            $table->string('sla_version', 30)->default('v1');
            $table->dateTime('sla_baseline_due_at')->nullable();
            $table->dateTime('sla_due_at')->nullable();
            $table->dateTime('sla_breached_at')->nullable();
            $table->unsignedInteger('sparepart_wait_minutes')->default(0);
            $table->json('sla_snapshot_json')->nullable();
            $table->dateTime('warranty_expires_at')->nullable();
            $table->string('warranty_review_status', 20)->default('not_applicable');
            $table->text('warranty_review_reason')->nullable();
            $table->foreignId('warranty_reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('warranty_reviewed_at')->nullable();
            $table->index(['payment_status', 'status']);
            $table->index(['warranty_review_status', 'status']);
            $table->index(['sla_due_at', 'status']);
        });

        Schema::table('sparepart_requests', function (Blueprint $table): void {
            $table->text('availability_note')->nullable();
            $table->dateTime('sla_deadline_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignUlid('confirmed_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->index(['status', 'confirmed_at']);
        });

        Schema::table('customer_feedbacks', function (Blueprint $table): void {
            $table->unique('service_ticket_id', 'customer_feedbacks_ticket_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_feedbacks', function (Blueprint $table): void {
            $table->dropUnique('customer_feedbacks_ticket_unique');
        });

        Schema::table('sparepart_requests', function (Blueprint $table): void {
            $table->dropForeign(['confirmed_by_employee_id']);
            $table->dropIndex(['status', 'confirmed_at']);
            $table->dropColumn(['availability_note', 'sla_deadline_at', 'confirmed_at', 'confirmed_by_employee_id']);
        });

        Schema::table('service_tickets', function (Blueprint $table): void {
            $table->dropForeign(['customer_consent_by_employee_id']);
            $table->dropForeign(['payment_recorded_by_employee_id']);
            $table->dropForeign(['delivered_by_employee_id']);
            $table->dropForeign(['payment_exception_approved_by_user_id']);
            $table->dropForeign(['warranty_reviewed_by_user_id']);
            $table->dropIndex(['payment_status', 'status']);
            $table->dropIndex(['warranty_review_status', 'status']);
            $table->dropIndex(['sla_due_at', 'status']);
            $table->dropColumn([
                'customer_consent_status',
                'customer_consent_at',
                'customer_consent_by_employee_id',
                'customer_consent_notes',
                'payment_status',
                'paid_amount',
                'payment_recorded_at',
                'payment_recorded_by_employee_id',
                'payment_exception_type',
                'payment_exception_reason',
                'payment_exception_approved_by_user_id',
                'payment_exception_approved_at',
                'delivery_recipient_type',
                'delivery_recipient_name',
                'delivered_by_employee_id',
                'delivery_notes',
                'unrepairable_reason',
                'customer_declined_reason',
                'cancellation_reason',
                'technical_evidence_json',
                'service_category',
                'service_complexity',
                'sla_version',
                'sla_baseline_due_at',
                'sla_due_at',
                'sla_breached_at',
                'sparepart_wait_minutes',
                'sla_snapshot_json',
                'warranty_expires_at',
                'warranty_review_status',
                'warranty_review_reason',
                'warranty_reviewed_by_user_id',
                'warranty_reviewed_at',
            ]);
            $table->string('passcode_or_pattern', 50)->nullable()->change();
        });
    }
};
