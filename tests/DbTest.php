<?php

declare(strict_types=1);

namespace SecureDb\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use SecureDb\Db;
use SecureDb\Exception\SecureDbException;

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
            $this->assertArrayHasKey('params', $logData);
        });
        
        $this->db->select('SELECT * FROM users WHERE id = ?', 1);
        
        $this->assertTrue($logCalled);
    }

    public function testStrictMode(): void
    {
        $this->db->setStrictMode(true);
        
        $this->expectException(SecureDbException::class);
        $this->db->select('SELECT * FROM nonexistent_table');
    }

    public function testSelectPage(): void
    {
        $totalRows = 0;
        $users = $this->db->selectPage($totalRows, 'SELECT * FROM users WHERE active = ?', 1);
        
        $this->assertEquals(2, $totalRows);
        $this->assertCount(2, $users);
    }
} 