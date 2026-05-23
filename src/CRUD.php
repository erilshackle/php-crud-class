<?php

namespace Qcrud;

use PDO;
use PDOException;
use Qcrud\Exceptions\ConnectionException;
use Qcrud\Exceptions\QueryException;

/**
 * Qcrud - Powerful CRUD Operations with Query Builder
 * 
 * @package QRud
 * @author erilshk <erilandocarvalho@gmail.com>
 * @license MIT
 * @version 1.0.0
 * 
 * @method static CRUD table(string $name, string $refKey = "id")
 * @method static QueryCrud query(string|array $table, string $select = '*')
 */
class CRUD
{
    protected string $table;
    protected ?string $pk = null;
    private static ?PDO $pdo = null;
    private static ?array $register = null;
    private static array $transactionsCallbacks = [];
    private static bool $onTransaction = false;

    /**
     * Register database connection
     */
    public static function registerConnection(PDO|array $pdo): void
    {
        if ($pdo instanceof PDO) {
            self::useConnection($pdo);
        } else {
            self::$register = $pdo;
        }
    }

    protected static function useConnection(?PDO $pdo = null): void
    {
        try {
            if ($pdo !== null) {
                self::$pdo = $pdo;
            } elseif (self::$register !== null) {
                self::$pdo = call_user_func(self::$register);
                self::$register = null;
            }
        } catch (PDOException $e) {
            throw new ConnectionException("Connection error: " . $e->getMessage());
        }
    }

    public function __construct(string $table, string $primaryKey = 'id', ?PDO $pdo = null)
    {
        $this->table = $table;
        $this->pk = $primaryKey;
        self::useConnection($pdo);

        if (self::$pdo === null) {
            throw new ConnectionException("No database connection available");
        }
    }

    protected function pdo(): PDO
    {
        return self::$pdo;
    }

    /**
     * Quick table instantiation
     */
    public static function table(string $name, string $refKey = "id"): self
    {
        return new self($name, $refKey);
    }

    /**
     * INSERT operation
     */
    public function create(array $data): int|bool
    {
        try {
            $columns = implode(',', array_keys($data));
            $placeholders = implode(',', array_fill(0, count($data), '?'));
            $sql = "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
            $result = $this->executeSQL($sql, array_values($data));
            return $result > 0 ? (int) self::$pdo->lastInsertId() : false;
        } catch (PDOException $e) {
            throw new QueryException("Create failed: " . $e->getMessage());
        }
    }

    /**
     * READ operation
     */
    public function read(string|int|null $id = null, ?string $select = null): array
    {
        $cols = $select ? array_map('trim', explode(',', $select)) : ['*'];
        $valid = array_filter($cols, fn($c) => $c === '*' || preg_match('/^[\w]+(\s+as\s+[\w]+|\s+[\w]+)?$/i', $c));
        $select = $valid ? implode(', ', $valid) : '*';

        try {
            if ($id === null) {
                return $this->querySQL("SELECT $select FROM {$this->table}");
            }
            $id = is_array($id) ? $id[$this->pk] ?? 0 : $id;
            return $this->querySQL("SELECT $select FROM {$this->table} WHERE {$this->pk}=? LIMIT 1", [$id]) ?: [];
        } catch (PDOException $e) {
            throw new QueryException("Read failed: " . $e->getMessage());
        }
    }

    /**
     * UPDATE operation
     */
    public function update(string|int $id, array $data): int|bool
    {
        try {
            $set = [];
            $params = [];
            foreach ($data as $column => $value) {
                $set[] = "$column = ?";
                $params[] = $value;
            }
            $setClause = implode(', ', $set);
            $sql = "UPDATE {$this->table} SET $setClause WHERE {$this->pk} = ?";
            $params[] = $id;
            return $this->executeSQL($sql, $params);
        } catch (PDOException $e) {
            throw new QueryException("Update failed: " . $e->getMessage());
        }
    }

    /**
     * DELETE operation
     */
    public function delete(mixed $id): int|bool
    {
        try {
            $id = is_array($id) ? $id[$this->pk] ?? 0 : $id;
            $sql = "DELETE FROM {$this->table} WHERE {$this->pk} = ?";
            return $this->executeSQL($sql, [$id]);
        } catch (PDOException $e) {
            throw new QueryException("Delete failed: " . $e->getMessage());
        }
    }

    /**
     * SELECT with custom WHERE
     */
    public function select(?string $where = null, array $params = [], ?string $fields = '*'): array
    {
        $fields  ??= '*';
        $cols = $fields ? array_map('trim', explode(',', $fields)) : ['*'];
        $valid = array_filter(
            $cols,
            fn($c) => $c === '*' || preg_match('/^[\w]+(\s+as\s+[\w]+)?$/i', $c)
        );
        $fields = $valid ? implode(', ', $valid) : '*';

        $sql = "SELECT $fields FROM {$this->table}";
        if ($where) {
            $sql .= " WHERE $where";
        }

        return $this->querySQL($sql, $params);
    }

    /**
     * Static query builder entry point
     */
    public static function query(string|array $table, string $select = '*'): QueryCrud
    {
        self::useConnection();
        if (!self::$pdo) {
            throw new ConnectionException("No database connection available for query");
        }
        if (is_array($table)) {
            $table = "{$table[0]} {$table[1]}";
        } else {
            $table = preg_replace('/(.+)\s*AS\s+(.+)/', '$1 $2', $table);
        }
        return new QueryCrud($table, $select, self::$pdo);
    }

    /**
     * Transaction management
     */
    public static function beginTransaction(): void
    {
        self::$onTransaction = true;
        self::$transactionsCallbacks = [];
    }

    public static function commit(): bool
    {
        self::$onTransaction = false;
        $db = self::$pdo;
        
        try {
            $db->beginTransaction();
            foreach (self::$transactionsCallbacks as $callback) {
                $callback();
            }
            $db->commit();
            self::$transactionsCallbacks = [];
            return true;
        } catch (PDOException $e) {
            $db->rollBack();
            self::$transactionsCallbacks = [];
            throw new QueryException("Transaction failed: " . $e->getMessage());
        }
    }

    public static function rollback(): void
    {
        self::$onTransaction = false;
        self::$transactionsCallbacks = [];
    }

    private function querySQL(string $sql, array $params = []): array
    {
        try {
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);

            return preg_match('/LIMIT\s+1/i', $sql)
                ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: [])
                : $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new QueryException("Query failed: " . $e->getMessage());
        }
    }

    private function executeSQL(string $sql, array $params = []): int
    {
        if (self::$onTransaction) {
            self::$transactionsCallbacks[] = function () use ($sql, $params) {
                $stmt = self::$pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->rowCount();
            };
            return 0;
        }

        try {
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new QueryException("Execute failed: " . $e->getMessage());
        }
    }
}
