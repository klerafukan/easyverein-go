<?php
/**
 * Debug script to diagnose sync issues
 * Place this file in your WordPress root and access via: yoursite.com/debug-sync.php
 */

// Basic WordPress environment
if (!defined('ABSPATH')) {
    require_once('wp-config.php');
    require_once('wp-load.php');
}

// Security check
if (!current_user_can('manage_options')) {
    die('Access denied');
}

echo "<h1>EasyVerein Sync Debug</h1>";

// Check if tables exist
global $wpdb;
$nightly_prefix_option = get_option('evg_nightly_sync_table_prefix', 'evg_nightly');
$nightly_prefix = class_exists('EVG_Sync')
    ? EVG_Sync::sanitize_table_prefix($nightly_prefix_option)
    : 'evg_nightly';
$table_prefixes = [
    'Primär' => 'evg'
];
if ($nightly_prefix !== 'evg') {
    $table_prefixes['Nächtlich'] = $nightly_prefix;
}

echo "<h2>1. Database Tables Check</h2>";
foreach ($table_prefixes as $label => $prefix) {
    echo "<h3>$label ($prefix)</h3>";
    $tables = [
        $wpdb->prefix . $prefix . '_groups',
        $wpdb->prefix . $prefix . '_members',
        $wpdb->prefix . $prefix . '_member_groups',
        $wpdb->prefix . $prefix . '_custom_fields',
        $wpdb->prefix . $prefix . '_member_custom_fields',
        $wpdb->prefix . $prefix . '_custom_field_values'
    ];
    foreach ($tables as $table) {
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        $count = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM $table") : 0;
        echo "<p><strong>$table:</strong> " . ($exists ? "✓ Exists ($count records)" : "✗ Missing") . "</p>";
    }
}

// Check current sync state
echo "<h2>2. Current Sync State</h2>";
$state_primary = get_option('evg_sync_job');
if ($state_primary) {
    echo "<h3>Primär (evg)</h3><pre>" . print_r($state_primary, true) . "</pre>";
} else {
    echo "<h3>Primär (evg)</h3><p>No active sync job</p>";
}
if ($nightly_prefix !== 'evg') {
    $nightly_state_key = 'evg_sync_job_' . $nightly_prefix;
    $state_nightly = get_option($nightly_state_key);
    if ($state_nightly) {
        echo "<h3>Nächtlich ($nightly_prefix)</h3><pre>" . print_r($state_nightly, true) . "</pre>";
    } else {
        echo "<h3>Nächtlich ($nightly_prefix)</h3><p>No active sync job</p>";
    }
}

// Check API configuration
echo "<h2>3. API Configuration</h2>";
$config = [
    'evg_api_base' => get_option('evg_api_base', ''),
    'evg_api_key' => get_option('evg_api_key', ''),
    'evg_auth_header' => get_option('evg_auth_header', ''),
    'evg_members_path' => get_option('evg_members_path', ''),
    'evg_contact_details_path' => get_option('evg_contact_details_path', ''),
    'evg_custom_fields_path' => get_option('evg_custom_fields_path', ''),
    'evg_member_custom_fields_path' => get_option('evg_member_custom_fields_path', ''),
    'evg_debug' => get_option('evg_debug', 0)
];

foreach ($config as $key => $value) {
    if (strpos($key, 'key') !== false) {
        $value = $value ? '***' . substr($value, -4) : 'Not set';
    }
    echo "<p><strong>$key:</strong> $value</p>";
}

