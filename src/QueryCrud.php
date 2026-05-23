<?php

namespace Qcrud;

use PDO;
use PDOException;
use InvalidArgumentException;
use Qcrud\Exceptions\QueryException;

/**
 * Qcrud Query Builder - Powerful fluent query builder with intelligent WHERE
 * 
 * @package QRud
 * @author erilshk <erilandocarvalho@gmail.com>
 * 
 * @method QueryCrud select(string $fields)
 * @method QueryCrud join(string|array $table, string $on, string $type = 'INNER')
 * @method QueryCrud joinLeft(string|array $table, string $on)
 * @method QueryCrud joinRight(string|array $table, string $on)
 * @method QueryCrud joinInner(string|array $table, string $on)
 * @method QueryCrud where(string $field, mixed $evaluator, mixed $value = null)
 * @method QueryCrud orWhere(string $field, mixed $evaluator, mixed $value = null)
 * @method QueryCrud whereNot(string $field, mixed $evaluator = null, mixed $value = null)
 * @method QueryCrud orWhereNot(string $field, mixed $evaluator = null, mixed $value = null)
 * @method QueryCrud whereBetween(string $field, mixed $start, mixed $end, bool $not = false)
 * @method QueryCrud whereNotBetween(string $field, mixed $start, mixed $end)
 * @method QueryCrud orWhereBetween(string $field, mixed $start, mixed $end, bool $not = false)
 * @method QueryCrud orWhereNotBetween(string $field, mixed $start, mixed $end)
 * @method QueryCrud whereSub(string $column, string $operator, callable|QueryCrud $callback, ?string $table = null)
 * @method QueryCrud orWhereSub(string $column, string $operator, callable|QueryCrud $callback, ?string $table = null)
 * @method QueryCrud groupBy(string $field)
 * @method QueryCrud having(string $field, mixed $evaluator, mixed $value = null)
 * @method QueryCrud orHaving(string $field, mixed $evaluator, mixed $value = null)
 * @method QueryCrud orderBy(string $field, string $sort = 'ASC')
 * @method QueryCrud limit(int $limit, int $offset = 0)
 * @method QueryCrud union(QueryCrud|callable $query, bool $all = false)
 */
class QueryCrud
{
    protected string $table;
    protected string $fields;
    protected ?PDO $pdo;
    protected array $wheres = [];
    protected array $params = [];
    protected array $joins = [];
    protected array $orWheres = [];
    protected array $havings = [];
    protected array $unions = [];
    protected ?string $order = null;
    protected ?string $limit = null;
    protected ?string $group = null;

    public function __construct(string $table, string $fields, ?PDO $pdo)
    {
        $table = trim($table, '`');
        $pattern = '/^\s*([a-zA-Z_][a-zA-Z0-9_]*(\s+[a-zA-Z_][a-zA-Z0-9_]*)?)\s*$/i';

        if (!preg_match($pattern, $table)) {
            throw new InvalidArgumentException("QRud: Invalid table name in CONSTRUCT: `$table`");
        }
        
        $this->table = $table;
        $this->select($fields);
        $this->pdo = $pdo;
    }

    // -----------------------
    // SELECT
    // -----------------------
    public function select(string $fields): self
    {
        $pattern = '/^\s*(\*|[a-zA-Z_][a-zA-Z0-9_\.]*(\s+(AS\s+)?[a-zA-Z_][a-zA-Z0-9_]*)?)(\s*,\s*[a-zA-Z_][a-zA-Z0-9_\.]*(\s+(AS\s+)?[a-zA-Z_][a-zA-Z0-9_]*)?)*\s*$/i';

        if (!empty($fields) && !preg_match($pattern, $fields)) {
            throw new InvalidArgumentException("QRud: Invalid fields in SELECT: $fields");
        }

        $this->fields = $fields;
        return $this;
    }

    // -----------------------
    // JOIN Methods
    // -----------------------
    public function join(string|array $table, string $on, string $type = 'INNER'): self
    {
        if (is_array($table)) {
            $table = "{$table[0]} AS {$table[1]}";
        } else {
            $table = preg_replace('/(.+)AS\s+(.+)/', '$1 AS $2', $table);
        }
        $this->joins[] = strtoupper($type) . " JOIN $table ON $on";
        return $this;
    }

    public function joinLeft(string|array $table, string $on): self
    {
        return $this->join($table, $on, 'LEFT');
    }

    public function joinRight(string|array $table, string $on): self
    {
        return $this->join($table, $on, 'RIGHT');
    }

    public function joinInner(string|array $table, string $on): self
    {
        return $this->join($table, $on, 'INNER');
    }

    protected function buildJoin(): string
    {
        return $this->joins ? ' ' . implode(' ', $this->joins) : '';
    }

    // -----------------------
    // Intelligent WHERE System
    // -----------------------
    public function where(string $field, mixed $evaluator, mixed $value = null): self
    {
        $this->addWhereCondition($field, $evaluator, $value, 'wheres');
        return $this;
    }

