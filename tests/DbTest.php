<?php

declare(strict_types=1);

namespace SecureDb\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use SecureDb\Db;
use SecureDb\Exception\SecureDbException;
use SecureDb\MacroControl;

class DbTest extends TestCase
{
    private Db $db;
    private PDO $pdo;

    protected function setUp(): void
    {
        // Create in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = Db::wrap($this->pdo, 'sqlite');
        
        // Create test table
        $this->pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                age INTEGER,
                active INTEGER DEFAULT 1
            )
        ');
        
        // Insert test data
        $this->pdo->exec("
            INSERT INTO users (name, email, age, active) VALUES 
            ('John Doe', 'john@example.com', 30, 1),
            ('Jane Smith', 'jane@example.com', 25, 1),
            ('Bob Johnson', 'bob@example.com', 35, 0)
        ");
    }

    public function testConnect(): void
    {
        $db = Db::connect('sqlite::memory:');
        $this->assertInstanceOf(Db::class, $db);
    }

    public function testConnectWithInvalidDsn(): void
    {
        $this->expectException(SecureDbException::class);
        $this->expectExceptionMessage('Database connection failed');
        
        Db::connect('invalid:dsn');
    }

    public function testConnectErrorDoesNotLeakCredentials(): void
    {
        try {
            Db::connect('mysql:host=localhost', 'secret_user', 'secret_password');
            $this->fail('Expected SecureDbException was not thrown');
        } catch (SecureDbException $e) {
            $this->assertStringNotContainsString('secret_user', $e->getMessage());
            $this->assertStringNotContainsString('secret_password', $e->getMessage());
        }
    }

    public function testWrap(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db = Db::wrap($pdo, 'sqlite');
        
        $this->assertInstanceOf(Db::class, $db);
        $this->assertSame($pdo, $db->getPdo());
    }

