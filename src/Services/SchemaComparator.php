<?php

namespace Chand335\SchemaSync\Services;

use Illuminate\Database\ConnectionResolverInterface;

class SchemaComparator
{
    public function __construct(protected ConnectionResolverInterface $connections)
    {
    }

    public function compare(string $sourceConnection, string $targetConnection, array $ignoreTables = []): array
    {
        $source = $this->getSchema($sourceConnection, $ignoreTables);
        $target = $this->getSchema($targetConnection, $ignoreTables);

        $diff = [
            'missing_tables' => [],
            'extra_tables' => [],
            'table_differences' => [],
        ];

        $sourceTables = array_keys($source);
        $targetTables = array_keys($target);

        foreach (array_diff($sourceTables, $targetTables) as $table) {
            $diff['missing_tables'][$table] = $source[$table];
        }

        foreach (array_diff($targetTables, $sourceTables) as $table) {
            $diff['extra_tables'][] = $table;
        }

        foreach (array_intersect($sourceTables, $targetTables) as $table) {
            $tableDiff = $this->compareTable($source[$table], $target[$table]);
            if (! empty(array_filter($tableDiff))) {
                $diff['table_differences'][$table] = $tableDiff;
            }
        }

        return $diff;
    }

    protected function getSchema(string $connectionName, array $ignoreTables = []): array
    {
        $connection = $this->connections->connection($connectionName);
        $database = $connection->getDatabaseName();

        $placeholders = implode(',', array_fill(0, count($ignoreTables), '?'));
        $ignoreClause = empty($ignoreTables) ? '' : 'AND TABLE_NAME NOT IN ('.$placeholders.')';

        $tableQuery = 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = "BASE TABLE" '
            .$ignoreClause.' ORDER BY TABLE_NAME';
        $tableParams = array_merge([$database], $ignoreTables);
        $tables = array_map(fn ($row) => $row->TABLE_NAME, $connection->select($tableQuery, $tableParams));

        $schema = [];
        foreach ($tables as $table) {
            $schema[$table] = [
                'columns' => $this->getColumns($connectionName, $table),
                'indexes' => $this->getIndexes($connectionName, $table),
                'foreign_keys' => $this->getForeignKeys($connectionName, $table),
            ];
        }

        return $schema;
    }