// Test API connection
echo "<h2>4. API Connection Test</h2>";
if (class_exists('EVG_Sync')) {
    $sync = new EVG_Sync();
    
    // Test members endpoint
    $base = rtrim(get_option('evg_api_base', ''), '/');
    $members_path = get_option('evg_members_path', '/api/v2.0/member');
    $url = $base . $members_path;
    
    $headers = [];
    $key = get_option('evg_api_key', '');
    $auth_header = get_option('evg_auth_header', 'Authorization Bearer');
    
    if ($auth_header === 'Authorization Bearer') {
        $headers['Authorization'] = 'Bearer ' . $key;
    } else {
        $headers['X-API-Key'] = $key;
    }
    $headers['Accept'] = 'application/json';
    
    echo "<p><strong>Testing URL:</strong> $url</p>";
    echo "<p><strong>Headers:</strong> " . print_r($headers, true) . "</p>";
    
    if (function_exists('evg_http_get')) {
        $response = evg_http_get($url, $headers);
        
        if (is_wp_error($response)) {
            echo "<p style='color: red;'><strong>Error:</strong> " . $response->get_error_message() . "</p>";
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            echo "<p><strong>Response Code:</strong> $code</p>";
            echo "<p><strong>Response Body (first 500 chars):</strong></p>";
            echo "<pre>" . substr($body, 0, 500) . "...</pre>";
            
            if ($code === 200) {
                $data = json_decode($body, true);
                if (is_array($data)) {
                    $count = 0;
                    if (array_keys($data) === range(0, count($data)-1)) {
                        $count = count($data);
                    } else {
                        $results = $data['results'] ?? $data['data'] ?? $data['items'] ?? [];
                        $count = count($results);
                    }
                    echo "<p><strong>Members found:</strong> $count</p>";
                }
            }
        }
    } else {
        echo "<p style='color: red;'>evg_http_get function not found</p>";
    }
}

// Check debug logs
echo "<h2>5. Debug Logs</h2>";
$debug_dir = WP_CONTENT_DIR . '/easyverein-debug';
if (is_dir($debug_dir)) {
    $files = glob($debug_dir . '/*.json');
    if ($files) {
        echo "<p><strong>Debug files found:</strong> " . count($files) . "</p>";
        // Show last 3 files
        $recent_files = array_slice($files, -3);
        foreach ($recent_files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            echo "<h3>" . basename($file) . "</h3>";
            echo "<pre>" . print_r($data, true) . "</pre>";
        }
    } else {
        echo "<p>No debug files found</p>";
    }
} else {
    echo "<p>Debug directory not found: $debug_dir</p>";
}

// Manual sync test
echo "<h2>6. Manual Sync Test</h2>";
echo "<p><button onclick='startSync()'>Start 100 Member Sync Test</button></p>";
echo "<div id='sync-result'></div>";

?>
<script>
function startSync() {
    const resultDiv = document.getElementById('sync-result');
    resultDiv.innerHTML = '<p>Starting sync...</p>';
    
    // Use WordPress AJAX
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=evg_sync_start&cap=100&_wpnonce=<?php echo wp_create_nonce('evg_sync'); ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            resultDiv.innerHTML = '<p style="color: green;">Sync started successfully</p>';
            // Start polling for progress
            pollProgress();
        } else {
            resultDiv.innerHTML = '<p style="color: red;">Error: ' + (data.data?.message || 'Unknown error') + '</p>';
        }
    })
    .catch(error => {
        resultDiv.innerHTML = '<p style="color: red;">Network error: ' + error.message + '</p>';
    });
}

function pollProgress() {
    const resultDiv = document.getElementById('sync-result');
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=evg_sync_tick&_wpnonce=<?php echo wp_create_nonce('evg_sync'); ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const progress = data.data;
            resultDiv.innerHTML = '<p>Progress: ' + (progress.percent || 0) + '% - ' + (progress.label || 'Processing...') + '</p>';
            
            if (!progress.done) {
                setTimeout(pollProgress, 1000);
            } else {
                resultDiv.innerHTML += '<p style="color: green;">Sync completed: ' + (progress.summary || 'Done') + '</p>';
                // Refresh page to show updated counts
                setTimeout(() => location.reload(), 2000);
            }
        } else {
            resultDiv.innerHTML += '<p style="color: red;">Error: ' + (data.data?.message || 'Unknown error') + '</p>';
        }
    })
    .catch(error => {
        resultDiv.innerHTML += '<p style="color: red;">Polling error: ' + error.message + '</p>';
    });
}
</script>
