<?php

namespace App\Services\Categories;

use Exception;

/**
 * Thrown when a category operation cannot be applied
 * (unknown category, duplicate name, duplicate keyword, empty name).
 * The message is user-facing and safe to send to Telegram as-is.
 */
class CategoryException extends Exception {}
