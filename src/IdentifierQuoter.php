<?php

declare(strict_types=1);

namespace SecureDb;

/**
 * Handles database-specific identifier quoting for table and column names
 */
class IdentifierQuoter
{
    private const QUOTE_CHARS = [
        'mysql' => '`',
        'pgsql' => '"',
        'sqlite' => '[',
        'sqlsrv' => '[',
        'oci' => '"',
        'firebird' => '"',
    ];

    private const CLOSE_CHARS = [
        'mysql' => '`',
        'pgsql' => '"',
        'sqlite' => ']',
        'sqlsrv' => ']',
        'oci' => '"',
        'firebird' => '"',
    ];

    private string $driverName;
    private string $openChar;
    private string $closeChar;

    public function __construct(string $driverName)
    {
        $this->driverName = strtolower($driverName);
        $this->openChar = self::QUOTE_CHARS[$this->driverName] ?? '"';
        $this->closeChar = self::CLOSE_CHARS[$this->driverName] ?? '"';
    }

    /**
     * Quote a single identifier (table or column name)
     */
    public function quoteIdentifier(string $identifier): string
    {
        // Handle qualified identifiers (e.g., "schema.table" or "table.column")
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier);
            return implode('.', array_map([$this, 'quoteSingleIdentifier'], $parts));
        }

        return $this->quoteSingleIdentifier($identifier);
    }

    /**
     * Quote a single identifier part
     */
    private function quoteSingleIdentifier(string $identifier): string
    {
        // Remove existing quotes if present
        $identifier = trim($identifier, $this->openChar . $this->closeChar);
        
        // Escape any quote characters within the identifier
        $escaped = str_replace($this->openChar, $this->openChar . $this->openChar, $identifier);
        
        return $this->openChar . $escaped . $this->closeChar;
    }

    /**
     * Quote multiple identifiers
     */
    public function quoteIdentifiers(array $identifiers): array
    {
        return array_map([$this, 'quoteIdentifier'], $identifiers);
    }

    /**
     * Get the driver name this quoter is configured for
     */
    public function getDriverName(): string
    {
        return $this->driverName;
    }
} 