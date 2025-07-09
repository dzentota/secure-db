# Secure PDO Wrapper

A secure-by-default PDO wrapper that prevents SQL injection vulnerabilities and provides a developer-friendly API for database operations.

## Features

### 🔒 Security First
- **Prepared statements by default** - All queries use prepared statements, making SQL injection nearly impossible
- **TypedValue integration** - Seamless integration with value objects for robust input validation
- **Identifier quoting** - Safe handling of dynamic table and column names
- **Comprehensive audit logging** - Track all database operations for security review

### 🚀 Developer Friendly
- **Intuitive API** - Simple, consistent methods for common database operations
- **Special placeholders** - Advanced placeholder system for complex queries
- **Transaction management** - Both explicit and automatic transaction handling
- **Error handling** - Comprehensive error management with customizable handlers

### 🎯 Advanced Features
- **Dynamic query building** - Build complex queries safely with special placeholders
- **Multi-database support** - Works with MySQL, PostgreSQL, SQLite, and more
- **Connection management** - Factory-based connection creation and existing PDO wrapping
- **Prefix support** - Table name prefixing for shared databases

## Installation

```bash
composer require secure-db/secure-pdo-wrapper
```

## Quick Start

```php
use SecureDb\Db;

// Create a new connection
$db = Db::connect('mysql:host=localhost;dbname=mydb', 'username', 'password');

// Or wrap an existing PDO instance
$pdo = new PDO('sqlite::memory:');
$db = Db::wrap($pdo, 'sqlite');

// Basic queries
$users = $db->select('SELECT * FROM users WHERE active = ?', 1);
$user = $db->selectRow('SELECT * FROM users WHERE id = ?', 123);
$userCount = $db->selectCell('SELECT COUNT(*) FROM users');

// CRUD operations
$userId = $db->insert('users', ['name' => 'John', 'email' => 'john@example.com']);
$affected = $db->update('users', ['active' => 0], ['id' => $userId]);
$deleted = $db->delete('users', ['id' => $userId]);
```

## Core API

### Connection Management

```php
// Factory connection
$db = Db::connect(string $dsn, string $username = '', string $password = '', array $options = []);

// Wrap existing PDO
$db = Db::wrap(PDO $pdo, string $driverName);
```

### Query Execution

```php
// Fetch all rows
$users = $db->select('SELECT * FROM users WHERE active = ?', 1);

// Fetch single row
$user = $db->selectRow('SELECT * FROM users WHERE id = ?', 123);

// Fetch single column from all rows
$names = $db->selectCol('SELECT name FROM users WHERE active = ?', 1);

// Fetch single cell value
$count = $db->selectCell('SELECT COUNT(*) FROM users WHERE active = ?', 1);

// Execute non-SELECT query
$affected = $db->query('UPDATE users SET last_login = NOW() WHERE id = ?', 123);

// Paginated results
$totalRows = 0;
$users = $db->selectPage($totalRows, 'SELECT * FROM users WHERE active = ?', 1);
```

### CRUD Operations

```php
// Insert
$userId = $db->insert('users', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'active' => 1
]);

// Update
$affected = $db->update('users', 
    ['name' => 'Jane Doe', 'active' => 0], 
    ['id' => $userId]
);

// Delete
$deleted = $db->delete('users', ['id' => $userId]);
```

### Transaction Management

```php
// Explicit transactions
$db->transaction();
try {
    $db->insert('orders', $orderData);
    $db->insert('order_items', $itemData);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}

// Automatic transaction wrapper
$result = $db->tryFlatTransaction(function($db) {
    $db->insert('orders', $orderData);
    $db->insert('order_items', $itemData);
    return 'success';
});
```

## Advanced Features

### Special Placeholders

#### Array Placeholder (`?a`)

```php
// IN clause with array
$users = $db->select('SELECT * FROM users WHERE id IN(?a)', [1, 2, 3]);
// Generates: SELECT * FROM users WHERE id IN(?, ?, ?)

// SET clause with associative array
$affected = $db->query('UPDATE users SET ?a WHERE id = ?', 
    ['name' => 'John', 'email' => 'john@example.com'], 
    123
);
// Generates: UPDATE users SET `name` = ?, `email` = ? WHERE id = ?
```

