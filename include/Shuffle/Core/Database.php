<?php
namespace Shuffle\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper providing centralized database access.
 *
 * All queries use parameterized placeholders. No string interpolation in SQL.
 */
class Database
{
    private PDO $pdo;

    /**
     * Creates PDO connection with ERRMODE_EXCEPTION and utf8mb4 charset.
     *
     * @param array $config Database configuration array with keys:
     *                      host, port, name, user, password, charset
     */
    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset'] ?? 'utf8mb4'
        );

        $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * Prepares and executes a parameterized query.
     *
     * @param string $sql    SQL statement with placeholders
     * @param array  $params Bound parameters
     * @return PDOStatement  The executed statement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Returns a single row as an associative array, or null.
     *
     * @param string $sql    SQL statement with placeholders
     * @param array  $params Bound parameters
     * @return array|null    Single row or null if not found
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Returns all rows as an array of associative arrays.
     *
     * @param string $sql    SQL statement with placeholders
     * @param array  $params Bound parameters
     * @return array         Array of associative arrays
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Executes a statement and returns affected row count.
     *
     * @param string $sql    SQL statement with placeholders
     * @param array  $params Bound parameters
     * @return int           Number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Returns the last auto-increment ID.
     *
     * @return string Last insert ID
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Starts a database transaction.
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commits the current transaction.
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Rolls back the current transaction.
     */
    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * Returns the underlying PDO instance for edge cases.
     *
     * @return PDO The PDO connection
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
