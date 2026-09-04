<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->foreignUlid('cashier_employee_id')
                ->nullable()
                ->after('intake_by_employee_id')
                ->constrained('employees')
                ->nullOnDelete();
            $table->index(['cashier_employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropForeign(['cashier_employee_id']);
            $table->dropIndex(['cashier_employee_id', 'created_at']);
            $table->dropColumn('cashier_employee_id');
        });
    }
};