    protected function getColumns(string $connectionName, string $table): array
    {
        $connection = $this->connections->connection($connectionName);
        $database = $connection->getDatabaseName();

        $rows = $connection->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT, ORDINAL_POSITION '
            .'FROM INFORMATION_SCHEMA.COLUMNS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$database, $table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->COLUMN_NAME] = [
                'name' => $row->COLUMN_NAME,
                'type' => $row->COLUMN_TYPE,
                'nullable' => $row->IS_NULLABLE === 'YES',
                'default' => $row->COLUMN_DEFAULT,
                'extra' => $row->EXTRA,
                'comment' => $row->COLUMN_COMMENT,
                'position' => (int) $row->ORDINAL_POSITION,
            ];
        }

        return $columns;
    }

    protected function getIndexes(string $connectionName, string $table): array
    {
        $connection = $this->connections->connection($connectionName);
        $database = $connection->getDatabaseName();

        $rows = $connection->select(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME '
            .'FROM INFORMATION_SCHEMA.STATISTICS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, $table]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $name = $row->INDEX_NAME;
            if (! isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'unique' => $row->NON_UNIQUE == 0,
                    'primary' => $name === 'PRIMARY',
                    'columns' => [],
                ];
            }

            $indexes[$name]['columns'][] = $row->COLUMN_NAME;
        }

        return $indexes;
    }

    protected function getForeignKeys(string $connectionName, string $table): array
    {
        $connection = $this->connections->connection($connectionName);
        $database = $connection->getDatabaseName();

        $rows = $connection->select(
            'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, '
            .'c.UPDATE_RULE, c.DELETE_RULE '
            .'FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k '
            .'JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS c '
            .'ON k.CONSTRAINT_NAME = c.CONSTRAINT_NAME AND k.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA '
            .'WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL '
            .'ORDER BY k.CONSTRAINT_NAME',
            [$database, $table]
        );

        $foreignKeys = [];
        foreach ($rows as $row) {
            $name = $row->CONSTRAINT_NAME;
            if (! isset($foreignKeys[$name])) {
                $foreignKeys[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'referenced_table' => $row->REFERENCED_TABLE_NAME,
                    'referenced_columns' => [],
                    'on_update' => $row->UPDATE_RULE,
                    'on_delete' => $row->DELETE_RULE,
                ];
            }

            $foreignKeys[$name]['columns'][] = $row->COLUMN_NAME;
            $foreignKeys[$name]['referenced_columns'][] = $row->REFERENCED_COLUMN_NAME;
        }

        return $foreignKeys;
    }

    protected function compareTable(array $source, array $target): array
    {
        $diff = [
            'add_columns' => [],
            'modify_columns' => [],
            'drop_columns' => [],
            'add_indexes' => [],
            'drop_indexes' => [],
            'add_foreign_keys' => [],
            'drop_foreign_keys' => [],
        ];

        $sourceColumns = $source['columns'];
        $targetColumns = $target['columns'];

        $previous = null;
        foreach ($sourceColumns as $name => $definition) {
            $definition['after'] = $previous;

            if (! isset($targetColumns[$name])) {
                $diff['add_columns'][$name] = $definition;
                $previous = $name;
                continue;
            }

            if ($this->columnChanged($definition, $targetColumns[$name])) {
                $diff['modify_columns'][$name] = $definition;
            }

            $previous = $name;
        }

        foreach ($targetColumns as $name => $definition) {
            if (! isset($sourceColumns[$name])) {
                $diff['drop_columns'][$name] = $definition;
            }
        }

        $sourceIndexes = $source['indexes'];
        $targetIndexes = $target['indexes'];

        foreach ($sourceIndexes as $name => $index) {
            if (! isset($targetIndexes[$name])) {
                $diff['add_indexes'][$name] = $index;
                continue;
            }

            if ($this->indexChanged($index, $targetIndexes[$name])) {
                $diff['drop_indexes'][$name] = $targetIndexes[$name];
                $diff['add_indexes'][$name] = $index;
            }
        }

        foreach ($targetIndexes as $name => $index) {
            if (! isset($sourceIndexes[$name])) {
                $diff['drop_indexes'][$name] = $index;
            }
        }

        $sourceForeign = $source['foreign_keys'];
        $targetForeign = $target['foreign_keys'];

        foreach ($sourceForeign as $name => $fk) {
            if (! isset($targetForeign[$name])) {
                $diff['add_foreign_keys'][$name] = $fk;
                continue;
            }

            if ($this->foreignKeyChanged($fk, $targetForeign[$name])) {
                $diff['drop_foreign_keys'][$name] = $targetForeign[$name];
                $diff['add_foreign_keys'][$name] = $fk;
            }
        }

        foreach ($targetForeign as $name => $fk) {
            if (! isset($sourceForeign[$name])) {
                $diff['drop_foreign_keys'][$name] = $fk;
            }
        }

        return $diff;
    }

    protected function columnChanged(array $source, array $target): bool
    {
        return $source['type'] !== $target['type']
            || $source['nullable'] !== $target['nullable']
            || $this->normaliseDefault($source['default']) !== $this->normaliseDefault($target['default'])
            || $source['extra'] !== $target['extra']
            || $source['comment'] !== $target['comment'];
    }

    protected function indexChanged(array $source, array $target): bool
    {
        if ($source['primary'] !== $target['primary']) {
            return true;
        }

        if ($source['unique'] !== $target['unique']) {
            return true;
        }

        return $source['columns'] !== $target['columns'];
    }

    protected function foreignKeyChanged(array $source, array $target): bool
    {
        return $source['columns'] !== $target['columns']
            || $source['referenced_table'] !== $target['referenced_table']
            || $source['referenced_columns'] !== $target['referenced_columns']
            || $source['on_update'] !== $target['on_update']
            || $source['on_delete'] !== $target['on_delete'];
    }

    protected function normaliseDefault(mixed $default): mixed
    {
        if ($default === null || $default === 'NULL') {
            return null;
        }

        return $default;
    }
}
