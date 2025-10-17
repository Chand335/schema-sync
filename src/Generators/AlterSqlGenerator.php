<?php

namespace Chand335\SchemaSync\Generators;

class AlterSqlGenerator
{
    public function generate(array $diff): array
    {
        $statements = [];

        foreach ($diff['missing_tables'] ?? [] as $table => $definition) {
            $statements[] = $this->createTableStatement($table, $definition);
        }

        foreach ($diff['extra_tables'] ?? [] as $table) {
            $statements[] = sprintf('DROP TABLE `%s`', $table);
        }

        foreach ($diff['table_differences'] ?? [] as $table => $changes) {
            $actions = [];

            foreach ($changes['drop_foreign_keys'] ?? [] as $foreignKey) {
                $actions[] = sprintf('DROP FOREIGN KEY `%s`', $foreignKey['name']);
            }

            foreach ($changes['drop_indexes'] ?? [] as $index) {
                if ($index['primary']) {
                    $actions[] = 'DROP PRIMARY KEY';
                    continue;
                }

                $actions[] = sprintf('DROP INDEX `%s`', $index['name']);
            }

            foreach ($changes['drop_columns'] ?? [] as $column) {
                $actions[] = sprintf('DROP COLUMN `%s`', $column['name']);
            }

            $addColumnActions = [];
            foreach ($changes['add_columns'] ?? [] as $column) {
                $addColumnActions[] = $this->buildAddColumnAction($column);
            }

            usort($addColumnActions, fn ($a, $b) => $a['position'] <=> $b['position']);
            foreach ($addColumnActions as $columnAction) {
                $actions[] = $columnAction['sql'];
            }

            foreach ($changes['modify_columns'] ?? [] as $column) {
                $actions[] = $this->buildModifyColumnAction($column);
            }

            foreach ($changes['add_indexes'] ?? [] as $index) {
                $actions[] = $this->buildAddIndexAction($index);
            }

            foreach ($changes['add_foreign_keys'] ?? [] as $foreignKey) {
                $actions[] = $this->buildAddForeignKeyAction($foreignKey);
            }

            if (! empty($actions)) {
                $statements[] = sprintf('ALTER TABLE `%s`%s%s', $table, PHP_EOL.'    ', implode(','.PHP_EOL.'    ', $actions));
            }
        }

        return array_values(array_filter($statements));
    }

    protected function createTableStatement(string $table, array $definition): string
    {
        $columns = [];
        foreach ($definition['columns'] as $column) {
            $columns[] = $this->buildColumnDefinition($column);
        }

        $primaryKeys = [];
        $uniqueKeys = [];
        $indexes = [];

        foreach ($definition['indexes'] as $index) {
            if ($index['primary']) {
                $primaryKeys[] = sprintf('PRIMARY KEY (%s)', $this->wrapColumns($index['columns']));
                continue;
            }

            if ($index['unique']) {
                $uniqueKeys[] = sprintf('UNIQUE KEY `%s` (%s)', $index['name'], $this->wrapColumns($index['columns']));
                continue;
            }

            $indexes[] = sprintf('KEY `%s` (%s)', $index['name'], $this->wrapColumns($index['columns']));
        }

        $foreignKeys = [];
        foreach ($definition['foreign_keys'] as $foreignKey) {
            $foreignKeys[] = $this->buildForeignKeyDefinition($foreignKey);
        }

        $lines = array_merge($columns, $primaryKeys, $uniqueKeys, $indexes, $foreignKeys);
        $body = implode(','.PHP_EOL.'    ', $lines);

        return sprintf('CREATE TABLE `%s` (%s%s%s)', $table, PHP_EOL.'    ', $body, PHP_EOL.') ENGINE=InnoDB');
    }

