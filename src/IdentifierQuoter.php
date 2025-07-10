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
        'sqlite' => '`',
        'sqlsrv' => '[',
        'oci' => '"',
        'firebird' => '"',
    ];

    private const CLOSE_CHARS = [
        'mysql' => '`',
        'pgsql' => '"',
        'sqlite' => '`',
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
        
        // For SQLite, use a simpler approach - only quote if the identifier contains special characters
        // or is a reserved word, otherwise leave it unquoted
        if ($this->driverName === 'sqlite') {
            // Check if identifier needs quoting (contains spaces, special chars, or is a reserved word)
            if ($this->needsQuoting($identifier)) {
                // Use double quotes for SQLite when needed
                return '"' . str_replace('"', '""', $identifier) . '"';
            }
            return $identifier; // Return unquoted for simple identifiers
        }
        
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
     * Check if an identifier needs quoting in SQLite
     */
    private function needsQuoting(string $identifier): bool
    {
        // Check for spaces, special characters, or starting with digit
        if (preg_match('/[^a-zA-Z0-9_]/', $identifier) || is_numeric($identifier[0])) {
            return true;
        }
        
        // Check for SQLite reserved words (basic list)
        $reservedWords = [
            'SELECT', 'FROM', 'WHERE', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP',
            'ALTER', 'TABLE', 'INDEX', 'VIEW', 'TRIGGER', 'DATABASE', 'SCHEMA',
            'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES', 'UNIQUE', 'NOT', 'NULL',
            'DEFAULT', 'CHECK', 'CONSTRAINT', 'AUTO_INCREMENT', 'AUTOINCREMENT',
            'IF', 'EXISTS', 'TEMPORARY', 'TEMP', 'AS', 'ON', 'ORDER', 'BY',
            'GROUP', 'HAVING', 'LIMIT', 'OFFSET', 'UNION', 'INTERSECT', 'EXCEPT',
            'INNER', 'LEFT', 'RIGHT', 'FULL', 'OUTER', 'JOIN', 'CROSS', 'NATURAL'
        ];
        
        return in_array(strtoupper($identifier), $reservedWords);
    }

    /**
     * Get the driver name this quoter is configured for
     */
    public function getDriverName(): string
    {
        return $this->driverName;
    }
} 