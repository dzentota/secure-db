<?php

declare(strict_types=1);

namespace SecureDb;

use SecureDb\Exception\SecureDbException;
use SecureDb\MacroControl;

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
        // First, process macro substitutions
        [$processedQuery, $filteredParams] = $this->processMacroSubstitutions($query, $params);
        
        // Now process special placeholders with the filtered parameters
        $finalParams = [];
        $paramIndex = 0;

        // First, handle ?_ prefix placeholders (don't consume parameters)
        $processedQuery = preg_replace_callback(
            '/\?_([a-zA-Z_][a-zA-Z0-9_]*)/u',
            function ($matches) {
                $tableName = $this->identifierPrefix . $matches[1];
                return $this->quoter->quoteIdentifier($tableName);
            },
            $processedQuery
        );

        // Process all remaining placeholders in order: ?#, ?a, and ? 
        $processedQuery = preg_replace_callback(
            '/(\?#|\?a|\?)/',
            function ($matches) use ($filteredParams, &$paramIndex, &$finalParams) {
                $placeholder = $matches[1];
                
                if ($placeholder === '?#') {
                    // Handle ?# identifier placeholders
                    if (!isset($filteredParams[$paramIndex])) {
                        throw new SecureDbException('Missing parameter for identifier placeholder ?#');
                    }
                    
                    $identifier = $filteredParams[$paramIndex];
                    $paramIndex++;
                    
                    if (!is_string($identifier)) {
                        throw new SecureDbException('Identifier placeholder ?# requires a string parameter');
                    }
                    
                    return $this->quoter->quoteIdentifier($identifier);
                    
                } elseif ($placeholder === '?a') {
                    // Handle ?a array placeholders
                    if (!isset($filteredParams[$paramIndex])) {
                        throw new SecureDbException('Missing parameter for array placeholder ?a');
                    }
                    
                    $array = $filteredParams[$paramIndex];
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
                            $finalParams[] = $this->extractValue($value);
                        }
                        return implode(', ', $setParts);
                    } else {
                        // Regular array for IN clauses
                        $placeholders = [];
                        foreach ($array as $value) {
                            $placeholders[] = '?';
                            $finalParams[] = $this->extractValue($value);
                        }
                        return implode(', ', $placeholders);
                    }
                    
                } else {
                    // Handle regular ? placeholders  
                    if (!isset($filteredParams[$paramIndex])) {
                        throw new SecureDbException('Missing parameter for placeholder ?');
                    }
                    
                    $finalParams[] = $this->extractValue($filteredParams[$paramIndex]);
                    $paramIndex++;
                    
                    return '?'; // Keep as-is for PDO
                }
            },
            $processedQuery
        );

        return [$processedQuery, $finalParams];
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
     * Process macro substitutions - conditional SQL blocks based on MacroControl::SKIP parameters
     */
    private function processMacroSubstitutions(string $query, array $params): array
    {
        $processedQuery = $query;
        $processedParams = [];
        
        // First, count placeholders before each macro block to determine parameter indices
        $paramIndex = 0;
        $queryPos = 0;
        
        // Find all macro blocks and process them
        $processedQuery = preg_replace_callback(
            '/\{([^}]+)\}/',
            function ($matches) use ($query, $params, &$paramIndex, &$processedParams, &$queryPos) {
                $blockContent = $matches[1];
                $fullMatch = $matches[0];
                
                // Find the position of this match in the original query
                $matchPos = strpos($query, $fullMatch, $queryPos);
                
                // Count placeholders in the query before this macro block
                $queryBeforeBlock = substr($query, $queryPos, $matchPos - $queryPos);
                $placeholdersBeforeBlock = $this->countPlaceholdersInBlock($queryBeforeBlock);
                
                // Update parameter index to account for placeholders before this block
                $paramIndex += $placeholdersBeforeBlock;
                
                // Add parameters before this block to processed params
                for ($i = $paramIndex - $placeholdersBeforeBlock; $i < $paramIndex; $i++) {
                    if (isset($params[$i]) && !$this->isSkipValue($params[$i])) {
                        $processedParams[] = $params[$i];
                    }
                }
                
                // Count placeholders in this block
                $placeholderCount = $this->countPlaceholdersInBlock($blockContent);
                
                // Check if any of the corresponding parameters are MacroControl::SKIP
                $shouldSkipBlock = false;
                $blockParams = [];
                
                for ($i = 0; $i < $placeholderCount; $i++) {
                    if (!isset($params[$paramIndex + $i])) {
                        // Not enough parameters - this will be caught later as an error
                        break;
                    }
                    
                    $param = $params[$paramIndex + $i];
                    
                    if ($this->isSkipValue($param)) {
                        $shouldSkipBlock = true;
                        // Don't add this parameter or any subsequent ones in this block
                        break;
                    }
                    $blockParams[] = $param;
                }
                
                // Move parameter index forward by the number of placeholders in this block
                $paramIndex += $placeholderCount;
                
                // Update query position
                $queryPos = $matchPos + strlen($fullMatch);
                
                if ($shouldSkipBlock) {
                    // Skip this block and don't add any of its parameters
                    return '';
                } else {
                    // Keep this block and add its parameters to the processed list
                    $processedParams = array_merge($processedParams, $blockParams);
                    return $blockContent;
                }
            },
            $processedQuery
        );
        
        // Add any remaining parameters after the last macro block (but filter out skip values)
        $remainingQuery = substr($query, $queryPos);
        $remainingPlaceholders = $this->countPlaceholdersInBlock($remainingQuery);
        
        for ($i = 0; $i < $remainingPlaceholders; $i++) {
            if (isset($params[$paramIndex + $i]) && !$this->isSkipValue($params[$paramIndex + $i])) {
                $processedParams[] = $params[$paramIndex + $i];
            }
        }
        
        return [$processedQuery, $processedParams];
    }
    
    /**
     * Count the number of placeholders in a SQL block
     */
    private function countPlaceholdersInBlock(string $block): int
    {
        $count = 0;
        
        // Count ?a placeholders (each consumes one parameter)
        $count += preg_match_all('/\?a/', $block);
        
        // Count ?# placeholders (each consumes one parameter)
        $count += preg_match_all('/\?#/', $block);
        
        // Count regular ? placeholders, but exclude special ones
        $regularQuestions = substr_count($block, '?');
        $specialQuestions = preg_match_all('/\?[a#]/', $block); // ?a and ?# 
        $prefixQuestions = preg_match_all('/\?_[a-zA-Z_][a-zA-Z0-9_]*/', $block); // ?_ prefixes don't consume params
        
        $count += $regularQuestions - $specialQuestions - $prefixQuestions;
        
        return $count;
    }
    
    /**
     * Check if a value is a skip sentinel
     */
    private function isSkipValue(mixed $value): bool
    {
        return $value === MacroControl::SKIP;
    }

    /**
     * Process conditional macro blocks in query (legacy method)
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