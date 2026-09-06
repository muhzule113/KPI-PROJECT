<?php

namespace App\Support;

use App\Models\User;

/**
 * Adapter pemanggil lama ke aturan akses bersama.
 */
class MenuAccess
{
    /**
     * Cek apakah user boleh membuka resource.
     *
     * @param  array  $allowedRoles  Role Spatie yang diizinkan secara eksplisit
     * @param  array  $allowedPositions  Position code (POS-TEK, POS-CS, ...) yang diizinkan
     */
    public static function can(?User $user, array $allowedRoles = [], array $allowedPositions = []): bool
    {
        if (! $user) {
            return false;
        }

        return CapabilityMatrix::matches($user, $allowedRoles, $allowedPositions);
    }

    /** Semua role non-super_admin yang masuk akal buat lihat menu operasional. */
    public static function anyEmployee(): bool
    {
        return self::can(
            auth()->user(),
            ['owner_manager', 'supervisor'],
            ['POS-TEK', 'POS-CS', 'POS-ADM', 'POS-KSR', 'POS-GUD']
        );
    }
}
