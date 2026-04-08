<?php

function test_serialization(): void {
    TestCase::suite('Serialization Helpers');

    // maybe_serialize string returns string
    TestCase::assertEqual('hello', maybe_serialize('hello'), 'maybe_serialize string returns string');

    // maybe_serialize int returns string of int
    TestCase::assertEqual('42', maybe_serialize(42), 'maybe_serialize int returns string');

    // maybe_serialize array returns serialized
    $arr = ['a' => 1, 'b' => 2];
    $result = maybe_serialize($arr);
    TestCase::assertContains('a:2', $result, 'maybe_serialize array returns serialized string');

    // maybe_serialize object returns serialized
    $obj = (object) ['foo' => 'bar'];
    $result = maybe_serialize($obj);
    TestCase::assertContains('foo', $result, 'maybe_serialize object returns serialized string');

    // maybe_unserialize normal string returns string
    TestCase::assertEqual('hello world', maybe_unserialize('hello world'), 'maybe_unserialize normal string returns string');

    // maybe_unserialize serialized string returns array
    $serialized = serialize(['x' => 10]);
    $result = maybe_unserialize($serialized);
    TestCase::assertIsArray($result, 'maybe_unserialize serialized string returns array');
    TestCase::assertEqual(10, $result['x'], 'maybe_unserialize preserves array values');

    // is_serialized detects serialized array
    TestCase::assertTrue(is_serialized(serialize([1, 2, 3])), 'is_serialized detects serialized array');

    // is_serialized detects serialized string
    TestCase::assertTrue(is_serialized(serialize('hello')), 'is_serialized detects serialized string');

    // is_serialized rejects normal string
    TestCase::assertFalse(is_serialized('just a string'), 'is_serialized rejects normal string');

    // is_serialized rejects non-strings
    TestCase::assertFalse(is_serialized(42), 'is_serialized rejects integer');
    TestCase::assertFalse(is_serialized(null), 'is_serialized rejects null');
    TestCase::assertFalse(is_serialized(false), 'is_serialized rejects false');

    // Roundtrip: data survives serialize->unserialize
    $original = ['nested' => ['data' => [1, 2, 3]], 'flag' => true];
    $roundtrip = maybe_unserialize(maybe_serialize($original));
    TestCase::assertEqual($original, $roundtrip, 'Roundtrip: serialize->unserialize preserves data');
}
