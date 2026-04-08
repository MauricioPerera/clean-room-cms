<?php

function test_sandbox(): void {
    TestCase::suite('Plugin Sandbox');
    CR_Sandbox::reset();

    // Register plugin with manifest
    CR_Sandbox::register_plugin('my-plugin', [
        'name' => 'My Plugin',
        'permissions' => ['db:read', 'options:read', 'options:write', 'hooks:core', 'content:filter'],
    ]);

    $manifest = CR_Sandbox::get_manifest('my-plugin');
    TestCase::assertNotEmpty($manifest, 'register_plugin stores manifest');
    TestCase::assertEqual('My Plugin', $manifest['name'], 'Manifest has correct name');

    // No permissions granted yet
    $perms = CR_Sandbox::get_permissions('my-plugin');
    TestCase::assertEmpty($perms, 'No permissions granted by default');

    // Plugin has pending permissions
    TestCase::assertTrue(CR_Sandbox::has_pending('my-plugin'), 'has_pending true when ungranted');

    // Grant permissions
    CR_Sandbox::grant_permissions('my-plugin', ['db:read', 'options:read', 'hooks:core']);
    $perms = CR_Sandbox::get_permissions('my-plugin');
    TestCase::assertCount(3, $perms, 'grant_permissions grants 3 permissions');
    TestCase::assertTrue(in_array('db:read', $perms), 'db:read granted');
    TestCase::assertTrue(in_array('options:read', $perms), 'options:read granted');

    // Can't grant permissions not in manifest
    CR_Sandbox::grant_permissions('my-plugin', ['exec:shell', 'db:read']);
    $perms = CR_Sandbox::get_permissions('my-plugin');
    TestCase::assertFalse(in_array('exec:shell', $perms), 'exec:shell NOT granted (not in manifest)');

    // core context = always allowed
    TestCase::assertTrue(CR_Sandbox::can('db:write'), 'Core context always allowed');

    // Enter plugin context
    CR_Sandbox::enter_context('my-plugin');
    TestCase::assertEqual('my-plugin', CR_Sandbox::current_plugin(), 'current_plugin returns correct slug');

    // Allowed permission
    TestCase::assertTrue(CR_Sandbox::can('db:read'), 'Granted permission allowed');

    // Denied permission
    TestCase::assertFalse(CR_Sandbox::can('db:write'), 'Ungranted permission denied');
    TestCase::assertFalse(CR_Sandbox::can('http:outbound'), 'Ungranted permission denied');

    // Violation recorded
    $violations = CR_Sandbox::get_violations();
    TestCase::assertGreaterThan(0, count($violations), 'Violations recorded');
    TestCase::assertEqual('my-plugin', $violations[0]['plugin'], 'Violation has correct plugin');
    TestCase::assertEqual('db:write', $violations[0]['permission'], 'Violation has correct permission');

    // Enforce throws exception
    $thrown = false;
    try {
        CR_Sandbox::enforce('exec:shell');
    } catch (CR_Sandbox_Exception $e) {
        $thrown = true;
        TestCase::assertContains('my-plugin', $e->getMessage(), 'Exception mentions plugin');
        TestCase::assertContains('exec:shell', $e->getMessage(), 'Exception mentions permission');
    }
    TestCase::assertTrue($thrown, 'enforce throws CR_Sandbox_Exception');

    // Exit context
    CR_Sandbox::exit_context();
    TestCase::assertNull(CR_Sandbox::current_plugin(), 'current_plugin null after exit');

    // Back to core context = allowed
    TestCase::assertTrue(CR_Sandbox::can('exec:shell'), 'Core context allowed after exit');

    // get_all_plugins
    $all = CR_Sandbox::get_all_plugins();
    TestCase::assertTrue(isset($all['my-plugin']), 'get_all_plugins includes my-plugin');
    TestCase::assertNotEmpty($all['my-plugin']['pending'], 'Pending permissions listed');

    // Revoke permissions
    CR_Sandbox::revoke_permissions('my-plugin');
    TestCase::assertEmpty(CR_Sandbox::get_permissions('my-plugin'), 'revoke clears all permissions');

    // known_permissions returns list
    $known = CR_Sandbox::known_permissions();
    TestCase::assertGreaterThan(10, count($known), 'known_permissions returns comprehensive list');
    TestCase::assertTrue(in_array('db:read', $known), 'known_permissions includes db:read');
    TestCase::assertTrue(in_array('exec:shell', $known), 'known_permissions includes exec:shell');

    CR_Sandbox::reset();
}
