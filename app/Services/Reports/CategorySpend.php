<?php

namespace App\Services\Reports;

final readonly class CategorySpend
{
    public function __construct(
        public string $name,
        public float $total,
    ) {}
}
