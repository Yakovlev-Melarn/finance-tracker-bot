<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;

final readonly class WeekStats
{
    /**
     * @param  array<int, CategorySpend>  $topCategories
     */
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public float $income,
        public float $expense,
        public array $topCategories,
    ) {}
}
