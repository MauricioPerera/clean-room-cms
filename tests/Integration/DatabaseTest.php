<?php

function test_database(): void {
    TestCase::suite('Database Layer');

    $db = cr_db();

    // connect establishes connection
    $db->connect();
    TestCase::assertNotNull($db->pdo(), 'connect establishes PDO connection');

    // prepare escapes strings
    $sql = $db->prepare("SELECT * FROM x WHERE name = %s", "O'Brien");
    TestCase::assertContains("O\\'Brien", $sql, 'prepare escapes strings');

    // prepare formats integers
    $sql = $db->prepare("SELECT * FROM x WHERE id = %d", '42abc');
    TestCase::assertContains('42', $sql, 'prepare formats integers');
    TestCase::assertFalse(str_contains($sql, 'abc'), 'prepare strips non-numeric from %d');

    // escape prevents SQL injection
    $escaped = $db->escape("'; DROP TABLE users; --");
    TestCase::assertNotEqual("'; DROP TABLE users; --", $escaped, 'escape modifies dangerous input');
    TestCase::assertContains("\\'", $escaped, 'escape escapes single quotes');

    // Create a test table for CRUD tests
    $db->query("CREATE TABLE IF NOT EXISTS `{$db->prefix}test_crud` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), value TEXT) ENGINE=InnoDB");

    // insert returns ID
    $id = $db->insert($db->prefix . 'test_crud', ['name' => 'foo', 'value' => 'bar']);
    TestCase::assertNotNull($id, 'insert returns ID');
    TestCase::assertGreaterThan(0, $id, 'insert ID is positive');

    // get_row returns object
    $row = $db->get_row("SELECT * FROM `{$db->prefix}test_crud` WHERE id = {$id}");
    TestCase::assertIsObject($row, 'get_row returns object');
    TestCase::assertEqual('foo', $row->name, 'get_row returns correct data');

    // get_results returns array
    $db->insert($db->prefix . 'test_crud', ['name' => 'baz', 'value' => 'qux']);
    $results = $db->get_results("SELECT * FROM `{$db->prefix}test_crud`");
    TestCase::assertIsArray($results, 'get_results returns array');
    TestCase::assertGreaterThan(1, count($results), 'get_results returns multiple rows');

    // get_var returns scalar value
    $count = $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}test_crud`");
    TestCase::assertGreaterThan(0, (int) $count, 'get_var returns scalar value');

    // get_col returns array of single column
    $names = $db->get_col("SELECT name FROM `{$db->prefix}test_crud`");
    TestCase::assertIsArray($names, 'get_col returns array');
    TestCase::assertContains('foo', implode(',', $names), 'get_col contains expected value');

    // update modifies rows
    $affected = $db->update($db->prefix . 'test_crud', ['value' => 'updated'], ['name' => 'foo']);
    TestCase::assertGreaterThan(0, $affected, 'update modifies rows');
    $row = $db->get_row("SELECT * FROM `{$db->prefix}test_crud` WHERE name = 'foo'");
    TestCase::assertEqual('updated', $row->value, 'update changes data correctly');

    // delete eliminates rows
    $affected = $db->delete($db->prefix . 'test_crud', ['name' => 'baz']);
    TestCase::assertGreaterThan(0, $affected, 'delete eliminates rows');

    // insert with NULL
    $id = $db->insert($db->prefix . 'test_crud', ['name' => 'nulltest', 'value' => null]);
    TestCase::assertNotNull($id, 'insert with NULL value works');

    // query with invalid SQL returns false
    $result = $db->query("SELECT * FROM nonexistent_table_xyz");
    TestCase::assertFalse($result, 'query with invalid SQL returns false');

    // last_error is populated on error
    TestCase::assertNotEmpty($db->last_error, 'last_error is populated on error');

    // rows_affected tracks count
    $db->query("DELETE FROM `{$db->prefix}test_crud` WHERE name = 'foo'");
    TestCase::assertGreaterThan(0, $db->rows_affected, 'rows_affected tracks count');

    // Cleanup
    $db->query("DROP TABLE IF EXISTS `{$db->prefix}test_crud`");
}
