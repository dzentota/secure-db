<?php

declare(strict_types=1);

namespace SecureDb;

use PDO;
use PDOException;
use PDOStatement;
use SecureDb\Exception\SecureDbException;
use Throwable;

/**
 * Secure PDO wrapper providing SQL injection protection and convenient database operations
 */
class Db
{
    private PDO $pdo;
    private IdentifierQuoter $quoter;
    private PlaceholderProcessor $placeholderProcessor;
    private mixed $errorHandler = null;
    private mixed $logger = null;
    private bool $strictMode = true;

    /**
     * Create a new Db instance
     */
    public function __construct(PDO $pdo, string $driverName)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        $this->quoter = new IdentifierQuoter($driverName);
        $this->placeholderProcessor = new PlaceholderProcessor($this->quoter);
    }

    /**
     * Create a new database connection
     */
    public static function connect(string $dsn, string $username = '', string $password = '', array $options = []): self
    {
        try {
            $pdo = new PDO($dsn, $username, $password, $options);
            $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            return new self($pdo, $driverName);
        } catch (PDOException $e) {
            throw new SecureDbException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Wrap an existing PDO instance
     */
    public static function wrap(PDO $pdo, string $driverName): self
    {
        return new self($pdo, $driverName);
    }

    /**
     * Set custom error handler
     */
    public function setErrorHandler(?callable $handler): void
    {
        $this->errorHandler = $handler;
    }

    /**
     * Set custom logger
     */
    public function setLogger(?callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Set strict mode (throws exceptions vs returns false)
     */
    public function setStrictMode(bool $strict): void
    {
        $this->strictMode = $strict;
    }

    /**
     * Set identifier prefix for table names
     */
    public function setIdentifierPrefix(string $prefix): void
    {
        $this->placeholderProcessor->setIdentifierPrefix($prefix);
    }

    /**
     * Execute a query and return all results
     */
    public function run(string $query, ...$params): array
    {
        return $this->select($query, ...$params);
    }

    /**
     * Execute a SELECT query and return all rows
     */
    public function select(string $query, ...$params): array
    {
        $stmt = $this->executeQuery($query, $params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a SELECT query and return a single row
     */
    public function selectRow(string $query, ...$params): ?array
    {
        $stmt = $this->executeQuery($query, $params);
        $result = $stmt->fetch();
        return $result === false ? null : $result;
    }

    /**
     * Execute a SELECT query and return a single column from all rows
     */
    public function selectCol(string $query, ...$params): array
    {
        $stmt = $this->executeQuery($query, $params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Execute a SELECT query and return a single cell value
     */
    public function selectCell(string $query, ...$params): mixed
    {
        $stmt = $this->executeQuery($query, $params);
        $result = $stmt->fetchColumn();
        return $result === false ? null : $result;
    }

    /**
     * Execute a SELECT query with pagination
     */
    public function selectPage(int &$totalRows, string $query, ...$params): array
    {
        // Count total rows
        $countQuery = $this->buildCountQuery($query);
        $totalRows = (int) $this->selectCell($countQuery, ...$params);
        
        // Execute the original query
        return $this->select($query, ...$params);
    }

    /**
     * Execute a non-SELECT query (INSERT, UPDATE, DELETE)
     */
    public function query(string $query, ...$params): int
    {
        $stmt = $this->executeQuery($query, $params);
        return $stmt->rowCount();
    }

    /**
     * Insert a row into a table
     */
    public function insert(string $table, array $data): int|string
    {
        if (empty($data)) {
            throw new SecureDbException('Insert data cannot be empty');
        }

        $columns = array_keys($data);
        $values = array_values($data);
        
        $quotedTable = $this->quoter->quoteIdentifier($table);
        $quotedColumns = $this->quoter->quoteIdentifiers($columns);
        $placeholders = str_repeat('?,', count($values) - 1) . '?';
        
        $query = "INSERT INTO {$quotedTable} (" . implode(', ', $quotedColumns) . ") VALUES ({$placeholders})";
        
        $this->executeQuery($query, $values);
        
        return $this->pdo->lastInsertId();
    }

    /**
     * Update rows in a table
     */
    public function update(string $table, array $data, array $where): int
    {
        if (empty($data)) {
            throw new SecureDbException('Update data cannot be empty');
        }

        if (empty($where)) {
            throw new SecureDbException('Update WHERE clause cannot be empty');
        }

        $quotedTable = $this->quoter->quoteIdentifier($table);
        
        // Build SET clause
        $setParts = [];
        $setValues = [];
        foreach ($data as $column => $value) {
            $setParts[] = $this->quoter->quoteIdentifier($column) . ' = ?';
            $setValues[] = $value;
        }
        
        // Build WHERE clause
        $whereParts = [];
        $whereValues = [];
        foreach ($where as $column => $value) {
            $whereParts[] = $this->quoter->quoteIdentifier($column) . ' = ?';
            $whereValues[] = $value;
        }
        
        $query = "UPDATE {$quotedTable} SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);
        $params = array_merge($setValues, $whereValues);
        
        $stmt = $this->executeQuery($query, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete rows from a table
     */
    public function delete(string $table, array $where): int
    {
        if (empty($where)) {
            throw new SecureDbException('Delete WHERE clause cannot be empty');
        }

        $quotedTable = $this->quoter->quoteIdentifier($table);
        
        // Build WHERE clause
        $whereParts = [];
        $whereValues = [];
        foreach ($where as $column => $value) {
            $whereParts[] = $this->quoter->quoteIdentifier($column) . ' = ?';
            $whereValues[] = $value;
        }
        
        $query = "DELETE FROM {$quotedTable} WHERE " . implode(' AND ', $whereParts);
        
        $stmt = $this->executeQuery($query, $whereValues);
        return $stmt->rowCount();
    }

    /**
     * Start a transaction
     */
    public function transaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Execute a transaction with automatic commit/rollback
     */
    public function tryFlatTransaction(callable $callback): mixed
    {
        $this->transaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Execute a query with prepared statements
     */
    private function executeQuery(string $query, array $params): PDOStatement
    {
        $startTime = microtime(true);
        
        try {
            // Process special placeholders
            [$processedQuery, $processedParams] = $this->placeholderProcessor->processQuery($query, $params);
            
            // Log pre-execution
            $this->logQuery($processedQuery, $processedParams, null, $startTime);
            
            // Prepare and execute statement
            $stmt = $this->pdo->prepare($processedQuery);
            $stmt->execute($processedParams);
            
            // Log post-execution
            $executionTime = microtime(true) - $startTime;
            $this->logQuery($processedQuery, $processedParams, $executionTime, $startTime);
            
            return $stmt;
            
        } catch (PDOException $e) {
            $this->handleError($e, $query, $params);
            throw new SecureDbException('Database query failed', (int)$e->getCode(), $e);
        }
    }

    /**
     * Build a COUNT query from a SELECT query
     */
    private function buildCountQuery(string $query): string
    {
        // Simple implementation - wrap the query in a COUNT
        return "SELECT COUNT(*) FROM ({$query}) AS count_query";
    }

    /**
     * Handle database errors
     */
    private function handleError(PDOException $e, string $query, array $params): void
    {
        if ($this->errorHandler) {
            ($this->errorHandler)($e, $query, $params);
        }
        
        // Log error
        $this->logError($e, $query, $params);
    }

    /**
     * Log query execution
     */
    private function logQuery(string $query, array $params, ?float $executionTime, float $startTime): void
    {
        if ($this->logger) {
            $logData = [
                'query' => $query,
                'params' => $params,
                'execution_time' => $executionTime,
                'timestamp' => $startTime,
                'caller' => $this->getCallerInfo(),
            ];
            
            ($this->logger)($logData);
        }
    }

    /**
     * Log database errors
     */
    private function logError(PDOException $e, string $query, array $params): void
    {
        if ($this->logger) {
            $logData = [
                'error' => $e->getMessage(),
                'query' => $query,
                'params' => $params,
                'timestamp' => microtime(true),
                'caller' => $this->getCallerInfo(),
            ];
            
            ($this->logger)($logData);
        }
    }

    /**
     * Get caller information for debugging
     */
    private function getCallerInfo(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        
        // Find the first caller outside of this class
        foreach ($trace as $frame) {
            if (!isset($frame['class']) || $frame['class'] !== self::class) {
                return [
                    'file' => $frame['file'] ?? 'unknown',
                    'line' => $frame['line'] ?? 0,
                    'function' => $frame['function'] ?? 'unknown',
                ];
            }
        }
        
        return ['file' => 'unknown', 'line' => 0, 'function' => 'unknown'];
    }

    /**
     * Get the underlying PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
} 