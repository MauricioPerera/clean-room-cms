<?php
/**
 * Clean Room CMS - Test Framework
 * Lightweight assertion library. No external dependencies.
 */

class TestCase {
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $total = 0;
    public static array $failures = [];
    private static string $current_suite = '';

    public static function suite(string $name): void {
        self::$current_suite = $name;
        echo "\n\033[1;36m=== {$name} ===\033[0m\n";
    }

    public static function assert(bool $condition, string $description): void {
        self::$total++;
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m {$description}\n";
        } else {
            self::$failed++;
            $msg = "[" . self::$current_suite . "] {$description}";
            self::$failures[] = $msg;
            echo "  \033[31m✗\033[0m {$description}\n";
        }
    }

    public static function assertEqual(mixed $expected, mixed $actual, string $description): void {
        $pass = $expected === $actual;
        if (!$pass) {
            $description .= " (expected: " . self::format($expected) . ", got: " . self::format($actual) . ")";
        }
        self::assert($pass, $description);
    }

    public static function assertNotEqual(mixed $expected, mixed $actual, string $description): void {
        self::assert($expected !== $actual, $description);
    }

    public static function assertTrue(mixed $value, string $description): void {
        self::assert($value === true, $description . (($value !== true) ? " (got: " . self::format($value) . ")" : ""));
    }

    public static function assertFalse(mixed $value, string $description): void {
        self::assert($value === false, $description . (($value !== false) ? " (got: " . self::format($value) . ")" : ""));
    }

    public static function assertNull(mixed $value, string $description): void {
        self::assert($value === null, $description);
    }

    public static function assertNotNull(mixed $value, string $description): void {
        self::assert($value !== null, $description);
    }

    public static function assertGreaterThan(int|float $expected, int|float $actual, string $description): void {
        self::assert($actual > $expected, $description . " (expected > {$expected}, got: {$actual})");
    }

    public static function assertContains(string $needle, string $haystack, string $description): void {
        self::assert(str_contains($haystack, $needle), $description);
    }

    public static function assertCount(int $expected, array|Countable $actual, string $description): void {
        $count = count($actual);
        self::assert($count === $expected, $description . " (expected {$expected}, got {$count})");
    }

    public static function assertNotEmpty(mixed $value, string $description): void {
        self::assert(!empty($value), $description);
    }

    public static function assertEmpty(mixed $value, string $description): void {
        self::assert(empty($value), $description);
    }

    public static function assertIsArray(mixed $value, string $description): void {
        self::assert(is_array($value), $description);
    }

    public static function assertIsObject(mixed $value, string $description): void {
        self::assert(is_object($value), $description);
    }

    public static function assertIsInt(mixed $value, string $description): void {
        self::assert(is_int($value), $description);
    }

    public static function assertIsString(mixed $value, string $description): void {
        self::assert(is_string($value), $description);
    }

    public static function assertInstanceOf(string $class, mixed $value, string $description): void {
        self::assert($value instanceof $class, $description);
    }

    public static function assertMatchesRegex(string $pattern, string $subject, string $description): void {
        self::assert((bool) preg_match($pattern, $subject), $description);
    }

    public static function summary(): void {
        echo "\n\033[1m" . str_repeat('=', 60) . "\033[0m\n";
        echo "\033[1mRESULTS: {$GLOBALS['_test_total_suites']} suites, " . self::$total . " tests\033[0m\n";
        echo "\033[32m  PASSED: " . self::$passed . "\033[0m\n";

        if (self::$failed > 0) {
            echo "\033[31m  FAILED: " . self::$failed . "\033[0m\n";
            echo "\n\033[31mFailures:\033[0m\n";
            foreach (self::$failures as $i => $f) {
                echo "  " . ($i + 1) . ". {$f}\n";
            }
        } else {
            echo "\n\033[1;32m  ALL TESTS PASSED!\033[0m\n";
        }
        echo "\n";
    }

    private static function format(mixed $value): string {
        if ($value === null) return 'null';
        if ($value === true) return 'true';
        if ($value === false) return 'false';
        if (is_array($value)) return 'array(' . count($value) . ')';
        if (is_object($value)) return get_class($value);
        if (is_string($value) && strlen($value) > 60) return '"' . substr($value, 0, 57) . '..."';
        return var_export($value, true);
    }
}
