<?php

function test_hooks(): void {
    TestCase::suite('Hooks System');
    test_reset_globals();

    // add_filter registers callback
    $result = add_filter('test_filter', 'strtoupper');
    TestCase::assertTrue($result, 'add_filter returns true');

    // add_action registers callback
    $result = add_action('test_action', function () {});
    TestCase::assertTrue($result, 'add_action returns true');

    // apply_filters passes value through callbacks
    test_reset_globals();
    add_filter('greet', function ($val) { return $val . ' World'; });
    $result = apply_filters('greet', 'Hello');
    TestCase::assertEqual('Hello World', $result, 'apply_filters passes value through callback');

    // apply_filters with multiple chained filters
    test_reset_globals();
    add_filter('chain', function ($v) { return $v . 'A'; });
    add_filter('chain', function ($v) { return $v . 'B'; });
    add_filter('chain', function ($v) { return $v . 'C'; });
    $result = apply_filters('chain', '');
    TestCase::assertEqual('ABC', $result, 'apply_filters chains multiple filters');

    // do_action executes callbacks (side effects)
    test_reset_globals();
    $counter = 0;
    add_action('count_action', function () use (&$counter) { $counter++; });
    do_action('count_action');
    TestCase::assertEqual(1, $counter, 'do_action executes callback');

    // Priority: lower number runs first
    test_reset_globals();
    $order = [];
    add_action('priority_test', function () use (&$order) { $order[] = 'B'; }, 10);
    add_action('priority_test', function () use (&$order) { $order[] = 'A'; }, 5);
    add_action('priority_test', function () use (&$order) { $order[] = 'C'; }, 15);
    do_action('priority_test');
    TestCase::assertEqual(['A', 'B', 'C'], $order, 'Priority: lower number runs first');

    // Same priority: insertion order
    test_reset_globals();
    $order = [];
    add_action('same_prio', function () use (&$order) { $order[] = 'first'; }, 10);
    add_action('same_prio', function () use (&$order) { $order[] = 'second'; }, 10);
    do_action('same_prio');
    TestCase::assertEqual(['first', 'second'], $order, 'Same priority: insertion order preserved');

    // remove_filter removes callback
    test_reset_globals();
    $fn = function ($v) { return $v . '!'; };
    add_filter('removable', $fn);
    remove_filter('removable', $fn, 10);
    $result = apply_filters('removable', 'Hello');
    TestCase::assertEqual('Hello', $result, 'remove_filter removes callback');

    // remove_action removes callback
    test_reset_globals();
    $called = false;
    $fn = function () use (&$called) { $called = true; };
    add_action('removable_action', $fn);
    remove_action('removable_action', $fn, 10);
    do_action('removable_action');
    TestCase::assertFalse($called, 'remove_action removes callback');

    // has_filter detects registered callback
    test_reset_globals();
    $fn = function ($v) { return $v; };
    add_filter('has_test', $fn);
    $result = has_filter('has_test', $fn);
    TestCase::assertNotEqual(false, $result, 'has_filter detects registered callback');

    // has_filter returns false if not registered
    test_reset_globals();
    $result = has_filter('nonexistent', function () {});
    TestCase::assertFalse($result, 'has_filter returns false if not registered');

    // has_action detects registered callback
    test_reset_globals();
    $fn = function () {};
    add_action('has_action_test', $fn);
    $result = has_action('has_action_test', $fn);
    TestCase::assertNotEqual(false, $result, 'has_action detects registered callback');

    // did_action counts executions
    test_reset_globals();
    do_action('counted');
    do_action('counted');
    do_action('counted');
    TestCase::assertEqual(3, did_action('counted'), 'did_action counts executions');

    // doing_action returns true during execution
    test_reset_globals();
    $was_doing = false;
    add_action('check_doing', function () use (&$was_doing) {
        $was_doing = doing_action('check_doing');
    });
    do_action('check_doing');
    TestCase::assertTrue($was_doing, 'doing_action returns true during execution');

    // current_filter returns current hook name
    test_reset_globals();
    $captured = '';
    add_action('capture_name', function () use (&$captured) {
        $captured = current_filter();
    });
    do_action('capture_name');
    TestCase::assertEqual('capture_name', $captured, 'current_filter returns current hook name');

    // apply_filters with 0 accepted_args
    test_reset_globals();
    add_filter('zero_args', function () { return 'overridden'; }, 10, 0);
    $result = apply_filters('zero_args', 'original');
    TestCase::assertEqual('overridden', $result, 'apply_filters with 0 accepted_args');

    // apply_filters with multiple args
    test_reset_globals();
    add_filter('multi_args', function ($val, $a, $b) { return $val . $a . $b; }, 10, 3);
    $result = apply_filters('multi_args', 'X', 'Y', 'Z');
    TestCase::assertEqual('XYZ', $result, 'apply_filters with multiple args');

    // Filter modifies and returns value
    test_reset_globals();
    add_filter('upper', 'strtoupper');
    TestCase::assertEqual('HELLO', apply_filters('upper', 'hello'), 'Filter modifies and returns value');

    // Action with multiple arguments
    test_reset_globals();
    $received = [];
    add_action('multi_arg_action', function ($a, $b, $c) use (&$received) {
        $received = [$a, $b, $c];
    }, 10, 3);
    do_action('multi_arg_action', 'one', 'two', 'three');
    TestCase::assertEqual(['one', 'two', 'three'], $received, 'Action receives multiple arguments');

    // Recursive hook (hook within hook)
    test_reset_globals();
    $log = [];
    add_action('recursive_outer', function () use (&$log) {
        $log[] = 'outer_start';
        do_action('recursive_inner');
        $log[] = 'outer_end';
    });
    add_action('recursive_inner', function () use (&$log) {
        $log[] = 'inner';
    });
    do_action('recursive_outer');
    TestCase::assertEqual(['outer_start', 'inner', 'outer_end'], $log, 'Recursive hooks work correctly');

    // remove_filter with wrong priority does nothing
    test_reset_globals();
    $fn = function ($v) { return $v . '!'; };
    add_filter('wrong_prio', $fn, 10);
    remove_filter('wrong_prio', $fn, 20); // wrong priority
    $result = apply_filters('wrong_prio', 'Hi');
    TestCase::assertEqual('Hi!', $result, 'remove_filter with wrong priority does not remove');

    // Class method callback
    test_reset_globals();
    $obj = new class {
        public function modify($v) { return $v . '_modified'; }
    };
    add_filter('class_method', [$obj, 'modify']);
    TestCase::assertEqual('test_modified', apply_filters('class_method', 'test'), 'Class method callback works');

    // Static class method callback
    test_reset_globals();
    // Use a named function instead since anonymous classes can't be static-called easily
    add_filter('static_test', 'strtolower');
    TestCase::assertEqual('hello', apply_filters('static_test', 'HELLO'), 'Named function callback works');

    // Unregistered hook returns value unchanged
    test_reset_globals();
    TestCase::assertEqual('unchanged', apply_filters('no_such_hook', 'unchanged'), 'Unregistered hook returns value unchanged');
}