    public function testSelect(): void
    {
        $users = $this->db->select('SELECT * FROM users WHERE active = ?', 1);
        
        $this->assertCount(2, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
        $this->assertEquals('Jane Smith', $users[1]['name']);
    }

    public function testSelectRow(): void
    {
        $user = $this->db->selectRow('SELECT * FROM users WHERE email = ?', 'john@example.com');
        
        $this->assertNotNull($user);
        $this->assertEquals('John Doe', $user['name']);
        $this->assertEquals(30, $user['age']);
    }

    public function testSelectRowNotFound(): void
    {
        $user = $this->db->selectRow('SELECT * FROM users WHERE email = ?', 'nonexistent@example.com');
        
        $this->assertNull($user);
    }

    public function testSelectCol(): void
    {
        $names = $this->db->selectCol('SELECT name FROM users WHERE active = ?', 1);
        
        $this->assertCount(2, $names);
        $this->assertEquals(['John Doe', 'Jane Smith'], $names);
    }

    public function testSelectCell(): void
    {
        $count = $this->db->selectCell('SELECT COUNT(*) FROM users WHERE active = ?', 1);
        
        $this->assertEquals(2, $count);
    }

    public function testSelectCellNotFound(): void
    {
        $result = $this->db->selectCell('SELECT name FROM users WHERE id = ?', 999);
        
        $this->assertNull($result);
    }

    public function testRun(): void
    {
        $users = $this->db->run('SELECT * FROM users WHERE active = ?', 1);
        
        $this->assertCount(2, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
    }

    public function testQuery(): void
    {
        $affected = $this->db->query('UPDATE users SET active = ? WHERE id = ?', 0, 1);
        
        $this->assertEquals(1, $affected);
        
        // Verify the update
        $user = $this->db->selectRow('SELECT * FROM users WHERE id = ?', 1);
        $this->assertEquals(0, $user['active']);
    }

    public function testInsert(): void
    {
        $data = [
            'name' => 'Alice Wilson',
            'email' => 'alice@example.com',
            'age' => 28,
            'active' => 1
        ];
        
        $id = $this->db->insert('users', $data);
        
        $this->assertIsNumeric($id);
        $this->assertGreaterThan(0, $id);
        
        // Verify the insert
        $user = $this->db->selectRow('SELECT * FROM users WHERE id = ?', $id);
        $this->assertEquals('Alice Wilson', $user['name']);
        $this->assertEquals('alice@example.com', $user['email']);
        $this->assertEquals(28, $user['age']);
    }

    public function testInsertEmptyData(): void
    {
        $this->expectException(SecureDbException::class);
        $this->expectExceptionMessage('Insert data cannot be empty');
        
        $this->db->insert('users', []);
    }

    public function testInsertEmptyTableName(): void
    {
        $this->expectException(SecureDbException::class);
        $this->expectExceptionMessage('Table name cannot be empty');

        $this->db->insert('', ['name' => 'Test']);
    }

    public function testUpdateEmptyTableName(): void
    {
        $this->expectException(SecureDbException::class);
        $this->expectExceptionMessage('Table name cannot be empty');

        $this->db->update('', ['name' => 'Test'], ['id' => 1]);
    }

    public function testDeleteEmptyTableName(): void
    {
        $this->expectException(SecureDbException::class);
        $this->expectExceptionMessage('Table name cannot be empty');

        $this->db->delete('', ['id' => 1]);
    }

    public function testUpdate(): void
    {
        $affected = $this->db->update(
            'users',
            ['name' => 'John Smith', 'age' => 31],
            ['id' => 1]
        );
        
        $this->assertEquals(1, $affected);
        
        // Verify the update
        $user = $this->db->selectRow('SELECT * FROM users WHERE id = ?', 1);
        $this->assertEquals('John Smith', $user['name']);
        $this->assertEquals(31, $user['age']);
    }

    public function testUpdateEmptyData(): void
    {
        $this->expectException(SecureDbException::class);
        $this->expectExceptionMessage('Update data cannot be empty');
        
        $this->db->update('users', [], ['id' => 1]);
    }

    public function testUpdateEmptyWhere(): void
    {
        $this->expectException(SecureDbException::class);
        $this->expectExceptionMessage('Update WHERE clause cannot be empty');
        
        $this->db->update('users', ['name' => 'Test'], []);
    }

    public function testDelete(): void
    {
        $affected = $this->db->delete('users', ['id' => 1]);
        
        $this->assertEquals(1, $affected);
        
        // Verify the delete
        $user = $this->db->selectRow('SELECT * FROM users WHERE id = ?', 1);
        $this->assertNull($user);
    }

    public function testDeleteEmptyWhere(): void
    {
        $this->expectException(SecureDbException::class);
        $this->expectExceptionMessage('Delete WHERE clause cannot be empty');
        
        $this->db->delete('users', []);
    }

    public function testTransaction(): void
    {
        $this->assertTrue($this->db->transaction());
        
        $this->db->insert('users', ['name' => 'Test User', 'email' => 'test@example.com']);
        
        $this->assertTrue($this->db->commit());
        
        // Verify the transaction was committed
        $user = $this->db->selectRow('SELECT * FROM users WHERE email = ?', 'test@example.com');
        $this->assertNotNull($user);
    }

    public function testTransactionRollback(): void
    {
        $this->assertTrue($this->db->transaction());
        
        $this->db->insert('users', ['name' => 'Test User', 'email' => 'test@example.com']);
        
        $this->assertTrue($this->db->rollback());
        
        // Verify the transaction was rolled back
        $user = $this->db->selectRow('SELECT * FROM users WHERE email = ?', 'test@example.com');
        $this->assertNull($user);
    }

    public function testTryFlatTransaction(): void
    {
        $result = $this->db->tryFlatTransaction(function ($db) {
            $db->insert('users', ['name' => 'Test User 1', 'email' => 'test1@example.com']);
            $db->insert('users', ['name' => 'Test User 2', 'email' => 'test2@example.com']);
            return 'success';
        });
        
        $this->assertEquals('success', $result);
        
        // Verify both inserts were committed
        $user1 = $this->db->selectRow('SELECT * FROM users WHERE email = ?', 'test1@example.com');
        $user2 = $this->db->selectRow('SELECT * FROM users WHERE email = ?', 'test2@example.com');
        $this->assertNotNull($user1);
        $this->assertNotNull($user2);
    }

    public function testTryFlatTransactionWithException(): void
    {
        $this->expectException(SecureDbException::class);
        
        $this->db->tryFlatTransaction(function ($db) {
            $db->insert('users', ['name' => 'Test User 1', 'email' => 'test1@example.com']);
            throw new SecureDbException('Test exception');
        });
        
        // Verify the transaction was rolled back
        $user = $this->db->selectRow('SELECT * FROM users WHERE email = ?', 'test1@example.com');
        $this->assertNull($user);
    }

    public function testArrayPlaceholder(): void
    {
        $users = $this->db->select('SELECT * FROM users WHERE id IN(?a)', [1, 2]);
        
        $this->assertCount(2, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
        $this->assertEquals('Jane Smith', $users[1]['name']);
    }

    public function testAssociativeArrayPlaceholder(): void
    {
        $affected = $this->db->query('UPDATE users SET ?a WHERE id = ?', ['name' => 'Updated Name', 'age' => 99], 1);
        
        $this->assertEquals(1, $affected);
        
        // Verify the update
        $user = $this->db->selectRow('SELECT * FROM users WHERE id = ?', 1);
        $this->assertEquals('Updated Name', $user['name']);
        $this->assertEquals(99, $user['age']);
    }

    public function testIdentifierPlaceholder(): void
    {
        $users = $this->db->select('SELECT * FROM ?# WHERE ?# = ?', 'users', 'active', 1);
        
        $this->assertCount(2, $users);
    }

    public function testIdentifierPrefix(): void
    {
        $this->db->setIdentifierPrefix('test_');
        
        // Create a prefixed table for testing
        $this->pdo->exec('CREATE TABLE test_products (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec("INSERT INTO test_products (name) VALUES ('Product 1')");
        
        $products = $this->db->select('SELECT * FROM ?_products');
        
        $this->assertCount(1, $products);
        $this->assertEquals('Product 1', $products[0]['name']);
    }

    public function testErrorHandler(): void
    {
        $errorCalled = false;
        $this->db->setErrorHandler(function ($error, $query, $params) use (&$errorCalled) {
            $errorCalled = true;
        });
        
        $this->expectException(SecureDbException::class);
        
        $this->db->select('SELECT * FROM nonexistent_table');
        
        $this->assertTrue($errorCalled);
    }

    public function testLogger(): void
    {
        $logCalled = false;
        $this->db->setLogger(function ($logData) use (&$logCalled) {
            $logCalled = true;
            $this->assertArrayHasKey('query', $logData);
            // Params are excluded by default to prevent sensitive data leaking into logs
            $this->assertArrayNotHasKey('params', $logData);
        });
        
        $this->db->select('SELECT * FROM users WHERE id = ?', 1);
        
        $this->assertTrue($logCalled);
    }

    public function testLoggerWithQueryParams(): void
    {
        $logData = null;
        $this->db->setLogQueryParams(true);
        $this->db->setLogger(function ($data) use (&$logData) {
            $logData = $data;
        });

        $this->db->select('SELECT * FROM users WHERE id = ?', 1);

        $this->assertNotNull($logData);
        $this->assertArrayHasKey('params', $logData);
        $this->assertSame([1], $logData['params']);
    }

    public function testStrictMode(): void
    {
        $this->db->setStrictMode(true);
        
        $this->expectException(SecureDbException::class);
        $this->db->select('SELECT * FROM nonexistent_table');
    }

    public function testStrictModeDisabled(): void
    {
        $this->db->setStrictMode(false);

        // select / selectRow — return empty collection / null
        $rows = $this->db->select('SELECT * FROM nonexistent_table');
        $this->assertSame([], $rows);

        $row = $this->db->selectRow('SELECT * FROM nonexistent_table');
        $this->assertNull($row);

        // selectCol / selectCell
        $col = $this->db->selectCol('SELECT name FROM nonexistent_table');
        $this->assertSame([], $col);

        $cell = $this->db->selectCell('SELECT COUNT(*) FROM nonexistent_table');
        $this->assertNull($cell);

        // write operations — return 0 affected rows
        $affected = $this->db->query('DELETE FROM nonexistent_table WHERE id = ?', 1);
        $this->assertSame(0, $affected);

        $updated = $this->db->update('nonexistent_table', ['name' => 'x'], ['id' => 1]);
        $this->assertSame(0, $updated);

        $deleted = $this->db->delete('nonexistent_table', ['id' => 1]);
        $this->assertSame(0, $deleted);

        $inserted = $this->db->insert('nonexistent_table', ['name' => 'x']);
        $this->assertSame(0, $inserted);
    }

    public function testSelectPage(): void
    {
        $totalRows = 0;
        $users = $this->db->selectPage($totalRows, 'SELECT * FROM users WHERE active = ?', 1);
        
        $this->assertEquals(2, $totalRows);
        $this->assertCount(2, $users);
    }

    public function testMacroSubstitutionWithIncludedBlock(): void
    {
        // Test with block included (parameter is not DBSIMPLE_SKIP)
        $users = $this->db->select(
            'SELECT * FROM users WHERE id = ? { AND active = ? } ORDER BY id',
            1,
            1
        );
        
        $this->assertCount(1, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
        $this->assertEquals(1, $users[0]['active']);
    }

    public function testMacroSubstitutionWithSkippedBlock(): void
    {
        // Test with block skipped (parameter is MacroControl::SKIP)
        $users = $this->db->select(
            'SELECT * FROM users WHERE id = ? { AND active = ? } ORDER BY id',
            1,
            MacroControl::SKIP
        );
        
        $this->assertCount(1, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
        // Should return the user regardless of active status since the condition was skipped
    }

    public function testMacroSubstitutionMultipleBlocks(): void
    {
        // Test with multiple macro blocks
        $users = $this->db->select(
            'SELECT * FROM users WHERE 1=1 { AND name = ? } { AND active = ? } ORDER BY id',
            'John Doe',
            MacroControl::SKIP
        );
        
        // Should include the name condition but skip the active condition
        $this->assertCount(1, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
    }

    public function testMacroSubstitutionComplexQuery(): void
    {
        // Create a category table for more complex testing
        $this->pdo->exec('
            CREATE TABLE categories (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            )
        ');
        
        $this->pdo->exec("
            INSERT INTO categories (id, name) VALUES 
            (1, 'Electronics'),
            (2, 'Books')
        ");
        
        // Add category_id to users table
        $this->pdo->exec('ALTER TABLE users ADD COLUMN category_id INTEGER');
        $this->pdo->exec('UPDATE users SET category_id = 1 WHERE id IN (1, 2)');
        $this->pdo->exec('UPDATE users SET category_id = 2 WHERE id = 3');
        
        // Test complex query without conditional blocks (simpler test)
        $users = $this->db->select(
            'SELECT u.* FROM users u
             WHERE u.active = ?
             { AND u.category_id = ? }
             ORDER BY u.id',
            1,
            MacroControl::SKIP  // Skip the category filter
        );
        
        // Should return active users without the JOIN and category name filter
        $this->assertCount(2, $users);
    }

    public function testMacroSubstitutionWithArrayPlaceholder(): void
    {
        // Test macro substitution combined with array placeholder
        $includeIds = true;
        $users = $this->db->select(
            'SELECT * FROM users WHERE active = ? { AND id IN(?a) } ORDER BY id',
            1,
            $includeIds ? [1, 2] : MacroControl::SKIP
        );
        
        $this->assertCount(2, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
        $this->assertEquals('Jane Smith', $users[1]['name']);
    }

    public function testMacroSubstitutionSkipWithArrayPlaceholder(): void
    {
        // Test macro substitution skipped with array placeholder
        $includeIds = false;
        $users = $this->db->select(
            'SELECT * FROM users WHERE active = ? { AND id IN(?a) } ORDER BY id',
            1,
            $includeIds ? [1, 2] : MacroControl::SKIP
        );
        
        // Should return all active users since the IN condition was skipped
        $this->assertCount(2, $users);
    }

    public function testMacroSubstitutionInUpdate(): void
    {
        // Test macro substitution in UPDATE queries with WHERE clause
        $includeAgeFilter = false;
        $affected = $this->db->query(
            'UPDATE users SET name = ? WHERE id = ? { AND age > ? }',
            'Updated Name',
            1,
            $includeAgeFilter ? 25 : MacroControl::SKIP
        );
        
        $this->assertEquals(1, $affected);
        
        // Verify name was updated
        $user = $this->db->selectRow('SELECT * FROM users WHERE id = ?', 1);
        $this->assertEquals('Updated Name', $user['name']);
    }

    public function testMacroSubstitutionNestedBlocks(): void
    {
        // Test with nested-like blocks (should handle each independently)
        $users = $this->db->select(
            'SELECT * FROM users WHERE 1=1 { AND active = ? } { AND age > ? } ORDER BY id',
            1,
            25
        );
        
        // Should include both conditions
        $this->assertCount(1, $users); // Only John Doe (age 30) meets both criteria
        $this->assertEquals('John Doe', $users[0]['name']);
    }



    public function testInsertWithNullValue(): void
    {
        $id = $this->db->insert('users', ['name' => 'N', 'email' => 'n@x', 'age' => null]);
        $this->assertNull($this->db->selectCell('SELECT age FROM users WHERE id = ?', $id));
        $this->assertSame(1, (int)$this->db->selectCell('SELECT COUNT(*) FROM users WHERE id = ? AND age IS NULL', $id));
    }

    public function testQueryWithNullPlaceholder(): void
    {
        $this->assertSame(1, $this->db->query('UPDATE users SET age = ? WHERE id = ?', null, 1));
        $this->assertNull($this->db->selectCell('SELECT age FROM users WHERE id = ?', 1));
    }

    public function testUpdateAndDeleteWithNull(): void
    {
        $this->assertSame(1, $this->db->update('users', ['age' => null], ['id' => 2]));
        $this->assertNull($this->db->selectCell('SELECT age FROM users WHERE id = 2'));
    }

    public function testArrayAssocPlaceholderWithNull(): void
    {
        $this->db->query('UPDATE users SET ?a WHERE id = ?', ['age' => null, 'name' => 'Z'], 1);
        $row = $this->db->selectRow('SELECT name, age FROM users WHERE id = 1');
        $this->assertSame('Z', $row['name']);
        $this->assertNull($row['age']);
    }

    public function testArrayListPlaceholderWithNullElementAndTrailingNull(): void
    {
        $rows = $this->db->selectCol('SELECT id FROM users WHERE id IN (?a) AND (? IS NULL)', [1, null, 2], null);
        $this->assertEquals([1, 2], array_map('intval', $rows));
    }

    public function testNullInsideMacroBlockIsNotSkipped(): void
    {
        $this->db->query('UPDATE users SET age = ? WHERE id = 3', null);
        $rows = $this->db->selectCol('SELECT id FROM users WHERE id > ? { AND (age IS ? OR age IS NULL) } ORDER BY id', 0, null);
        $this->assertEquals([3], array_map('intval', $rows));
        $rows = $this->db->selectCol('SELECT id FROM users WHERE id > ? { AND age = ? } AND ? IS NULL ORDER BY id', 0, MacroControl::SKIP, null);
        $this->assertEquals([1, 2, 3], array_map('intval', $rows));
    }

    public function testMissingParameterStillThrows(): void
    {
        $this->expectException(SecureDbException::class);
        $this->db->select('SELECT * FROM users WHERE id = ? AND age = ?', 1);
    }

    public function testHelpersApplyPrefixToPrefixPlaceholderTable(): void
    {
        $this->pdo->exec('CREATE TABLE app_posts (id INTEGER PRIMARY KEY, title TEXT)');
        $this->db->setIdentifierPrefix('app_');
        $id = $this->db->insert('?_posts', ['title' => 'a']);
        $this->assertSame(1, $this->db->update('?_posts', ['title' => 'b'], ['id' => $id]));
        $this->assertSame('b', $this->db->selectCell('SELECT title FROM ?_posts WHERE id = ?', $id));
        $this->assertSame(1, $this->db->delete('?_posts', ['id' => $id]));
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM app_posts')->fetchColumn());
    }

    public function testHelpersKeepBareTableNamesVerbatimWithPrefix(): void
    {
        $this->db->setIdentifierPrefix('app_');
        $id = $this->db->insert('users', ['name' => 'B', 'email' => 'b@x']);
        $this->assertNotEmpty($id);
    }

    public function testInvalidPrefixedTableNameRejected(): void
    {
        $this->expectException(SecureDbException::class);
        $this->db->insert('?_bad name', ['x' => 1]);
    }
} 