    public function orWhere(string $field, mixed $evaluator, mixed $value = null): self
    {
        $this->addWhereCondition($field, $evaluator, $value, 'orWheres');
        return $this;
    }

    public function whereNot(string $field, mixed $evaluator = null, mixed $value = null): self
    {
        if (str_starts_with($field, '!')) {
            $field = substr($field, 1);
        }
        return $this->where("!$field", $evaluator, $value);
    }

    public function orWhereNot(string $field, mixed $evaluator = null, mixed $value = null): self
    {
        if (str_starts_with($field, '!')) {
            $field = substr($field, 1);
        }
        return $this->orWhere("!$field", $evaluator, $value);
    }

    public function whereBetween(string $field, mixed $start, mixed $end, bool $not = false): self
    {
        $this->addBetweenCondition($field, $start, $end, $not, 'wheres');
        return $this;
    }

    public function whereNotBetween(string $field, mixed $start, mixed $end): self
    {
        return $this->whereBetween($field, $start, $end, true);
    }

    public function orWhereBetween(string $field, mixed $start, mixed $end, bool $not = false): self
    {
        $this->addBetweenCondition($field, $start, $end, $not, 'orWheres');
        return $this;
    }

    public function orWhereNotBetween(string $field, mixed $start, mixed $end): self
    {
        return $this->orWhereBetween($field, $start, $end, true);
    }

    public function whereSub(string $column, string $operator, callable|QueryCrud $callback, ?string $table = null): self
    {
        $this->addSubqueryCondition($column, $operator, $callback, $table, 'wheres');
        return $this;
    }

    public function orWhereSub(string $column, string $operator, callable|QueryCrud $callback, ?string $table = null): self
    {
        $this->addSubqueryCondition($column, $operator, $callback, $table, 'orWheres');
        return $this;
    }

    // -----------------------
    // WHERE Helper Methods
    // -----------------------
    private function addWhereCondition(string $field, mixed $evaluator, mixed $value, string $target): void
    {
        $not = false;

        if (str_starts_with($field, '!')) {
            $not = true;
            $field = substr($field, 1);
        }

        if ($value === null) {
            if (is_null($evaluator)) {
                $this->{$target}[] = "$field IS " . ($not ? 'NOT NULL' : 'NULL');
            } elseif (is_array($evaluator) && count($evaluator) === 3 && $evaluator[1] === '><') {
                if ($not) {
                    $this->{$target}[] = "($field < ? OR $field > ?)";
                } else {
                    $this->{$target}[] = "$field BETWEEN ? AND ?";
                }
                $this->params[] = $evaluator[0];
                $this->params[] = $evaluator[2];
            } elseif (is_array($evaluator)) {
                $placeholders = implode(',', array_fill(0, count($evaluator), '?'));
                $this->{$target}[] = $not
                    ? "$field NOT IN ($placeholders)"
                    : "$field IN ($placeholders)";
                $this->params = array_merge($this->params, $evaluator);
            } else {
                $this->{$target}[] = $not ? "$field <> ?" : "$field = ?";
                $this->params[] = $evaluator;
            }
        } else {
            $this->{$target}[] = $not ? "$field NOT $evaluator ?" : "$field $evaluator ?";
            $this->params[] = $value;
        }
    }

    private function addBetweenCondition(string $field, mixed $start, mixed $end, bool $not, string $target): void
    {
        if ($not) {
            $this->{$target}[] = "($field < ? OR $field > ?)";
        } else {
            $this->{$target}[] = "$field BETWEEN ? AND ?";
        }
        $this->params[] = $start;
        $this->params[] = $end;
    }

    private function addSubqueryCondition(string $column, string $operator, callable|QueryCrud $callback, ?string $table, string $target): void
    {
        if ($callback instanceof QueryCrud) {
            $sub = $callback;
        } else {
            $sub = new self($table ?? $this->table, '', $this->pdo);
            $callback($sub);
        }
        $this->{$target}[] = [$column, $operator, $sub];
    }

    protected function buildWhere(): string
    {
        $whereParts = [];
        $orParts = [];

        // Process WHEREs
        foreach ($this->wheres as $where) {
            $whereParts[] = $this->processWhereItem($where);
        }

        // Process OR WHEREs
        foreach ($this->orWheres as $where) {
            $orParts[] = $this->processWhereItem($where);
        }

        if (!$whereParts && !$orParts) return '';

        $sql = ' WHERE ';
        
        if ($whereParts) {
            $sql .= implode(' AND ', $whereParts);
        }
        
        if ($orParts) {
            if ($whereParts) {
                $sql .= ' AND (' . implode(' OR ', $orParts) . ')';
            } else {
                $sql .= implode(' OR ', $orParts);
            }
        }

        return $sql;
    }

    private function processWhereItem($where): string
    {
        if (is_array($where)) {
            [$col, $op, $sub] = $where;
            $this->params = array_merge($this->params, $sub->params);
            return "$col $op (" . $sub->mountSQL() . ")";
        }
        return $where;
    }

