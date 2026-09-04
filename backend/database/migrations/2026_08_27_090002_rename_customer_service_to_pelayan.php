<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('positions')
            ->where('code', 'POS-CS')
            ->update(['name' => 'Pelayan']);

        DB::table('kpi_templates')
            ->where('code', 'TPL-CS-01')
            ->update(['name' => 'Template KPI Pelayan v1']);

        DB::table('users')
            ->where('email', 'cs@toko.com')
            ->update(['name' => 'Siti Rahma (Pelayan)']);
    }

    public function down(): void
    {
        DB::table('positions')
            ->where('code', 'POS-CS')
            ->update(['name' => 'Customer Service']);

        DB::table('kpi_templates')
            ->where('code', 'TPL-CS-01')
            ->update(['name' => 'Template KPI CS v1']);

        DB::table('users')
            ->where('email', 'cs@toko.com')
            ->update(['name' => 'Siti Rahma (CS)']);
    }
};
