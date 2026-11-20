<?php

declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(
        string $host,
        string $database,
        string $username,
        string $password,
        string $charset = 'utf8mb4'
    ) {
        $dsn = "mysql:host={$host};dbname={$database};charset={$charset}";

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /** Create a row and return its generated ID. */
    public function insert(string $table, array $data): string
    {
        if ($data === []) {
            throw new InvalidArgumentException('Insert data cannot be empty.');
        }

        $table = $this->quoteIdentifier($table);
        $columns = array_map(
            fn(string $column): string => $this->quoteIdentifier($column),
            array_keys($data)
        );
        $placeholders = array_map(
            fn(int $i): string => ":value{$i}",
            array_keys($data)
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $statement = $this->pdo->prepare($sql);
        $this->executeValues($statement, array_values($data));

        return $this->pdo->lastInsertId();
    }

    /** Read all rows from a table. */
    public function getAll(string $table): array
    {
        $sql = 'SELECT * FROM ' . $this->quoteIdentifier($table);

        return $this->pdo->query($sql)->fetchAll();
    }

    /** Read one row matching the supplied conditions, or return null. */
    public function find(string $table, array $where): ?array
    {
        [$whereSql, $values] = $this->buildWhere($where);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s LIMIT 1',
            $this->quoteIdentifier($table),
            $whereSql
        );

        $statement = $this->pdo->prepare($sql);
        $this->executeValues($statement, $values);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Update rows matching the conditions; returns the number of affected rows. */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Update data cannot be empty.');
        }

        [$whereSql, $whereValues] = $this->buildWhere($where);

        $setParts = [];
        $setValues = [];

        foreach ($data as $column => $value) {
            $placeholder = ':set' . count($setValues);
            $setParts[] = $this->quoteIdentifier((string) $column) . " = {$placeholder}";
            $setValues[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $setParts),
            $whereSql
        );

        $statement = $this->pdo->prepare($sql);
        $this->executeValues($statement, array_merge($setValues, $whereValues));

        return $statement->rowCount();
    }

    /** Delete rows matching the conditions; returns the number of affected rows. */
    public function delete(string $table, array $where): int
    {
        [$whereSql, $values] = $this->buildWhere($where);

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            $whereSql
        );

        $statement = $this->pdo->prepare($sql);
        $this->executeValues($statement, $values);

        return $statement->rowCount();
    }

    private function buildWhere(array $where): array
    {
        if ($where === []) {
            throw new InvalidArgumentException(
                'Conditions cannot be empty; refusing an unrestricted query.'
            );
        }

        $parts = [];
        $values = [];

        foreach ($where as $column => $value) {
            $quotedColumn = $this->quoteIdentifier((string) $column);

            if ($value === null) {
                $parts[] = "{$quotedColumn} IS NULL";
            } else {
                $placeholder = ':where' . count($values);
                $parts[] = "{$quotedColumn} = {$placeholder}";
                $values[] = $value;
            }
        }

        return [implode(' AND ', $parts), $values];
    }

    private function executeValues(PDOStatement $statement, array $values): void
    {
        foreach ($values as $index => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };

            $statement->bindValue($index + 1, $value, $type);
        }

        $statement->execute();
    }

    /** Validate and quote a MySQL table or column identifier. */
    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('Invalid table or column name.');
        }

        return "`{$identifier}`";
    }
}
