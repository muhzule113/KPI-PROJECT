<?php

namespace App\Support;

use App\Models\User;

/**
 * Access control menu Filament berbasis role Spatie + position_code.
 * Dipakai oleh canViewAny() tiap resource agar menu tersaring per peran.
 */
class MenuAccess
{
    /**
     * Cek apakah user boleh membuka resource.
     *
     * @param array $allowedRoles  Role Spatie yang diizinkan (selain super_admin yg selalu boleh)
     * @param array $allowedPositions Position code (POS-TEK, POS-CS, ...) yang diizinkan
     */
    public static function can(?User $user, array $allowedRoles = [], array $allowedPositions = []): bool
    {
        if (!$user) {
            return false;
        }

        // super_admin selalu punya akses penuh
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Cek role Spatie
        foreach ($allowedRoles as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        // Cek position_code karyawan
        $positionCode = $user->employee?->position?->code;
        if ($positionCode && in_array($positionCode, $allowedPositions, true)) {
            return true;
        }

        return false;
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
