<?php

declare(strict_types=1);

namespace SecureDb;

use SecureDb\Exception\SecureDbException;

/**
 * Processes special placeholder types in SQL queries
 */
class PlaceholderProcessor
{
    private IdentifierQuoter $quoter;
    private string $identifierPrefix = '';

    public function __construct(IdentifierQuoter $quoter)
    {
        $this->quoter = $quoter;
    }

    /**
     * Set the identifier prefix for table names
     */
    public function setIdentifierPrefix(string $prefix): void
    {
        $this->identifierPrefix = $prefix;
    }

    /**
     * Process a query with special placeholders
     */
    public function processQuery(string $query, array $params): array
    {
        $processedQuery = $query;
        $processedParams = [];
        $paramIndex = 0;

        // Handle ?_ prefix placeholders
        $processedQuery = preg_replace_callback(
            '/\?_([a-zA-Z_][a-zA-Z0-9_]*)/u',
            function ($matches) {
                $tableName = $this->identifierPrefix . $matches[1];
                return $this->quoter->quoteIdentifier($tableName);
            },
            $processedQuery
        );

        // Handle ?# identifier placeholders
        $processedQuery = preg_replace_callback(
            '/\?#/',
            function ($matches) use ($params, &$paramIndex) {
                if (!isset($params[$paramIndex])) {
                    throw new SecureDbException('Missing parameter for identifier placeholder ?#');
                }
                
                $identifier = $params[$paramIndex];
                $paramIndex++;
                
                if (!is_string($identifier)) {
                    throw new SecureDbException('Identifier placeholder ?# requires a string parameter');
                }
                
                return $this->quoter->quoteIdentifier($identifier);
            },
            $processedQuery
        );

        // Handle ?a array placeholders
        $processedQuery = preg_replace_callback(
            '/\?a/',
            function ($matches) use ($params, &$paramIndex, &$processedParams) {
                if (!isset($params[$paramIndex])) {
                    throw new SecureDbException('Missing parameter for array placeholder ?a');
                }
                
                $array = $params[$paramIndex];
                $paramIndex++;
                
                if (!is_array($array)) {
                    throw new SecureDbException('Array placeholder ?a requires an array parameter');
                }
                
                if (empty($array)) {
                    throw new SecureDbException('Array placeholder ?a cannot be empty');
                }
                
                // Check if this is an associative array for SET clauses
                if ($this->isAssociativeArray($array)) {
                    $setParts = [];
                    foreach ($array as $key => $value) {
                        $setParts[] = $this->quoter->quoteIdentifier($key) . ' = ?';
                        $processedParams[] = $this->extractValue($value);
                    }
                    return implode(', ', $setParts);
                } else {
                    // Regular array for IN clauses
                    $placeholders = [];
                    foreach ($array as $value) {
                        $placeholders[] = '?';
                        $processedParams[] = $this->extractValue($value);
                    }
                    return implode(', ', $placeholders);
                }
            },
            $processedQuery
        );

        // Add remaining regular parameters
        for ($i = $paramIndex; $i < count($params); $i++) {
            $processedParams[] = $this->extractValue($params[$i]);
        }

        return [$processedQuery, $processedParams];
    }

    /**
     * Check if an array is associative
     */
    private function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Extract value from parameter, handling TypedValue objects
     */
    private function extractValue(mixed $value): mixed
    {
        // Handle TypedValue objects
        if (is_object($value) && method_exists($value, 'toNative')) {
            return $value->toNative();
        }

        return $value;
    }

    /**
     * Process conditional macro blocks in query
     */
    public function processConditionalBlocks(string $query, array $conditions): string
    {
        return preg_replace_callback(
            '/\{([^}]+)\}/',
            function ($matches) use ($conditions) {
                $blockContent = $matches[1];
                $conditionKey = trim($blockContent);
                
                // Check if condition is met
                if (isset($conditions[$conditionKey]) && $conditions[$conditionKey]) {
                    return $blockContent;
                }
                
                return '';
            },
            $query
        );
    }
} 