    // -----------------------
    // GROUP BY & HAVING
    // -----------------------
    public function groupBy(string $field): self
    {
        $this->group = "GROUP BY $field";
        return $this;
    }

    public function having(string $field, mixed $evaluator, mixed $value = null): self
    {
        if ($value === null) {
            $this->havings[] = "$field $evaluator ?";
            $this->params[] = $evaluator;
        } else {
            $this->havings[] = "$field $evaluator ?";
            $this->params[] = $value;
        }
        return $this;
    }

    public function orHaving(string $field, mixed $evaluator, mixed $value = null): self
    {
        if ($value === null) {
            $this->havings[] = "OR $field $evaluator ?";
            $this->params[] = $evaluator;
        } else {
            $this->havings[] = "OR $field $evaluator ?";
            $this->params[] = $value;
        }
        return $this;
    }

    protected function buildGroup(): string
    {
        return $this->group ? " {$this->group}" : '';
    }

    protected function buildHaving(): string
    {
        if (!$this->havings) return '';

        $parts = [];
        foreach ($this->havings as $index => $having) {
            if ($index === 0) {
                $parts[] = preg_replace('/^\s*OR\s+/', '', $having);
            } else {
                $parts[] = $having;
            }
        }

        return ' HAVING ' . implode(' ', $parts);
    }

    // -----------------------
    // ORDER, LIMIT & UNION
    // -----------------------
    public function orderBy(string $field, string $sort = 'ASC'): self
    {
        $sort = str_word_count($sort, 1)[0];
        $this->order = "ORDER BY $field " . trim(strtoupper($sort));
        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = "LIMIT $offset, $limit";
        return $this;
    }

    public function union(QueryCrud|callable $query, bool $all = false): self
    {
        if ($query instanceof QueryCrud) {
            $this->unions[] = [$query, $all];
        } else {
            $unionQuery = new self($this->table, $this->fields, $this->pdo);
            $query($unionQuery);
            $this->unions[] = [$unionQuery, $all];
        }
        return $this;
    }

    protected function buildUnion(): string
    {
        if (!$this->unions) return '';

        $unionSql = '';
        foreach ($this->unions as [$query, $all]) {
            $unionSql .= ' UNION ' . ($all ? 'ALL ' : '') . '(' . $query->mountSQL() . ')';
            $this->params = array_merge($this->params, $query->params);
        }

        return $unionSql;
    }

    // -----------------------
    // Execution Methods
    // -----------------------
    public function get(): array
    {
        $sql = $this->mountSQL();

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new QueryException("Query execution failed: " . $e->getMessage());
        }
    }

    public function first(): array
    {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? [];
    }

    public function count(string $field = '*'): int
    {
        $originalFields = $this->fields;
        $this->fields = "COUNT($field) as count";
        
        try {
            $result = $this->first();
            $this->fields = $originalFields;
            return (int) ($result['count'] ?? 0);
        } catch (QueryException $e) {
            $this->fields = $originalFields;
            throw $e;
        }
    }

    public function sum(string $field): float
    {
        $originalFields = $this->fields;
        $this->fields = "SUM($field) as total_sum";
        
        try {
            $result = $this->first();
            $this->fields = $originalFields;
            return (float) ($result['total_sum'] ?? 0);
        } catch (QueryException $e) {
            $this->fields = $originalFields;
            throw $e;
        }
    }

    public function avg(string $field): float
    {
        $originalFields = $this->fields;
        $this->fields = "AVG($field) as average";
        
        try {
            $result = $this->first();
            $this->fields = $originalFields;
            return (float) ($result['average'] ?? 0);
        } catch (QueryException $e) {
            $this->fields = $originalFields;
            throw $e;
        }
    }

    public function exists(): bool
    {
        $originalFields = $this->fields;
        $this->fields = '1 as exists_flag';
        
        try {
            $result = $this->first();
            $this->fields = $originalFields;
            return !empty($result);
        } catch (QueryException $e) {
            $this->fields = $originalFields;
            throw $e;
        }
    }

    public function paginate(int $perPage, int $page = 1): array
    {
        $offset = ($page - 1) * $perPage;
        $data = $this->limit($perPage, $offset)->get();
        $total = $this->count();

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage)
            ]
        ];
    }

    /**
     * Get the generated SQL for debugging
     */
    public function toSql(): string
    {
        return $this->mountSQL();
    }

    /**
     * @internal For debugging purposes
     */
    public function mountSQL(): string
    {
        $sql = "SELECT {$this->fields} FROM {$this->table}"
            . $this->buildJoin()
            . $this->buildWhere()
            . $this->buildGroup()
            . $this->buildHaving();

        if ($this->order) $sql .= " {$this->order}";
        if ($this->limit) $sql .= " {$this->limit}";
        if ($this->unions) $sql .= $this->buildUnion();

        return $sql;
    }

    /**
     * Get bind parameters for debugging
     */
    public function getBindings(): array
    {
        return $this->params;
    }
}
