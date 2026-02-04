<?php

namespace GiresCICD;

class Replication {
    private $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function get_sets() {
        return $this->settings->get('replication_sets', []);
    }

    public function get_set_by_name($name) {
        foreach ($this->get_sets() as $set) {
            if (!empty($set['id']) && $set['id'] === $name) {
                return $set;
            }
            if (!empty($set['name']) && $set['name'] === $name) {
                return $set;
            }
        }
        return null;
    }

    public function export_sql(array $set, array $override = []) {
        global $wpdb;

        $tables = $this->resolve_tables($set['tables'] ?? []);
        $search = $this->normalize_lines($override['search'] ?? ($set['search'] ?? ''));
        $replace = $this->normalize_lines($override['replace'] ?? ($set['replace'] ?? ''));
        $exclude_prefix = $override['exclude_option_prefix'] ?? ($set['exclude_option_prefix'] ?? '');
        $search_only = $override['search_only_tables'] ?? [];
        $search_only = $this->normalize_lines($search_only);

        $sql = "SET foreign_key_checks=0;\n";
        $sql .= "SET sql_mode='NO_AUTO_VALUE_ON_ZERO';\n\n";

        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_A);
            if (!$create || empty($create['Create Table'])) {
                continue;
            }

            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $create['Create Table'] . ";\n\n";

            $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
            if (empty($rows)) {
                continue;
            }

            $columns = array_keys($rows[0]);
            $column_list = '`' . implode('`,`', $columns) . '`';

            $values = [];
            foreach ($rows as $row) {
                if ($exclude_prefix && $this->should_skip_row($table, $row, $exclude_prefix)) {
                    continue;
                }

                $processed = [];
                foreach ($columns as $column) {
                $value = $row[$column];
                if (empty($search_only) || in_array($table, $search_only, true)) {
                    $value = $this->apply_search_replace($value, $search, $replace);
                }
                $processed[] = $this->sql_value($value);
            }
            $values[] = '(' . implode(',', $processed) . ')';
        }

            if (!empty($values)) {
                $sql .= "INSERT INTO `{$table}` ({$column_list}) VALUES\n" . implode(",\n", $values) . ";\n\n";
            }
        }

        $sql .= "SET foreign_key_checks=1;\n";

        return $sql;
    }

    public function import_sql(string $sql, array $set, array $options = []) {
        global $wpdb;

        $temp_prefix = $options['temp_prefix'] ?? ($set['temp_prefix'] ?? '');
        $use_temp = !empty($temp_prefix);
        $skip_rename = !empty($options['skip_rename']);

        $tables = $this->resolve_tables($set['tables'] ?? '');
        $table_map = [];
        if ($use_temp) {
            foreach ($tables as $table) {
                $table_map[$table] = $temp_prefix . $table;
            }
        }

        $sql_to_run = $use_temp ? $this->rewrite_sql_table_names($sql, $table_map) : $sql;
        $statements = $this->split_sql($sql_to_run);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $result = $wpdb->query($statement);
            if ($result === false) {
                return [
                    'success' => false,
                    'message' => $wpdb->last_error ?: 'Erreur SQL',
                ];
            }
        }

        if ($use_temp && !$skip_rename) {
            foreach ($table_map as $original => $temp) {
                $wpdb->query("DROP TABLE IF EXISTS `{$original}`");
                $wpdb->query("RENAME TABLE `{$temp}` TO `{$original}`");
            }
        }

        return [
            'success' => true,
            'message' => 'Import réussi',
        ];
    }

    private function resolve_tables($tables_raw) {
        global $wpdb;

        if (is_array($tables_raw)) {
            $tables = array_filter(array_map('trim', $tables_raw));
            if (!empty($tables)) {
                return $tables;
            }
        }

        $tables_raw = trim((string) $tables_raw);
        if ($tables_raw === '') {
            $all = $wpdb->get_col('SHOW TABLES');
            return is_array($all) ? $all : [];
        }

        $tables = preg_split('/\r?\n/', $tables_raw);
        $tables = array_filter(array_map('trim', $tables));
        return $tables;
    }

    private function normalize_lines($value) {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        $lines = preg_split('/\r?\n/', (string) $value);
        return array_values(array_filter(array_map('trim', $lines)));
    }

    private function apply_search_replace($value, array $search, array $replace) {
        if (empty($search) || count($search) !== count($replace)) {
            return $value;
        }

        if (is_null($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $maybe = @unserialize($value);
        if ($maybe !== false || $value === 'b:0;') {
            $replaced = $this->recursive_replace($maybe, $search, $replace);
            return serialize($replaced);
        }

        return str_replace($search, $replace, $value);
    }

    private function recursive_replace($data, array $search, array $replace) {
        if (is_string($data)) {
            return str_replace($search, $replace, $data);
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->recursive_replace($value, $search, $replace);
            }
            return $data;
        }

        if (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->recursive_replace($value, $search, $replace);
            }
            return $data;
        }

        return $data;
    }

    private function sql_value($value) {
        if (is_null($value)) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value) && !is_string($value)) {
            return (string) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return "'" . addslashes($value) . "'";
        }
        return "'" . addslashes((string) $value) . "'";
    }

    private function should_skip_row($table, array $row, $exclude_prefix) {
        if ($exclude_prefix === '') {
            return false;
        }
        if (substr($table, -7) !== 'options') {
            return false;
        }
        $option_name = $row['option_name'] ?? '';
        return strpos($option_name, $exclude_prefix) === 0;
    }

    private function split_sql($sql) {
        $statements = [];
        $buffer = '';
        $in_string = false;
        $string_char = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($in_string) {
                if ($char === $string_char && $sql[$i - 1] !== '\\') {
                    $in_string = false;
                    $string_char = '';
                }
                $buffer .= $char;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $in_string = true;
                $string_char = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $statements[] = $buffer . ';';
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    private function rewrite_sql_table_names($sql, array $table_map) {
        foreach ($table_map as $original => $temp) {
            $sql = str_replace("`{$original}`", "`{$temp}`", $sql);
        }
        return $sql;
    }
}
