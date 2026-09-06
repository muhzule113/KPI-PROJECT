<?php

namespace App\Models;

use App\Support\CapabilityMatrix;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected static function booted(): void
    {
        static::updated(function (self $user): void {
            if ($user->wasChanged('password') || ($user->wasChanged('is_active') && ! $user->is_active)) {
                $user->revokeAllSessions();
            }
        });
    }

    protected $attributes = ['is_active' => true];

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function employee()
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function notifications()
    {
        return $this->hasMany(SystemNotification::class, 'user_id');
    }

    public function deviceTokens()
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function getRolesLabelAttribute(): string
    {
        return $this->roles->pluck('name')->map(fn (string $role) => CapabilityMatrix::roleLabel($role))->implode(', ');
    }

    public function getAllowedPlatformsLabelAttribute(): string
    {
        return implode(', ', CapabilityMatrix::allowedPlatforms($this));
    }

    public function getEmployeeIdAttribute(): ?string
    {
        return $this->employee?->getKey();
    }

    public function revokeAllSessions(): void
    {
        $this->tokens()->delete();
        DB::table('sessions')->where('user_id', $this->id)->delete();
    }
}
