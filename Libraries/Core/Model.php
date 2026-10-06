<?php

namespace Libraries\Core;

use PDO;

class Model extends Conexion {

    protected $table;
    protected $primaryKey = 'id';
    protected $originalTable = '';

    protected $select = '*';
    protected $tableAlias = '';
    protected $joins = [];
    protected $whereBuilder = [];
    protected $whereValues = [];
    protected $rawSelectBindings = [];
    protected $joinValues = [];
    protected $groupByFields = [];
    protected $havingBuilder = [];
    protected $havingValues = [];
    protected $orderBy = [];
    protected $limit = '';
    protected $offset = '';

    private static $FORBIDDEN_SQL = [
        'UNION', 'SELECT.*FROM', '--', ';--', '/*', '*/', 'EXEC',
        'EXECUTE', 'DROP', 'DELETE', 'TRUNCATE', 'ALTER', 'CREATE',
        'INSERT', 'SLEEP', 'BENCHMARK', 'LOAD_FILE', 'INTO OUTFILE',
        'INTO DUMPFILE', 'INFORMATION_SCHEMA'
    ];

    private static $AGGREGATE_FUNCTIONS = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    public function __construct() {
        $this->conect = Conexion::getInstance()->conect();
    }

    private function validateIdentifier($name, $context = 'identifier'): string {
        $clean = preg_replace('/[^a-zA-Z0-9_\. ]/', '', $name);
        if (empty($clean) || $clean !== $name) {
            throw new \InvalidArgumentException("Invalid $context: $name");
        }
        return $clean;
    }

    private function quoteFieldPath($field): string {
        $parts = explode('.', $field);
        $quoted = array_map(function($p) { return '`' . trim($p) . '`'; }, $parts);
        return implode('.', $quoted);
    }

    private function validateFieldName($field): string {
        $field = trim($field);
        if ($field === '*' || preg_match('/^[a-zA-Z0-9_]+\.\*$/', $field)) {
            return $field;
        }

        $upper = strtoupper($field);
        foreach (self::$AGGREGATE_FUNCTIONS as $fn) {
            if (preg_match('/^' . $fn . '\s*\(/i', $field)) {
                return $field;
            }
        }

        $clean = preg_replace('/[^a-zA-Z0-9_\. ]/', '', $field);
        $clean = preg_replace('/\s+/', ' ', $clean);
        if ($clean !== $field) {
            throw new \InvalidArgumentException("Invalid field: $field");
        }
        $upperClean = strtoupper($clean);
        if (preg_match('/\b(UNION|SELECT|FROM|WHERE|DROP|DELETE|INSERT|UPDATE|ALTER|CREATE)\b/', $upperClean)) {
            throw new \InvalidArgumentException("Field contains SQL keyword: $field");
        }
        return $clean;
    }

    private function validateDirection($dir): string {
        $dir = strtoupper(trim($dir));
        return ($dir === 'ASC' || $dir === 'DESC') ? $dir : 'ASC';
    }

    private function checkForSqlInjection($value) {
        $upper = strtoupper($value);
        foreach (self::$FORBIDDEN_SQL as $pattern) {
            if (strpos($upper, $pattern) !== false) {
                throw new \InvalidArgumentException("Potential SQL injection detected in: $value");
            }
        }
    }

    private function isExpression(string $field): bool {
        return preg_match('/[\(\)]/', $field) || preg_match('/^[A-Z]+\s*\(/i', $field);
    }

    private function resetQuery() {
        if (!empty($this->originalTable)) {
            $this->table = $this->originalTable;
        }
        $this->select = '*';
        $this->tableAlias = '';
        $this->joins = [];
        $this->whereBuilder = [];
        $this->whereValues = [];
        $this->rawSelectBindings = [];
        $this->joinValues = [];
        $this->groupByFields = [];
        $this->havingBuilder = [];
        $this->havingValues = [];
        $this->orderBy = [];
        $this->limit = '';
        $this->offset = '';
    }

    // ========== CONDITIONAL ==========

    public function when($condition, callable $callback) {
        if ($condition) {
            $callback($this);
        }
        return $this;
    }

    // ========== TABLE SWITCH ==========

    public function table(string $table): self {
        if (empty($this->originalTable)) {
            $this->originalTable = $this->table;
        }
        $parts = preg_split('/\s+/', trim($table), 2);
        $this->table = $parts[0];
        if (isset($parts[1])) {
            $this->tableAlias = trim($parts[1]);
        }
        return $this;
    }

    public function as(string $alias): self {
        $this->tableAlias = $alias;
        return $this;
    }

