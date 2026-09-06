<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opnames', fn (Blueprint $table) => $table->unsignedBigInteger('branch_id')->nullable()->index());
        DB::table('stock_opnames')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('stock_opnames')->where('id', $row->id)->update([
                    'branch_id' => DB::table('employees')->where('user_id', $row->created_by)->value('branch_id'),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_opnames', fn (Blueprint $table) => $table->dropColumn('branch_id'));
    }
};
