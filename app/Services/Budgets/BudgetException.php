<?php

namespace App\Services\Budgets;

use Exception;

/**
 * Thrown when a budget operation cannot be applied
 * (unknown category, invalid amount).
 * The message is user-facing and safe to send to Telegram as-is.
 */
final class BudgetException extends Exception {}
