<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiRatingScheme extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'version',
        'score_cap',
        'description',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'score_cap' => 'decimal:6',
    ];

    public function bands()
    {
        return $this->hasMany(KpiRatingBand::class, 'rating_scheme_id')->orderBy('sort_order');
    }

    public function getBandForScore(float $score): ?KpiRatingBand
    {
        return $this->bands()
            ->where('min_score', '<=', $score)
            ->where('max_score', '>=', $score)
            ->first();
    }

    public function manualOptions(): array
    {
        return $this->bands
            ->filter(fn (KpiRatingBand $band): bool => $band->manual_score !== null)
            ->sortBy('sort_order')
            ->map(fn (KpiRatingBand $band): array => [
                'code' => $band->code,
                'label' => $band->label,
                'score' => (float) $band->manual_score,
            ])
            ->values()
            ->all();
    }

    public function coverageIssues(): array
    {
        $bands = $this->bands()->reorder('min_score')->get();
        $issues = [];
        $expectedCodes = ['FAIR', 'GOOD', 'POOR', 'STAR', 'VERY_GOOD'];
        $codes = $bands->pluck('code')->sort()->values()->all();
        if ($codes !== $expectedCodes) {
            $issues[] = 'Skema penilaian Supervisor harus memakai lima kode predikat internal yang unik.';
        }
        if ($bands->whereNotNull('manual_score')->count() !== 5) {
            $issues[] = 'Skema penilaian Supervisor harus mempunyai tepat lima pilihan manual.';
        }
        $expected = 0.0;
        foreach ($bands as $band) {
            $minimum = (float) $band->min_score;
            if ($minimum < 0 || (float) $band->max_score > 100
                || ($band->manual_score !== null && ((float) $band->manual_score < 0 || (float) $band->manual_score > 100))) {
                $issues[] = 'Nilai dan rentang predikat harus berada pada 0 sampai 100.';
            }
            if (abs($minimum - $expected) > 0.000001) {
                $issues[] = $minimum < $expected ? 'Band rating saling tumpang tindih.' : 'Band rating memiliki gap.';
            }
            if ((float) $band->max_score < $minimum) {
                $issues[] = 'Batas maksimum band lebih kecil dari batas minimum.';
            }
            $expected = (float) $band->max_score + 0.01;
        }
        if ($bands->isEmpty() || abs(($expected - 0.01) - (float) $this->score_cap) > 0.000001) {
            $issues[] = 'Band rating harus menutup skor 0 sampai score cap.';
        }

        return array_values(array_unique($issues));
    }
}