    private function buildSelectSql(): string {
        $fromClause = '`' . $this->table . '`';
        if (!empty($this->tableAlias)) {
            $fromClause .= ' `' . $this->tableAlias . '`';
        }
        $sql = "SELECT $this->select FROM $fromClause ";
        if (!empty($this->joins)) {
            $sql .= implode(" ", $this->joins) . " ";
        }
        if (!empty($this->whereBuilder)) {
            $sql .= "WHERE " . implode(" AND ", $this->whereBuilder) . " ";
        }
        if (!empty($this->groupByFields)) {
            $sql .= "GROUP BY " . implode(", ", $this->groupByFields) . " ";
        }
        if (!empty($this->havingBuilder)) {
            $sql .= "HAVING " . implode(" AND ", $this->havingBuilder) . " ";
        }
        if (!empty($this->orderBy)) {
            $sql .= "ORDER BY " . implode(", ", $this->orderBy) . " ";
        } else {
            $orderTable = !empty($this->tableAlias) ? $this->tableAlias : $this->table;
            $sql .= "ORDER BY " . $this->quoteFieldPath($orderTable . "." . $this->primaryKey) . " ASC ";
        }
        if (!empty($this->limit)) {
            $sql .= $this->limit . " ";
        }
        if (!empty($this->offset)) {
            $sql .= $this->offset;
        }
        return $sql;
    }

    private function bindWhereValues($stmt) {
        foreach ($this->whereValues as $key => $value) {
            $stmt->bindValue(":where_$key", $value);
        }
    }