#### Identifier Placeholder (`?#`)

```php
// Dynamic table/column names
$users = $db->select('SELECT * FROM ?# WHERE ?# = ?', 'users', 'active', 1);
// Generates: SELECT * FROM `users` WHERE `active` = ?

// With qualified identifiers
$data = $db->select('SELECT ?#.* FROM ?#', 'u.name', 'users u');
// Generates: SELECT `u`.`name` FROM `users` `u`
```

#### Prefix Placeholder (`?_`)

```php
// Table name prefixing
$db->setIdentifierPrefix('app_');
$users = $db->select('SELECT * FROM ?_users WHERE ?_users.active = ?', 1);
// Generates: SELECT * FROM `app_users` WHERE `app_users`.`active` = ?
```

### Error Handling and Logging

```php
// Custom error handler
$db->setErrorHandler(function($error, $query, $params) {
    // Log error, send notifications, etc.
    error_log("Database error: " . $error->getMessage());
});

// Query logging
$db->setLogger(function($logData) {
    // Log all queries for debugging/auditing
    error_log(json_encode([
        'query' => $logData['query'],
        'execution_time' => $logData['execution_time'],
        'caller' => $logData['caller']
    ]));
});

// Strict mode (default: true)
$db->setStrictMode(false); // Returns false instead of throwing exceptions
```

### TypedValue Integration

```php
use SomeNamespace\TypedValue;

// Automatic extraction of TypedValue objects
$email = new EmailValue('john@example.com');
$age = new AgeValue(25);

$userId = $db->insert('users', [
    'email' => $email,    // Automatically calls $email->toNative()
    'age' => $age,        // Automatically calls $age->toNative()
    'name' => 'John Doe'  // Regular value passed as-is
]);
```

## Database Support

| Database | Identifier Quoting | Status |
|----------|-------------------|---------|
| MySQL | Backticks (`) | ✅ Full Support |
| PostgreSQL | Double quotes (") | ✅ Full Support |
| SQLite | Brackets ([]) | ✅ Full Support |
| SQL Server | Brackets ([]) | ✅ Basic Support |
| Oracle | Double quotes (") | ✅ Basic Support |

## Security Features

### SQL Injection Prevention

```php
// ✅ SAFE - Uses prepared statements
$users = $db->select('SELECT * FROM users WHERE name = ?', $_POST['name']);

// ✅ SAFE - Identifier quoting
$users = $db->select('SELECT * FROM ?# WHERE ?# = ?', $_POST['table'], $_POST['column'], $_POST['value']);

// ✅ SAFE - Array placeholder
$users = $db->select('SELECT * FROM users WHERE id IN(?a)', $_POST['ids']);

// ❌ IMPOSSIBLE - No string concatenation methods provided
// $users = $db->select('SELECT * FROM users WHERE name = ' . $_POST['name']);
```

### Input Validation

```php
// Automatic TypedValue handling
$email = new EmailValue($_POST['email']); // Validates email format
$age = new AgeValue($_POST['age']);       // Validates age range

$userId = $db->insert('users', [
    'email' => $email,  // Extracted safely with toNative()
    'age' => $age,      // Extracted safely with toNative()
]);
```

### Audit Logging

```php
$db->setLogger(function($logData) {
    // Log to security audit system
    SecurityAudit::log([
        'query' => $logData['query'],
        'params' => $logData['params'],
        'user_id' => getCurrentUserId(),
        'timestamp' => $logData['timestamp'],
        'caller' => $logData['caller']
    ]);
});
```

## Testing

Run the test suite:

```bash
composer test
```

Run with coverage:

```bash
composer test -- --coverage-html coverage
```

Run static analysis:

```bash
composer psalm
composer phpstan
```

## Development

### Requirements

- PHP 8.1 or higher
- PDO extension
- Composer

### Setup

```bash
git clone https://github.com/dzentota/secure-db.git
cd secure-pdo-wrapper
composer install
composer test
```

### Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Run the test suite
6. Submit a pull request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Security

If you discover any security vulnerabilities, please send an email to security@secure-db.com instead of using the issue tracker.

## Changelog

### 1.0.0
- Initial release
- Core secure PDO wrapper functionality
- Special placeholder support
- Transaction management
- Comprehensive test suite 