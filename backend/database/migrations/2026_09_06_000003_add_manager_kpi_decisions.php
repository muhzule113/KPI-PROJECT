<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_kpi_items', function (Blueprint $table): void {
            $table->string('manager_decision', 30)->nullable();
            $table->text('manager_note')->nullable();
            $table->json('manager_evidence_json')->nullable();
            $table->foreignId('manager_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('manager_decided_at')->nullable();
            $table->index(['employee_kpi_id', 'manager_decision']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_kpi_items', function (Blueprint $table): void {
            $table->dropForeign(['manager_decided_by']);
            $table->dropIndex(['employee_kpi_id', 'manager_decision']);
            $table->dropColumn([
                'manager_decision',
                'manager_note',
                'manager_evidence_json',
                'manager_decided_by',
                'manager_decided_at',
            ]);
        });
    }
};
