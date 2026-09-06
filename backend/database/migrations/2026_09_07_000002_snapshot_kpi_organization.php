<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_kpis', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id_snapshot')->nullable()->index();
            $table->unsignedBigInteger('position_id_snapshot')->nullable()->index();
            $table->string('position_code_snapshot')->nullable();
        });
        DB::table('employee_kpis')->orderBy('id')->chunkById(100, function ($kpis): void {
            foreach ($kpis as $kpi) {
                $employee = DB::table('employees')->where('id', $kpi->employee_id)->first();
                if (! $employee) {
                    continue;
                }
                DB::table('employee_kpis')->where('id', $kpi->id)->update([
                    'branch_id_snapshot' => $employee->branch_id,
                    'position_id_snapshot' => $employee->position_id,
                    'position_code_snapshot' => DB::table('positions')->where('id', $employee->position_id)->value('code'),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_kpis', fn (Blueprint $table) => $table->dropColumn([
            'branch_id_snapshot', 'position_id_snapshot', 'position_code_snapshot',
        ]));
    }
};
