<?php

namespace App\Http\Middleware;

use App\Models\KpiPeriod;
use App\Support\AdminNavigation;
use App\Support\CapabilityMatrix;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->loadMissing(['employee.position', 'employee.branch', 'roles']);
        $activePeriod = $user ? KpiPeriod::active() : null;
        $notifications = $user
            ? $user->notifications()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn ($notification): ?array => $notification->visiblePayload($user))
                ->filter()
                ->values()
                ->all()
            : [];

        return [
            ...parent::share($request),
            'activePeriod' => $activePeriod ? [
                'id' => $activePeriod->getKey(),
                'name' => $activePeriod->name,
                'status' => $activePeriod->status,
                'start_date' => $activePeriod->start_date?->toDateString(),
                'end_date' => $activePeriod->end_date?->toDateString(),
            ] : null,
            'notifications' => $notifications,
            'navigation' => $user ? AdminNavigation::for($user) : [],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name')->values()->all(),
                    'capabilities' => CapabilityMatrix::for($user),
                    'allowed_platforms' => CapabilityMatrix::allowedPlatforms($user),
                    'employee' => $user->employee ? [
                        'id' => $user->employee->getKey(),
                        'name' => $user->employee->name,
                        'position' => $user->employee->position?->name,
                        'position_code' => $user->employee->position?->code,
                        'branch' => $user->employee->branch?->name,
                    ] : null,
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
