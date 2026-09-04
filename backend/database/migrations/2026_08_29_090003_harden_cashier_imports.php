<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->string('source_application', 100)->default('POS_SYSTEM')->after('file_hash_sha256');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('warnings_acknowledged_at')->nullable()->after('confirmed_by');
        });

        Schema::table('cashier_transactions', function (Blueprint $table): void {
            $table->string('source_application', 100)->default('POS_SYSTEM')->after('period_id');
            $table->string('business_key', 191)->nullable()->after('transaction_number');
        });

        DB::table('cashier_transactions')->update([
            'business_key' => DB::raw("CONCAT('POS_SYSTEM|', LOWER(TRIM(transaction_number)))"),
        ]);

        $duplicates = DB::table('cashier_transactions')
            ->select('period_id', 'source_application', 'business_key')
            ->whereNotNull('business_key')
            ->groupBy('period_id', 'source_application', 'business_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Tidak dapat menambahkan unique key import: terdapat transaksi duplikat yang harus direkonsiliasi.');
        }

        Schema::table('cashier_transactions', function (Blueprint $table): void {
            $table->unique(
                ['period_id', 'source_application', 'business_key'],
                'cashier_transactions_business_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cashier_transactions', function (Blueprint $table): void {
            $table->dropUnique('cashier_transactions_business_key_unique');
            $table->dropColumn(['source_application', 'business_key']);
        });

        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['source_application', 'warnings_acknowledged_at']);
        });
    }
};
