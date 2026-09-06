<?php

namespace App\Modules\Assessment;

use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Support\CapabilityMatrix;
use Illuminate\Auth\Access\AuthorizationException;
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
            $this->assertActor($lockedOpname, $userId);

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
                if ((string) $part->branch_id !== (string) $lockedOpname->branch_id) {
                    throw new RuntimeException('Item stok berada di luar cabang opname.');
                }
                if ((int) $part->stock_quantity !== (int) $item->system_stock) {
                    throw new RuntimeException('Stok berubah sejak snapshot. Buat opname baru dengan stok terbaru.');
                }
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
                        'note' => 'Penyesuaian hasil opname '.$lockedOpname->code,
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
        if (! $opname->branch_id || $opname->status === StockOpname::STATUS_COMPLETED) {
            throw new RuntimeException('Cabang opname wajib ditentukan dan sesi belum selesai.');
        }
        $parts = Sparepart::query()
            ->where('branch_id', $opname->branch_id)
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

    public function saveCounts(StockOpname $opname, array $data, int $userId): void
    {
        DB::transaction(function () use ($opname, $data, $userId): void {
            $opname = StockOpname::whereKey($opname->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($opname, $userId);
            if ($opname->status === StockOpname::STATUS_COMPLETED) {
                throw new RuntimeException('Stock opname yang sudah selesai tidak dapat diubah.');
            }
            if ((int) $data['period_id'] !== (int) $opname->period_id) {
                throw new RuntimeException('Periode opname tidak dapat diganti.');
            }
            $ids = collect($data['items'])->pluck('id')->map(fn ($id) => (string) $id);
            if ($ids->duplicates()->isNotEmpty() || $opname->items()->whereIn('id', $ids)->count() !== $ids->count()) {
                throw new RuntimeException('Item opname tidak valid atau dikirim lebih dari sekali.');
            }
            $opname->update(['code' => ($data['code'] ?? '') ?: $opname->code, 'deadline' => $data['deadline'] ?? null]);
            foreach ($data['items'] as $item) {
                $opname->items()->whereKey($item['id'])->update(['physical_stock' => $item['physical_stock']]);
            }
        });
    }

    private function assertActor(StockOpname $opname, ?int $userId): void
    {
        $user = User::with('employee')->find($userId ?? auth()->id());
        if (! $user || ! CapabilityMatrix::has($user, 'stock-opname.manage')
            || (string) $opname->created_by !== (string) $user->id
            || (string) $opname->branch_id !== (string) $user->employee?->branch_id) {
            throw new AuthorizationException('Opname berada di luar penugasan atau cabang Anda.');
        }
        if ($opname->period?->status !== 'OPEN') {
            throw new RuntimeException('Data periode yang sudah ditutup hanya dapat dibaca.');
        }
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
