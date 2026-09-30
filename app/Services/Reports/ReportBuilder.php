<?php

namespace App\Services\Reports;

class ReportBuilder
{
    /**
     * Build a weekly summary with totals and top categories.
     *
     * @param  iterable<array{amount: int|float, type: string, category: string, date: string}>  $transactions
     */
    public function buildWeekly(iterable $transactions): string
    {
        //
    }
}