    private function bindHavingValues($stmt) {
        foreach ($this->havingValues as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
    }

    private function bindRawSelectValues($stmt) {
        foreach ($this->rawSelectBindings as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
    }

    private function bindJoinValues($stmt) {
        foreach ($this->joinValues as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
    }

    // ========== SELECT ==========

    public function select($fields) {
        $validated = array_map([$this, 'validateFieldName'], $fields);
        $quoted = array_map(function($f) {
            if ($f === '*' || preg_match('/^[a-zA-Z0-9_]+\.\*$/', $f)) {
                return $f;
            }
            if (preg_match('/\s+AS\s+/i', $f)) {
                $parts = preg_split('/\s+AS\s+/i', $f, 2);
                return $this->quoteFieldPath(trim($parts[0])) . ' AS `' . trim($parts[1]) . '`';
            }
            return $this->quoteFieldPath($f);
        }, $validated);
        $this->select = implode(", ", $quoted);
        return $this;
    }

    public function selectRaw(string $expression, array $bindings = []): self {
        if ($this->select === '*') {
            $this->select = $expression;
        } else {
            $this->select .= ', ' . $expression;
        }
        foreach ($bindings as $key => $value) {
            $this->rawSelectBindings[$key] = $value;
        }
        return $this;
    }

    // ========== JOIN ==========

    public function join($table, $condition, $type = 'INNER', array $bindings = []) {
        $allowedTypes = ['INNER', 'LEFT', 'RIGHT', 'CROSS'];
        $type = strtoupper(trim($type));
        if (!in_array($type, $allowedTypes)) {
            throw new \InvalidArgumentException("Invalid JOIN type: $type");
        }
        $quotedCondition = preg_replace_callback('/([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)/', function($m) {
            return '`' . $m[1] . '`.`' . $m[2] . '`';
        }, $condition);
        if (strpos(trim($table), '(') === 0) {
            $this->joins[] = "$type JOIN $table ON $quotedCondition";
        } else {
            $this->validateIdentifier($table, 'join table');
            $tableParts = preg_split('/\s+/', trim($table), 2);
            $quotedTable = '`' . $tableParts[0] . '`';
            if (isset($tableParts[1])) {
                $quotedTable .= ' `' . $tableParts[1] . '`';
            }
            $this->joins[] = "$type JOIN $quotedTable ON $quotedCondition";
        }
        foreach ($bindings as $key => $value) {
            $this->joinValues[$key] = $value;
        }
        return $this;
    }

    public function leftJoin($table, $condition, array $bindings = []) {
        return $this->join($table, $condition, 'LEFT', $bindings);
    }

    public function rightJoin($table, $condition, array $bindings = []) {
        return $this->join($table, $condition, 'RIGHT', $bindings);
    }

    // ========== WHERE ==========

    public function where($conditions) {
        foreach ($conditions as $key => $condition) {
            if (is_numeric($key)) {
                $this->checkForSqlInjection($condition);
                $this->whereBuilder[] = $condition;
            } else {
                $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                $this->whereBuilder[] = $this->quoteFieldPath($key) . " = :where_$safeKey";
                $this->whereValues[$safeKey] = $condition;
            }
        }
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): self {
        $this->checkForSqlInjection($sql);
        $this->whereBuilder[] = $sql;
        foreach ($bindings as $key => $value) {
            $this->whereValues[$key] = $value;
        }
        return $this;
    }

    public function orWhere($conditions) {
        $parts = [];
        foreach ($conditions as $key => $condition) {
            if (is_numeric($key)) {
                $this->checkForSqlInjection($condition);
                $parts[] = $condition;
            } else {
                $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
                $parts[] = $this->quoteFieldPath($key) . " = :where_or_$safeKey";
                $this->whereValues["or_$safeKey"] = $condition;
            }
        }
        $this->whereBuilder[] = '(' . implode(' OR ', $parts) . ')';
        return $this;
    }

    public function whereBetween(string $field, $min, $max): self {
        $safeField = $this->isExpression($field) ? $field : $this->quoteFieldPath($field);
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        $this->whereBuilder[] = "$safeField BETWEEN :where_between_min_$safeKey AND :where_between_max_$safeKey";
        $this->whereValues["between_min_$safeKey"] = $min;
        $this->whereValues["between_max_$safeKey"] = $max;
        return $this;
    }

    public function whereNotBetween(string $field, $min, $max): self {
        $safeField = $this->isExpression($field) ? $field : $this->quoteFieldPath($field);
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        $this->whereBuilder[] = "$safeField NOT BETWEEN :where_nb_min_$safeKey AND :where_nb_max_$safeKey";
        $this->whereValues["nb_min_$safeKey"] = $min;
        $this->whereValues["nb_max_$safeKey"] = $max;
        return $this;
    }

    public function whereIn(string $field, array $values): self {
        if (empty($values)) {
            $this->whereBuilder[] = "1 = 0";
            return $this;
        }
        $safeField = $this->quoteFieldPath($field);
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        $placeholders = [];
        foreach ($values as $i => $val) {
            $ph = ":where_in_{$safeKey}_{$i}";
            $placeholders[] = $ph;
            $this->whereValues["in_{$safeKey}_{$i}"] = $val;
        }
        $this->whereBuilder[] = "$safeField IN (" . implode(', ', $placeholders) . ")";
        return $this;
    }

    public function whereNotIn(string $field, array $values): self {
        if (empty($values)) {
            return $this;
        }
        $safeField = $this->quoteFieldPath($field);
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        $placeholders = [];
        foreach ($values as $i => $val) {
            $ph = ":where_ni_{$safeKey}_{$i}";
            $placeholders[] = $ph;
            $this->whereValues["ni_{$safeKey}_{$i}"] = $val;
        }
        $this->whereBuilder[] = "$safeField NOT IN (" . implode(', ', $placeholders) . ")";
        return $this;
    }

    public function whereNull(string $field): self {
        $this->whereBuilder[] = $this->quoteFieldPath($field) . " IS NULL";
        return $this;
    }

    public function whereNotNull(string $field): self {
        $this->whereBuilder[] = $this->quoteFieldPath($field) . " IS NOT NULL";
        return $this;
    }

    public function whereColumn(string $field1, string $operator, string $field2): self {
        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>='];
        $operator = trim($operator);
        if (!in_array($operator, $allowedOps)) {
            throw new \InvalidArgumentException("Invalid operator: $operator");
        }
        $this->whereBuilder[] = $this->quoteFieldPath($field1) . " $operator " . $this->quoteFieldPath($field2);
        return $this;
    }

    public function whereExists(callable $callback): self {
        $sub = new static();
        $callback($sub);
        $subSql = $sub->toSql();
        $this->whereBuilder[] = "EXISTS ($subSql)";
        foreach ($sub->getBindValues() as $key => $value) {
            $this->whereValues["exists_$key"] = $value;
        }
        return $this;
    }

    public function whereNotExists(callable $callback): self {
        $sub = new static();
        $callback($sub);
        $subSql = $sub->toSql();
        $this->whereBuilder[] = "NOT EXISTS ($subSql)";
        foreach ($sub->getBindValues() as $key => $value) {
            $this->whereValues["nexists_$key"] = $value;
        }
        return $this;
    }

    // ========== LIKE ==========

    public function likeWhere(string $field, string $value): self {
        $safeField = $this->quoteFieldPath($field);
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        $this->whereBuilder[] = "$safeField LIKE :where_like_$safeKey";
        $this->whereValues["like_$safeKey"] = '%' . $value . '%';
        return $this;
    }

    public function orLikeWhere(array $fieldValuePairs, string $groupPrefix = ''): self {
        $parts = [];
        foreach ($fieldValuePairs as $field => $value) {
            $safeField = $this->quoteFieldPath($field);
            $safeKey = $groupPrefix . preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
            $parts[] = "$safeField LIKE :where_like_$safeKey";
            $this->whereValues["like_$safeKey"] = '%' . $value . '%';
        }
        $this->whereBuilder[] = '(' . implode(' OR ', $parts) . ')';
        return $this;
    }

    // ========== GROUP BY ==========

    public function groupBy(...$fields) {
        foreach ($fields as $field) {
            if (is_array($field)) {
                foreach ($field as $f) {
                    $this->groupByFields[] = $this->quoteFieldPath($f);
                }
            } else {
                $this->groupByFields[] = $this->quoteFieldPath($field);
            }
        }
        return $this;
    }

    public function groupByRaw(string $expression): self {
        $this->checkForSqlInjection($expression);
        $this->groupByFields[] = $expression;
        return $this;
    }

    // ========== HAVING ==========

    public function having(string $field, string $operator, $value): self {
        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>='];
        $operator = trim($operator);
        if (!in_array($operator, $allowedOps)) {
            throw new \InvalidArgumentException("Invalid having operator: $operator");
        }
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
        $this->havingBuilder[] = $this->quoteFieldPath($field) . " $operator :having_$safeKey";
        $this->havingValues["having_$safeKey"] = $value;
        return $this;
    }

    public function havingRaw(string $sql, array $bindings = []): self {
        $this->checkForSqlInjection($sql);
        $this->havingBuilder[] = $sql;
        foreach ($bindings as $key => $value) {
            $this->havingValues[$key] = $value;
        }
        return $this;
    }

    // ========== ORDER BY ==========

    public function orderBy($field, $direction = 'ASC') {
        $field = $this->validateFieldName($field);
        $direction = $this->validateDirection($direction);
        $this->orderBy[] = $this->quoteFieldPath($field) . " $direction";
        return $this;
    }

    public function orderByRaw(string $expression): self {
        $this->checkForSqlInjection($expression);
        $this->orderBy[] = $expression;
        return $this;
    }

    // ========== LIMIT / OFFSET ==========

    public function limit($limit) {
        $limit = intval($limit);
        $this->limit = "LIMIT $limit";
        return $this;
    }

    public function offset($offset) {
        $offset = intval($offset);
        $this->offset = "OFFSET $offset";
        return $this;
    }

    // ========== EXECUTE ==========

    public function get() {
        $sql = $this->buildSelectSql();
        $stmt = $this->conect()->prepare($sql);
        $this->bindWhereValues($stmt);
        $this->bindHavingValues($stmt);
        $this->bindRawSelectValues($stmt);
        $this->bindJoinValues($stmt);
        $stmt->execute();
        $this->resetQuery();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function first() {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? null;
    }

    public function pluck(string $column) {
        $this->select([$column]);
        $rows = $this->get();
        return array_map(function($row) use ($column) {
            $alias = str_replace('`', '', explode(' AS ', $column)[0] ?? $column);
            return $row[$alias] ?? reset($row);
        }, $rows);
    }

    public function value(string $column) {
        $row = $this->first();
        if (!$row) return null;
        $alias = str_replace('`', '', explode(' AS ', $column)[0] ?? $column);
        return $row[$alias] ?? reset($row);
    }

    // ========== AGGREGATES ==========

    public function count($condition = '') {
        $sql = "SELECT COUNT(*) FROM `$this->table`";
        if (!empty($this->whereBuilder)) {
            $sql .= " WHERE " . implode(" AND ", $this->whereBuilder);
        } elseif (!empty($condition)) {
            $this->checkForSqlInjection($condition);
            $sql .= " WHERE $condition";
        }
        $stmt = $this->conect()->prepare($sql);
        $this->bindWhereValues($stmt);
        $stmt->execute();
        $result = $stmt->fetchColumn();
        $this->resetQuery();
        return $result;
    }

    public function countWithQuery() {
        $sql = "SELECT COUNT(*) as total FROM `$this->table` ";
        if (!empty($this->joins)) {
            $sql .= implode(" ", $this->joins) . " ";
        }
        if (!empty($this->whereBuilder)) {
            $sql .= "WHERE " . implode(" AND ", $this->whereBuilder) . " ";
        }
        $stmt = $this->conect()->prepare($sql);
        $this->bindWhereValues($stmt);
        $this->bindJoinValues($stmt);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    public function sum($field) {
        $field = $this->validateFieldName($field);
        $sql = "SELECT SUM(" . $this->quoteFieldPath($field) . ") FROM `$this->table`";
        if (!empty($this->whereBuilder)) {
            $sql .= " WHERE " . implode(" AND ", $this->whereBuilder);
        }
        $stmt = $this->conect()->prepare($sql);
        $this->bindWhereValues($stmt);
        $stmt->execute();
        $result = $stmt->fetchColumn() ?: 0.00;
        $this->resetQuery();
        return $result;
    }

    public function avg($field) {
        $field = $this->validateFieldName($field);
        $sql = "SELECT AVG(" . $this->quoteFieldPath($field) . ") FROM `$this->table`";
        if (!empty($this->whereBuilder)) {
            $sql .= " WHERE " . implode(" AND ", $this->whereBuilder);
        }
        $stmt = $this->conect()->prepare($sql);
        $this->bindWhereValues($stmt);
        $stmt->execute();
        $result = $stmt->fetchColumn() ?: 0.00;
        $this->resetQuery();
        return $result;
    }

    public function min($field) {
        $field = $this->validateFieldName($field);
        $sql = "SELECT MIN(" . $this->quoteFieldPath($field) . ") FROM `$this->table`";
        if (!empty($this->whereBuilder)) {
            $sql .= " WHERE " . implode(" AND ", $this->whereBuilder);
        }
        $stmt = $this->conect()->prepare($sql);
        $this->bindWhereValues($stmt);
        $stmt->execute();
        $result = $stmt->fetchColumn() ?: 0;
        $this->resetQuery();
        return $result;
    }

    public function max($field) {
        $field = $this->validateFieldName($field);
        $sql = "SELECT MAX(" . $this->quoteFieldPath($field) . ") FROM `$this->table`";
        if (!empty($this->whereBuilder)) {
            $sql .= " WHERE " . implode(" AND ", $this->whereBuilder);
        }
        $stmt = $this->conect()->prepare($sql);
        $this->bindWhereValues($stmt);
        $stmt->execute();
        $result = $stmt->fetchColumn() ?: 0;
        $this->resetQuery();
        return $result;
    }

    public function exists(): bool {
        $sql = "SELECT 1 FROM `$this->table` ";
        if (!empty($this->joins)) {
            $sql .= implode(" ", $this->joins) . " ";
        }
        if (!empty($this->whereBuilder)) {
            $sql .= "WHERE " . implode(" AND ", $this->whereBuilder) . " ";
        }
        $sql .= "LIMIT 1";
        $stmt = $this->conect()->prepare($sql);
        $this->bindWhereValues($stmt);
        $this->bindJoinValues($stmt);
        $stmt->execute();
        $this->resetQuery();
        return $stmt->fetch() !== false;
    }

    // ========== DEBUG ==========

    public function toSql(): string {
        return $this->buildSelectSql();
    }

    public function getBindValues(): array {
        return array_merge($this->whereValues, $this->joinValues, $this->havingValues, $this->rawSelectBindings);
    }

    // ========== CRUD ==========

    public function all() {
        $sql = "SELECT * FROM `$this->table` ORDER BY `$this->primaryKey` ASC";
        $stmt = $this->conect()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id) {
        $sql = "SELECT * FROM `$this->table` WHERE `$this->primaryKey` = :id";
        $stmt = $this->conect()->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function delete($id) {
        $sql = "DELETE FROM `$this->table` WHERE `$this->primaryKey` = :id";
        $stmt = $this->conect()->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function insert($data) {
        $columns = implode(", ", array_map(function($k) { return $this->quoteFieldPath($k); }, array_keys($data)));
        $placeholders = ":" . implode(", :", array_keys($data));
        $sql = "INSERT INTO `$this->table` ($columns) VALUES ($placeholders)";
        $stmt = $this->conect()->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        return $stmt->execute();
    }

    public function update($id, $data) {
        $setClause = "";
        foreach ($data as $key => $value) {
            $safeKey = $this->validateFieldName($key);
            $setClause .= $this->quoteFieldPath($safeKey) . " = :$safeKey, ";
        }
        $setClause = rtrim($setClause, ", ");
        $sql = "UPDATE `$this->table` SET $setClause WHERE `$this->primaryKey` = :id";
        $stmt = $this->conect()->prepare($sql);
        foreach ($data as $key => $value) {
            $safeKey = $this->validateFieldName($key);
            $stmt->bindValue(":$safeKey", $value);
        }
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function getTable() {
        return $this->table;
    }

    public function getPrimaryKey() {
        return $this->primaryKey;
    }
}
