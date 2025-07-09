<?php

require_once __DIR__ . '/../vendor/autoload.php';

use SecureDb\Db;

// Example 1: Basic Connection and Queries
echo "=== Basic Usage Example ===\n";

// Connect to database
$db = Db::connect('sqlite::memory:');

// Create a sample table
$db->query('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        age INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        active INTEGER DEFAULT 1
    )
');

// Insert sample data
$userId1 = $db->insert('users', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30,
    'active' => 1
]);

$userId2 = $db->insert('users', [
    'name' => 'Jane Smith',
    'email' => 'jane@example.com',
    'age' => 25,
    'active' => 1
]);

$userId3 = $db->insert('users', [
    'name' => 'Bob Johnson',
    'email' => 'bob@example.com',
    'age' => 35,
    'active' => 0
]);

echo "Inserted users with IDs: $userId1, $userId2, $userId3\n";

// Example 2: Different Query Types
echo "\n=== Query Examples ===\n";

// Select all active users
$activeUsers = $db->select('SELECT * FROM users WHERE active = ?', 1);
echo "Active users: " . count($activeUsers) . "\n";

// Select single user
$user = $db->selectRow('SELECT * FROM users WHERE email = ?', 'john@example.com');
echo "Found user: " . $user['name'] . "\n";

// Get user names only
$names = $db->selectCol('SELECT name FROM users WHERE active = ?', 1);
echo "User names: " . implode(', ', $names) . "\n";

// Get total count
$totalUsers = $db->selectCell('SELECT COUNT(*) FROM users');
echo "Total users: $totalUsers\n";

// Example 3: Special Placeholders
echo "\n=== Special Placeholders Example ===\n";

// Array placeholder for IN clause
$userIds = [1, 2];
$users = $db->select('SELECT * FROM users WHERE id IN(?a)', $userIds);
echo "Users with IDs 1,2: " . count($users) . "\n";

// Associative array placeholder for UPDATE
$updateData = ['name' => 'John Smith', 'age' => 31];
$affected = $db->query('UPDATE users SET ?a WHERE id = ?', $updateData, 1);
echo "Updated $affected user(s)\n";

// Identifier placeholder for dynamic table/column names
$tableName = 'users';
$columnName = 'active';
$users = $db->select('SELECT * FROM ?# WHERE ?# = ?', $tableName, $columnName, 1);
echo "Dynamic query found " . count($users) . " active users\n";

// Example 4: Transaction Management
echo "\n=== Transaction Example ===\n";

// Manual transaction
$db->transaction();
try {
    $db->insert('users', ['name' => 'Alice Wilson', 'email' => 'alice@example.com', 'age' => 28]);
    $db->insert('users', ['name' => 'Charlie Brown', 'email' => 'charlie@example.com', 'age' => 32]);
    $db->commit();
    echo "Transaction committed successfully\n";
} catch (Exception $e) {
    $db->rollback();
    echo "Transaction rolled back: " . $e->getMessage() . "\n";
}

// Automatic transaction wrapper
$result = $db->tryFlatTransaction(function($db) {
    $db->insert('users', ['name' => 'David Wilson', 'email' => 'david@example.com', 'age' => 29]);
    $db->insert('users', ['name' => 'Eva Martinez', 'email' => 'eva@example.com', 'age' => 27]);
    return 'success';
});
echo "Automatic transaction result: $result\n";

// Example 5: Error Handling and Logging
echo "\n=== Error Handling Example ===\n";

// Set up error handler
$db->setErrorHandler(function($error, $query, $params) {
    echo "Error Handler Called: " . $error->getMessage() . "\n";
});

// Set up query logger
$db->setLogger(function($logData) {
    echo "Query Log: " . $logData['query'] . " (";
    echo "Execution time: " . ($logData['execution_time'] ? round($logData['execution_time'] * 1000, 2) . 'ms' : 'N/A');
    echo ")\n";
});

// Execute a query to trigger logging
$users = $db->select('SELECT COUNT(*) as count FROM users');
echo "Current user count: " . $users[0]['count'] . "\n";

// Example 6: CRUD Operations
echo "\n=== CRUD Operations Example ===\n";

// Create (Insert)
$newUserId = $db->insert('users', [
    'name' => 'Frank Miller',
    'email' => 'frank@example.com',
    'age' => 45,
    'active' => 1
]);
echo "Created user with ID: $newUserId\n";

// Read (Select)
$user = $db->selectRow('SELECT * FROM users WHERE id = ?', $newUserId);
echo "Read user: " . $user['name'] . " (Age: " . $user['age'] . ")\n";

// Update
$affected = $db->update('users', ['age' => 46], ['id' => $newUserId]);
echo "Updated $affected user(s)\n";

// Verify update
$updatedUser = $db->selectRow('SELECT * FROM users WHERE id = ?', $newUserId);
echo "Updated user age: " . $updatedUser['age'] . "\n";

// Delete
$deleted = $db->delete('users', ['id' => $newUserId]);
echo "Deleted $deleted user(s)\n";

// Example 7: Pagination
echo "\n=== Pagination Example ===\n";

$totalRows = 0;
$users = $db->selectPage($totalRows, 'SELECT * FROM users WHERE active = ? ORDER BY name LIMIT 3', 1);
echo "Page results: " . count($users) . " users (Total: $totalRows)\n";

foreach ($users as $user) {
    echo "- " . $user['name'] . " (" . $user['email'] . ")\n";
}

// Example 8: Table Prefix
echo "\n=== Table Prefix Example ===\n";

// Set a table prefix
$db->setIdentifierPrefix('app_');

// Create a prefixed table
$db->query('CREATE TABLE app_products (id INTEGER PRIMARY KEY, name TEXT, price REAL)');

// Insert into prefixed table using ?_ placeholder
$productId = $db->insert('app_products', ['name' => 'Laptop', 'price' => 999.99]);
echo "Created product with ID: $productId\n";

// Query using prefix placeholder
$products = $db->select('SELECT * FROM ?_products WHERE price > ?', 500);
echo "Expensive products: " . count($products) . "\n";

// Example 9: TypedValue Integration (Simulated)
echo "\n=== TypedValue Integration Example ===\n";

// Simulate TypedValue objects
class EmailValue {
    private string $value;
    
    public function __construct(string $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format');
        }
        $this->value = $email;
    }
    
    public function toNative(): string {
        return $this->value;
    }
}

class AgeValue {
    private int $value;
    
    public function __construct(int $age) {
        if ($age < 0 || $age > 150) {
            throw new InvalidArgumentException('Invalid age range');
        }
        $this->value = $age;
    }
    
    public function toNative(): int {
        return $this->value;
    }
}

// Use TypedValue objects
$email = new EmailValue('typed@example.com');
$age = new AgeValue(33);

$typedUserId = $db->insert('users', [
    'name' => 'Typed User',
    'email' => $email,    // Automatically calls toNative()
    'age' => $age,        // Automatically calls toNative()
    'active' => 1
]);

echo "Created typed user with ID: $typedUserId\n";

// Final count
$finalCount = $db->selectCell('SELECT COUNT(*) FROM users');
echo "\nFinal user count: $finalCount\n";

echo "\n=== Example completed successfully! ===\n"; 