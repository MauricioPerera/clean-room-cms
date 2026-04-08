<?php

function test_user(): void {
    TestCase::suite('User System');
    test_reset_globals();

    // cr_register_default_roles
    cr_register_default_roles();
    global $cr_roles;
    TestCase::assertCount(5, $cr_roles, 'cr_register_default_roles registers 5 roles');
    TestCase::assertTrue(isset($cr_roles['administrator']), 'administrator role exists');
    TestCase::assertTrue(isset($cr_roles['editor']), 'editor role exists');
    TestCase::assertTrue(isset($cr_roles['author']), 'author role exists');
    TestCase::assertTrue(isset($cr_roles['contributor']), 'contributor role exists');
    TestCase::assertTrue(isset($cr_roles['subscriber']), 'subscriber role exists');

    // add_role
    add_role('moderator', 'Moderator', ['moderate_comments' => true, 'read' => true]);
    TestCase::assertNotNull(get_role('moderator'), 'add_role adds custom role');

    // get_role
    $role = get_role('administrator');
    TestCase::assertNotNull($role, 'get_role returns role');
    TestCase::assertEqual('Administrator', $role['name'], 'Role has correct name');

    // remove_role
    remove_role('moderator');
    TestCase::assertNull(get_role('moderator'), 'remove_role removes role');

    // cr_create_user
    $user_id = cr_create_user('testuser', 'pass123', 'testuser@test.com', [
        'display_name' => 'Test User',
        'role' => 'editor',
    ]);
    TestCase::assertNotEqual(false, $user_id, 'cr_create_user creates user');
    TestCase::assertGreaterThan(0, $user_id, 'User ID is positive');

    // cr_create_user rejects duplicates
    $dup = cr_create_user('testuser', 'pass456', 'testuser@test.com');
    TestCase::assertFalse($dup, 'cr_create_user rejects duplicate username/email');

    // get_user_by id
    $user = get_user_by('id', $user_id);
    TestCase::assertNotNull($user, 'get_user_by id returns user');
    TestCase::assertEqual('Test User', $user->display_name, 'User has correct display_name');

    // get_user_by login
    $user = get_user_by('login', 'testuser');
    TestCase::assertNotNull($user, 'get_user_by login returns user');

    // get_user_by email
    $user = get_user_by('email', 'testuser@test.com');
    TestCase::assertNotNull($user, 'get_user_by email returns user');

    // cr_authenticate validates correct credentials
    $auth = cr_authenticate('testuser', 'pass123');
    TestCase::assertEqual($user_id, $auth, 'cr_authenticate validates correct credentials');

    // cr_authenticate rejects wrong password
    $auth = cr_authenticate('testuser', 'wrongpass');
    TestCase::assertFalse($auth, 'cr_authenticate rejects wrong password');

    // cr_authenticate rejects nonexistent user
    $auth = cr_authenticate('nobody', 'pass');
    TestCase::assertFalse($auth, 'cr_authenticate rejects nonexistent user');

    // user_can checks capability by role
    TestCase::assertTrue(user_can($user_id, 'edit_posts'), 'user_can: editor can edit_posts');
    TestCase::assertTrue(user_can($user_id, 'moderate_comments'), 'user_can: editor can moderate_comments');

    // user_can returns false for unpermitted capability
    TestCase::assertFalse(user_can($user_id, 'manage_options'), 'user_can: editor cannot manage_options');

    // admin user can manage_options
    TestCase::assertTrue(user_can(1, 'manage_options'), 'user_can: admin can manage_options');

    // cr_create_nonce
    global $cr_current_user;
    $cr_current_user = get_userdata(1); // Set as admin for nonce
    $nonce = cr_create_nonce('test_action');
    TestCase::assertIsString($nonce, 'cr_create_nonce returns string');
    TestCase::assertNotEmpty($nonce, 'cr_create_nonce returns non-empty');

    // cr_verify_nonce validates correct nonce
    TestCase::assertTrue(cr_verify_nonce($nonce, 'test_action'), 'cr_verify_nonce validates correct nonce');

    // cr_verify_nonce rejects incorrect nonce
    TestCase::assertFalse(cr_verify_nonce('badnonce', 'test_action'), 'cr_verify_nonce rejects incorrect nonce');

    // cr_verify_nonce rejects wrong action
    TestCase::assertFalse(cr_verify_nonce($nonce, 'different_action'), 'cr_verify_nonce rejects wrong action');

    // cr_role_to_level
    TestCase::assertEqual(10, cr_role_to_level('administrator'), 'admin level is 10');
    TestCase::assertEqual(7, cr_role_to_level('editor'), 'editor level is 7');
    TestCase::assertEqual(2, cr_role_to_level('author'), 'author level is 2');
    TestCase::assertEqual(1, cr_role_to_level('contributor'), 'contributor level is 1');
    TestCase::assertEqual(0, cr_role_to_level('subscriber'), 'subscriber level is 0');

    // Cleanup
    $cr_current_user = null;
    $db = cr_db();
    $db->delete($db->prefix . 'users', ['ID' => $user_id]);
    $db->query($db->prepare("DELETE FROM `{$db->prefix}usermeta` WHERE user_id = %d", $user_id));
}
