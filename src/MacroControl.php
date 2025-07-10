<?php

declare(strict_types=1);

namespace SecureDb;

/**
 * Enum for macro substitution control
 */
enum MacroControl
{
    /**
     * Special value used to skip macro blocks in SQL queries
     */
    case SKIP;
} 