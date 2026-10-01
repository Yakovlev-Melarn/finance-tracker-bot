<?php

namespace App\Services\Parser;

use App\Models\Category;
use App\TransactionType;

final readonly class ParsedTransaction
{
    public function __construct(
        public float $amount,
        public string $comment,
        public TransactionType $type,
        public ?Category $category = null,
    ) {}
}
