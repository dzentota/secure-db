<?php

declare(strict_types=1);

namespace SecureDb\Exception;

use Exception;

/**
 * Base exception class for SecureDb operations
 */
class SecureDbException extends Exception
{
    /**
     * Create a new SecureDbException
     */
    public function __construct(string $message, int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
} 