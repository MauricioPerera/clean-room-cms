<?php
/**
 * Clean Room CMS - Database Abstraction Layer
 *
 * Provides safe database access with prepared statements,
 * result fetching, and CRUD helpers.
 */

class CR_Database {
    private ?PDO $pdo = null;
    public string $prefix;
    public int $num_rows = 0;
    public int $rows_affected = 0;
    public ?int $insert_id = null;
    public ?string $last_error = null;
    public ?string $last_query = null;

    private static ?CR_Database $instance = null;

    public static function instance(): CR_Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $table_prefix;
        $this->prefix = $table_prefix ?? 'cr_';
    }

    public function connect(): void {
        if ($this->pdo !== null) {
            return;
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            if (defined('DB_COLLATE') && DB_COLLATE !== '') {
                $this->pdo->exec("SET NAMES '" . DB_CHARSET . "' COLLATE '" . DB_COLLATE . "'");
            }
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            if (defined('CR_DEBUG') && CR_DEBUG) {
                throw $e;
            }
            die('Database connection error.');
        }
    }

    /**
     * Prepare a SQL statement with sprintf-style placeholders.
     * %s = string, %d = integer, %f = float
     */
    public function prepare(string $query, mixed ...$args): string {
        if (empty($args)) {
            return $query;
        }

        $query = str_replace('%', '%%', $query);
        $query = preg_replace('/%%s/', '%s', $query);
        $query = preg_replace('/%%d/', '%d', $query);
        $query = preg_replace('/%%f/', '%f', $query);

        $safe_args = [];
        $i = 0;
        $prepared = preg_replace_callback('/%([sdf])/', function ($match) use (&$i, $args, &$safe_args) {
            $val = $args[$i] ?? '';
            $i++;

            switch ($match[1]) {
                case 'd':
                    return intval($val);
                case 'f':
                    return floatval($val);
                case 's':
                default:
                    return "'" . $this->escape($val) . "'";
            }
        }, $query);

        return $prepared;
    }

    /**
     * Escape a string for safe SQL usage.
     */
    public function escape(string $value): string {
        $this->connect();
        $quoted = $this->pdo->quote($value);
        return substr($quoted, 1, -1);
    }

    /**
     * Execute a raw SQL query.
     */
    public function query(string $sql): PDOStatement|false {
        $this->connect();
        $this->last_query = $sql;
        $this->last_error = null;

        try {
            $stmt = $this->pdo->query($sql);
            $this->rows_affected = $stmt->rowCount();
            $lid = $this->pdo->lastInsertId();
            $this->insert_id = $lid ? (int) $lid : null;
            return $stmt;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            if (defined('CR_DEBUG') && CR_DEBUG) {
                error_log("CR_Database error: {$this->last_error} | Query: {$sql}");
            }
            return false;
        }
    }

    /**
     * Get multiple rows as array of objects.
     */
    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $stmt = $this->query($sql);
        if ($stmt === false) {
            return [];
        }

        $mode = match ($output) {
            'ARRAY_A' => PDO::FETCH_ASSOC,
            'ARRAY_N' => PDO::FETCH_NUM,
            default   => PDO::FETCH_OBJ,
        };

        $results = $stmt->fetchAll($mode);
        $this->num_rows = count($results);
        return $results;
    }

    /**
     * Get a single row.
     */
    public function get_row(string $sql, string $output = 'OBJECT'): mixed {
        $results = $this->get_results($sql, $output);
        return $results[0] ?? null;
    }

    /**
     * Get a single column from results.
     */
    public function get_col(string $sql, int $column_offset = 0): array {
        $stmt = $this->query($sql);
        if ($stmt === false) {
            return [];
        }

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $results[] = $row[$column_offset] ?? null;
        }
        $this->num_rows = count($results);
        return $results;
    }

    /**
     * Get a single value.
     */
    public function get_var(string $sql, int $col = 0, int $row = 0): mixed {
        $results = $this->get_results($sql, 'ARRAY_N');
        return $results[$row][$col] ?? null;
    }

    /**
     * Insert a row into a table.
     */
    public function insert(string $table, array $data, array $format = []): int|false {
        $this->connect();

        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = [];

        foreach ($values as $i => $val) {
            $type = $format[$i] ?? $this->guess_format($val);
            $placeholders[] = $type;
        }

        $sql = sprintf(
            "INSERT INTO `%s` (`%s`) VALUES (%s)",
            $table,
            implode('`, `', $columns),
            implode(', ', array_map(fn($v, $p) => $this->format_value($v, $p), $values, $placeholders))
        );

        $result = $this->query($sql);
        if ($result === false) return false;
        return $this->insert_id ?? (int) $this->pdo->lastInsertId();
    }

    /**
     * Update rows in a table.
     */
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []): int|false {
        $this->connect();

        $set_parts = [];
        $i = 0;
        foreach ($data as $col => $val) {
            $type = $format[$i] ?? $this->guess_format($val);
            $set_parts[] = "`{$col}` = " . $this->format_value($val, $type);
            $i++;
        }

        $where_parts = [];
        $j = 0;
        foreach ($where as $col => $val) {
            $type = $where_format[$j] ?? $this->guess_format($val);
            $where_parts[] = "`{$col}` = " . $this->format_value($val, $type);
            $j++;
        }

        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE %s",
            $table,
            implode(', ', $set_parts),
            implode(' AND ', $where_parts)
        );

        $result = $this->query($sql);
        return $result !== false ? $this->rows_affected : false;
    }

    /**
     * Delete rows from a table.
     */
    public function delete(string $table, array $where, array $where_format = []): int|false {
        $this->connect();

        $where_parts = [];
        $i = 0;
        foreach ($where as $col => $val) {
            $type = $where_format[$i] ?? $this->guess_format($val);
            $where_parts[] = "`{$col}` = " . $this->format_value($val, $type);
            $i++;
        }

        $sql = sprintf(
            "DELETE FROM `%s` WHERE %s",
            $table,
            implode(' AND ', $where_parts)
        );

        $result = $this->query($sql);
        return $result !== false ? $this->rows_affected : false;
    }

    /**
     * Get PDO instance for advanced operations.
     */
    public function pdo(): PDO {
        $this->connect();
        return $this->pdo;
    }

    private function guess_format(mixed $value): string {
        if (is_int($value)) return '%d';
        if (is_float($value)) return '%f';
        return '%s';
    }

    private function format_value(mixed $value, string $format): string {
        if ($value === null) {
            return 'NULL';
        }
        return match ($format) {
            '%d' => (string) intval($value),
            '%f' => (string) floatval($value),
            default => "'" . $this->escape((string) $value) . "'",
        };
    }
}

/**
 * Global database accessor function.
 */
function cr_db(): CR_Database {
    return CR_Database::instance();
}
