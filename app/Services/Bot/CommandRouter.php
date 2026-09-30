<?php

namespace App\Services\Bot;

use App\Services\Parser\CategoryMatcher;
use App\Services\Parser\TransactionParser;
use App\Services\Reports\ReportBuilder;
use Telegram\Bot\Api;

class CommandRouter
{
    public function __construct(
        private Api $telegram,
        private TransactionParser $transactionParser,
        private CategoryMatcher $categoryMatcher,
        private ReportBuilder $reportBuilder,
    ) {}

    /**
     * Route an incoming Telegram update to the appropriate handler.
     *
     * @param  array{message?: array<string, mixed>}  $update
     */
    public function handle(array $update): void
    {
        //
    }
}
