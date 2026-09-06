<?php

namespace App\Modules\Calculation;

class CalculationResult
{
    public function __construct(
        public bool $isSuccess,
        public ?float $achievementPercentage = null,
        public ?float $weightedScore = null,
        public string $status = 'calculated', // calculated, unscorable, pending
        public ?string $note = null,
        public array $meta = []
    ) {}

    public static function calculated(float $achievement, float $weightedScore, array $meta = []): self
    {
        return new self(
            isSuccess: true,
            achievementPercentage: round($achievement, 6, PHP_ROUND_HALF_UP),
            weightedScore: round($weightedScore, 6, PHP_ROUND_HALF_UP),
            status: 'calculated',
            meta: $meta
        );
    }

    public static function unscorable(string $reason): self
    {
        return new self(
            isSuccess: false,
            achievementPercentage: null,
            weightedScore: null,
            status: 'unscorable',
            note: $reason
        );
    }

    public static function pending(string $reason = 'Data belum lengkap'): self
    {
        return new self(
            isSuccess: true,
            achievementPercentage: null,
            weightedScore: null,
            status: 'pending',
            note: $reason
        );
    }
}