    protected function buildAddColumnAction(array $column): array
    {
        $definition = $this->buildColumnDefinition($column);
        $clause = sprintf('ADD COLUMN %s', $definition);

        if ($column['after'] === null) {
            $clause .= ' FIRST';
        } else {
            $clause .= sprintf(' AFTER `%s`', $column['after']);
        }

        return [
            'sql' => $clause,
            'position' => $column['position'],
        ];
    }

    protected function buildModifyColumnAction(array $column): string
    {
        $definition = $this->buildColumnDefinition($column);
        $clause = sprintf('MODIFY COLUMN %s', $definition);

        if ($column['after'] === null) {
            $clause .= ' FIRST';
        } else {
            $clause .= sprintf(' AFTER `%s`', $column['after']);
        }

        return $clause;
    }

    protected function buildAddIndexAction(array $index): string
    {
        if ($index['primary']) {
            return sprintf('ADD PRIMARY KEY (%s)', $this->wrapColumns($index['columns']));
        }

        if ($index['unique']) {
            return sprintf('ADD UNIQUE KEY `%s` (%s)', $index['name'], $this->wrapColumns($index['columns']));
        }

        return sprintf('ADD INDEX `%s` (%s)', $index['name'], $this->wrapColumns($index['columns']));
    }

    protected function buildAddForeignKeyAction(array $foreignKey): string
    {
        $sql = sprintf(
            'ADD CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s)',
            $foreignKey['name'],
            $this->wrapColumns($foreignKey['columns']),
            $foreignKey['referenced_table'],
            $this->wrapColumns($foreignKey['referenced_columns'])
        );

        if (! empty($foreignKey['on_delete']) && $foreignKey['on_delete'] !== 'RESTRICT') {
            $sql .= sprintf(' ON DELETE %s', $foreignKey['on_delete']);
        }

        if (! empty($foreignKey['on_update']) && $foreignKey['on_update'] !== 'RESTRICT') {
            $sql .= sprintf(' ON UPDATE %s', $foreignKey['on_update']);
        }

        return $sql;
    }

    protected function buildColumnDefinition(array $column): string
    {
        $sql = sprintf('`%s` %s', $column['name'], $column['type']);

        $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';

        if (array_key_exists('default', $column)) {
            if ($column['default'] === null) {
                if ($column['nullable']) {
                    $sql .= ' DEFAULT NULL';
                }
            } elseif ($this->isExpressionDefault($column['default'])) {
                $sql .= ' DEFAULT '.$column['default'];
            } else {
                $sql .= ' DEFAULT '.$this->quote($column['default']);
            }
        }

        if (! empty($column['extra'])) {
            $sql .= ' '.strtoupper($column['extra']);
        }

        if (! empty($column['comment'])) {
            $sql .= ' COMMENT '.$this->quote($column['comment']);
        }

        return $sql;
    }

    protected function buildForeignKeyDefinition(array $foreignKey): string
    {
        $sql = sprintf(
            'CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s)',
            $foreignKey['name'],
            $this->wrapColumns($foreignKey['columns']),
            $foreignKey['referenced_table'],
            $this->wrapColumns($foreignKey['referenced_columns'])
        );

        if (! empty($foreignKey['on_delete']) && $foreignKey['on_delete'] !== 'RESTRICT') {
            $sql .= sprintf(' ON DELETE %s', $foreignKey['on_delete']);
        }

        if (! empty($foreignKey['on_update']) && $foreignKey['on_update'] !== 'RESTRICT') {
            $sql .= sprintf(' ON UPDATE %s', $foreignKey['on_update']);
        }

        return $sql;
    }

    protected function wrapColumns(array $columns): string
    {
        return implode(', ', array_map(fn ($column) => sprintf('`%s`', $column), $columns));
    }

    protected function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    protected function isExpressionDefault(string $value): bool
    {
        $upper = strtoupper($value);
        return str_starts_with($upper, 'CURRENT_TIMESTAMP')
            || str_starts_with($upper, 'UUID()')
            || str_starts_with($upper, 'NULL');
    }
}
