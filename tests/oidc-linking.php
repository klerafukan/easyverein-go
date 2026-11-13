<?php
/** Run with php tests/oidc-linking.php. No WordPress database required. */
define('ABSPATH', __DIR__);
define('DB_NAME', 'fixture');

require __DIR__ . '/../includes/evg-utils.php';

$wpdb = new class {
    public $prefix = 'test_';
    public $users = 'test_users';
    public $members = [];
    public $last_error = "";

    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) {
        return strpos($sql, 'LOCK(') !== false ? 1 : ($this->members[0] ?? null);
    }
    public function get_col($sql) { return strpos($sql, $this->users) !== false ? array_keys($GLOBALS['accounts']) : $this->members; }
};
$accounts = [];
function sanitize_text_field($value) { return trim($value); }
function sanitize_email($value) { return trim($value); }
function get_users($args) {
    return array_keys(array_filter($GLOBALS['accounts'], fn($meta) => ($meta['evg_oidc_sub'] ?? '') === $args['meta_value']));
}
function get_user_by($field, $value) { return isset($GLOBALS['accounts'][7]) ? (object)['ID' => 7] : false; }
function get_userdata($id) { return (object)['ID' => $id]; }
function get_user_meta($id, $key, $single) { return $GLOBALS['accounts'][$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { $GLOBALS['accounts'][$id][$key] = $value; return true; }
function user_can($user, $cap) { return $GLOBALS['accounts'][$user->ID]['privileged'] ?? false; }
function wp_generate_password(...$args) { return 'fixture'; }
function wp_insert_user($args) { $GLOBALS['accounts'][7] = []; return 7; }
function is_wp_error($value) { return false; }
function check($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "OK: $label\n";
}
$profile = ['email' => 'shared@example.test', 'email_verified' => true];
$wpdb->members = ['101', '102'];
check(evg_find_or_provision_oidc_user('new', $profile) === null && !$accounts, 'Shared email cannot create a link');
$accounts = [7 => ['evg_oidc_sub' => 'known', 'evg_member_id' => '101']];
check(evg_find_or_provision_oidc_user('known', $profile) === 7, 'Existing subject still resolves');
$wpdb->members = ['101'];
check(evg_find_or_provision_oidc_user('different', $profile) === null && $accounts[7]['evg_oidc_sub'] === 'known', 'Different subject cannot replace an existing link');
$accounts = [7 => ['evg_member_id' => '102']];
check(evg_find_or_provision_oidc_user('new', $profile) === null && $accounts[7]['evg_member_id'] === '102', 'Conflicting member link is preserved');
$accounts = [7 => ['evg_member_id' => '101', 'privileged' => true]];
check(evg_find_or_provision_oidc_user('new', $profile) === null, 'Privileged account requires manual linking');
$accounts = [];
check(evg_find_or_provision_oidc_user('new', $profile) === 7 && $accounts[7]['evg_member_id'] === '101', 'Unique email provisions the correct member');
$accounts = [7 => [], 8 => []];
check(evg_find_or_provision_oidc_user('new', $profile) === null, 'Duplicate WordPress email requires manual linking');
$accounts = [7 => ['evg_oidc_sub' => 'duplicate'], 8 => ['evg_oidc_sub' => 'duplicate']];
check(evg_find_or_provision_oidc_user('duplicate', $profile) === null, 'Duplicate subject is ambiguous');
$accounts = [7 => ['evg_member_id' => '101']];
$wpdb->members = [];
check(evg_find_or_provision_oidc_user('new', $profile) === null, 'Existing WordPress metadata cannot bypass membership lookup');
$wpdb->members = ['101'];
check(evg_find_or_provision_oidc_user('new', ['email'=>'shared@example.test','email_verified'=>false]) === null, 'Explicitly unverified email is rejected');
