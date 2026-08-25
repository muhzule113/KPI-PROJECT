<?php

namespace App\Modules\Assessment;

use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Illuminate\Support\Facades\DB;

/**
 * Business logic sesi stock opname: input fisik → hitung selisih →
 * sesuaikan stok sparepart + catat ledger mutasi.
 */
class StockOpnameService
{
    /**
     * Menyelesaikan sesi opname.
     * Semua item yang sudah diisi stok fisik dihitung selisihnya;
     * item yang selisih ≠ 0 akan menyesuaikan stok sparepart + membuat stock movement.
     *
     * @return array{counted: int, adjusted: int, message: string}
     */
    public function complete(StockOpname $opname, ?int $userId = null): array
    {
        if ($opname->status === StockOpname::STATUS_COMPLETED) {
            return ['counted' => 0, 'adjusted' => 0, 'message' => "Opname {$opname->code} sudah diselesaikan sebelumnya."];
        }

        $counted = 0;
        $adjusted = 0;

        DB::transaction(function () use ($opname, $userId, &$counted, &$adjusted) {
            $opname->loadMissing('items.sparepart');

            foreach ($opname->items as $item) {
                if ($item->physical_stock === null) continue;

                $counted++;
                $item->difference = $item->physical_stock - $item->system_stock;
                $item->is_counted = true;
                $item->save();

                if ($item->difference !== 0) {
                    $part = $item->sparepart;

                    StockMovement::create([
                        'sparepart_id' => $part->id,
                        'movement_type' => StockMovement::TYPE_OPNAME_ADJUSTMENT,
                        'quantity' => $item->difference,
                        'stock_before' => $part->stock_quantity,
                        'stock_after' => $item->physical_stock,
                        'reference_type' => 'stock_opname',
                        'reference_id' => $opname->id,
                        'note' => 'Penyesuaian hasil opname ' . $opname->code,
                        'user_id' => $userId,
                    ]);

                    $part->stock_quantity = $item->physical_stock;
                    $part->save();
                    $adjusted++;
                }
            }

            $opname->status = StockOpname::STATUS_COMPLETED;
            $opname->completed_at = now();
            $opname->save();
        });

        return [
            'counted' => $counted,
            'adjusted' => $adjusted,
            'message' => "Opname {$opname->code} selesai: {$counted} item dihitung, {$adjusted} item stok disesuaikan.",
        ];
    }

    /**
     * Snapshot stok sistem dari database untuk semua sparepart.
     */
    public function snapshotItems(StockOpname $opname): int
    {
        $created = 0;
        $parts = \App\Models\Sparepart::orderBy('name')->get();

        foreach ($parts as $part) {
            StockOpnameItem::create([
                'stock_opname_id' => $opname->id,
                'sparepart_id' => $part->id,
                'system_stock' => $part->stock_quantity,
            ]);
            $created++;
        }

        return $created;
    }
}
