<?php

namespace App\Modules\Assessment;

use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Sparepart;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Business logic sesi stock opname: input fisik → hitung selisih →
 * sesuaikan stok sparepart + catat ledger mutasi.
 */
class StockOpnameService
{
    /**
     * Menyelesaikan sesi opname setelah seluruh item dihitung.
     * Stok dan ledger disesuaikan atomik di dalam transaksi.
     *
     * @return array{counted: int, adjusted: int, message: string}
     */
    public function complete(StockOpname $opname, ?int $userId = null): array
    {
        return DB::transaction(function () use ($opname, $userId): array {
            $lockedOpname = StockOpname::with('items')
                ->whereKey($opname->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOpname->status === StockOpname::STATUS_COMPLETED) {
                return ['counted' => 0, 'adjusted' => 0, 'message' => "Opname {$lockedOpname->code} sudah diselesaikan sebelumnya."];
            }

            $items = $lockedOpname->items;
            if ($items->isEmpty()) {
                throw new RuntimeException('Opname tidak memiliki item untuk dihitung.');
            }
            if ($items->contains(fn (StockOpnameItem $item): bool => $item->physical_stock === null)) {
                throw new RuntimeException('Semua item opname wajib diisi stok fisiknya sebelum diselesaikan.');
            }

            $counted = 0;
            $adjusted = 0;
            foreach ($items as $item) {
                $part = Sparepart::whereKey($item->sparepart_id)->lockForUpdate()->firstOrFail();
                $difference = (int) $item->physical_stock - (int) $item->system_stock;

                $item->difference = $difference;
                $item->is_counted = true;
                $item->save();
                $counted++;

                if ($difference !== 0) {
                    $before = (int) $part->stock_quantity;
                    $part->stock_quantity = (int) $item->physical_stock;
                    $part->save();

                    StockMovement::create([
                        'sparepart_id' => $part->id,
                        'movement_type' => StockMovement::TYPE_OPNAME_ADJUSTMENT,
                        'quantity' => $difference,
                        'stock_before' => $before,
                        'stock_after' => $part->stock_quantity,
                        'reference_type' => 'stock_opname',
                        'reference_id' => $lockedOpname->id,
                        'note' => 'Penyesuaian hasil opname ' . $lockedOpname->code,
                        'user_id' => $userId,
                    ]);
                    $adjusted++;
                }
            }

            $lockedOpname->status = StockOpname::STATUS_COMPLETED;
            $lockedOpname->completed_at = now();
            $lockedOpname->save();

            return [
                'counted' => $counted,
                'adjusted' => $adjusted,
                'message' => "Opname {$lockedOpname->code} selesai: {$counted} item dihitung, {$adjusted} item stok disesuaikan.",
            ];
        });
    }

    /**
     * Snapshot stok sistem dari database untuk sparepart pada cabang opname.
     */
    public function snapshotItems(StockOpname $opname): int
    {
        $opname->loadMissing('period');
        $branchIds = $opname->period?->branches()->pluck('branches.id')->all() ?? [];
        $parts = Sparepart::query()
            ->where(function ($query) use ($branchIds): void {
                $query->whereIn('branch_id', $branchIds)
                    ->orWhereNull('branch_id');
            })
            ->orderBy('name')
            ->get();

        $created = 0;
        foreach ($parts as $part) {
            StockOpnameItem::firstOrCreate(
                ['stock_opname_id' => $opname->id, 'sparepart_id' => $part->id],
                ['system_stock' => $part->stock_quantity]
            );
            $created++;
        }

        return $created;
    }

    /**
     * Prevent completed opname deletion; its ledger entries are immutable history.
     */
    public function assertDeletable(StockOpname $opname): void
    {
        if ($opname->status === StockOpname::STATUS_COMPLETED) {
            throw new RuntimeException('Stock opname yang sudah selesai tidak dapat dihapus.');
        }
    }

    /**
     * Update stock through the ledger while holding the row lock.
     */
    public function restock(Sparepart $sparepart, int $quantity, ?string $note = null, ?int $userId = null): Sparepart
    {
        if ($quantity < 1) {
            throw new RuntimeException('Jumlah restok harus lebih besar dari nol.');
        }

        return DB::transaction(function () use ($sparepart, $quantity, $note, $userId): Sparepart {
            $part = Sparepart::whereKey($sparepart->id)->lockForUpdate()->firstOrFail();
            $before = (int) $part->stock_quantity;
            $part->stock_quantity = $before + $quantity;
            $part->save();

            StockMovement::create([
                'sparepart_id' => $part->id,
                'movement_type' => StockMovement::TYPE_RESTOCK_IN,
                'quantity' => $quantity,
                'stock_before' => $before,
                'stock_after' => $part->stock_quantity,
                'reference_type' => 'manual_restock',
                'note' => $note,
                'user_id' => $userId,
            ]);

            return $part;
        });
    }
}
