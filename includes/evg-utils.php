<?php
if (!defined('ABSPATH')) { exit; }
function evg__endpoint_key_from_url($url){
    $p = parse_url($url);
    $path = isset($p['path']) ? $p['path'] : '';
    if (preg_match('~\/api\/v2\.0\/(.+)$~i',$path,$m)){
        $tail = preg_replace('~[\/]+~','-', trim($m[1],'/'));
        $tail = preg_replace('~[^a-z0-9\-]~i','_', $tail);
        return strtolower($tail);
    }
    return 'root';
}
function evg_sanitize_table_prefix($prefix){
    $prefix=strtolower(preg_replace('/[^a-z0-9_]/','',(string)$prefix));
    return $prefix!==''?$prefix:'evg';
}
function evg_debug_log_api($context){
    $status_raw = $context['status'] ?? 'ERR';
    $status_int = is_numeric($status_raw) ? (int) $status_raw : null;
    $debug_enabled = (int) get_option('evg_debug',0) === 1;
    if (!$debug_enabled){
        if ($status_int !== null && $status_int < 400){
            return;
        }
        if ($status_int === null && !empty($context['note']) && strpos($context['note'],'wp_error') === false){
            return;
        }
    }
    $dir = trailingslashit(WP_CONTENT_DIR).'easyverein-debug';
    if (!file_exists($dir)){ wp_mkdir_p($dir); @file_put_contents($dir.'/.htaccess',"Require all denied\n"); @file_put_contents($dir.'/index.html',""); }
    $headers = (array)($context['headers']??[]);
    foreach($headers as $k=>$v){
        if (stripos($k,'authorization')!==false) $headers[$k] = 'Bearer XXXXX';
        if (stripos($k,'x-api-key')!==false)     $headers[$k] = 'XXXXX';
    }
    $status = $status_raw;
    $ep = evg__endpoint_key_from_url($context['url'] ?? '');
    $payload = [
        'ts'=>current_time('mysql'),
        'method'=>$context['method']??'GET',
        'url'=>$context['url']??'',
        'headers'=>$headers,
        'status'=>$status,
        'retry_after'=>$context['retry_after']??null,
        'body'=>$context['body']??null,
        'note'=>$context['note']??null,
    ];
    $file = sprintf('%s/%s-%s-%s-%s.json',$dir,gmdate('Ymd-His'),$status,$ep,substr(md5(($context['url']??'').wp_rand()),0,8));
    @file_put_contents($file, wp_json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
/**
 * Stellt sicher, dass der Token-Refresh innerhalb eines PHP-Requests
 * nur einmal ausgeführt wird (statische Flag), und sendet bei Erfolg
 * oder Fehler eine kurze Info-Mail.
 */
function evg_maybe_refresh_token_once(){
    static $already_tried = false;
    if ($already_tried) {
        return;
    }
    $already_tried = true;

    $new_token = evg_refresh_api_token();

    $report_email = trim((string) get_option('evg_sync_report_email',''));
    if ($report_email === '' || !is_email($report_email)){
        $report_email = get_option('admin_email');
    }
    if (!$report_email || !is_email($report_email)){
        return;
    }

    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) ?: 'WordPress';
    $formatted_time = date_i18n('d.m.Y H:i', current_time('timestamp'));

    if ($new_token) {
        $expires_in_days = 30;
        $next_refresh    = date_i18n('d.m.Y', current_time('timestamp') + 15 * DAY_IN_SECONDS);
        wp_mail(
            $report_email,
            sprintf('[%s] Easyverein API-Token automatisch erneuert', $site_name),
            implode("\n", [
                'Der Easyverein API-Token wurde automatisch erneuert.',
                '',
                'Zeitpunkt: ' . $formatted_time,
                'Nächste empfohlene Erneuerung: ' . $next_refresh . ' (in 15 Tagen)',
                'Token-Gültigkeit laut API: ' . $expires_in_days . ' Tage ab heute',
                '',
                'Es ist keine weitere Aktion erforderlich.',
            ])
        );
    } else {
        wp_mail(
            $report_email,
            sprintf('[%s] Easyverein API-Token-Erneuerung fehlgeschlagen', $site_name),
            implode("\n", [
                'Die automatische Erneuerung des Easyverein API-Tokens ist fehlgeschlagen.',
                '',
                'Zeitpunkt: ' . $formatted_time,
                '',
                'Bitte erneuere den API-Token manuell in den EasyVerein-Einstellungen',
                'und trage den neuen Token unter Einstellungen → Easyverein Go → API-Schlüssel ein.',
                '',
                'Protokolldateien: wp-content/easyverein-debug/',
            ])
        );
    }
}

/**
 * Versucht, den API-Token über den refresh-token-Endpoint zu erneuern.
 * Gibt den neuen Token-String zurück oder false bei Fehler.
 */
function evg_refresh_api_token(){
    $base = rtrim((string)get_option('evg_api_base',''),'/');
    if ($base === '') {
        return false;
    }
    $refresh_url = $base . '/api/v2.0/refresh-token';
    $key         = (string) get_option('evg_api_key','');
    $hdr         = (string) get_option('evg_auth_header','Authorization Bearer');
    $req_headers = ['Accept' => 'application/json'];
    if ($hdr === 'Authorization Bearer') {
        $req_headers['Authorization'] = 'Bearer ' . $key;
    } else {
        $req_headers['X-API-Key'] = $key;
    }

    $resp = wp_remote_get($refresh_url, [
        'headers'     => $req_headers,
        'timeout'     => 20,
        'redirection' => 3,
    ]);

    if (is_wp_error($resp)) {
        evg_debug_log_api([
            'method'  => 'GET',
            'url'     => $refresh_url,
            'headers' => $req_headers,
            'status'  => 'ERR',
            'body'    => $resp->get_error_message(),
            'note'    => 'token-refresh wp_error',
        ]);
        return false;
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);

    evg_debug_log_api([
        'method'  => 'GET',
        'url'     => $refresh_url,
        'headers' => $req_headers,
        'status'  => $code,
        'body'    => $body,
        'note'    => 'token-refresh',
    ]);

    if ($code < 200 || $code >= 300) {
        return false;
    }

    $data = json_decode($body, true);
    $new_token = null;
    foreach (['token', 'key', 'api_key', 'apiKey', 'access_token', 'accessToken'] as $field) {
        if (!empty($data[$field]) && is_string($data[$field])) {
            $new_token = $data[$field];
            break;
        }
    }
    // Fallback: wenn die Antwort ein einfacher String-Token ist
    if ($new_token === null && is_string($data) && strlen($data) > 10) {
        $new_token = $data;
    }

    if ($new_token === null || $new_token === '') {
        return false;
    }

    update_option('evg_api_key', sanitize_text_field($new_token));
    update_option('evg_api_key_refreshed_at', time());

    return $new_token;
}

/**
 * Prüft anhand der gespeicherten Option, ob der Token bald abläuft (> 25 Tage alt).
 * Gibt die Anzahl Tage seit letztem Refresh zurück, oder null wenn unbekannt.
 */
function evg_api_token_age_days(){
    $refreshed_at = (int) get_option('evg_api_key_refreshed_at', 0);
    if ($refreshed_at === 0) {
        return null;
    }
    return (int) floor((time() - $refreshed_at) / DAY_IN_SECONDS);
}

function evg_http_get($url,$headers=[],$args=[]){
    $max_attempts = max(1, (int) apply_filters('evg_http_max_attempts', 4, $url));
    $base_backoff = max(1, (int) apply_filters('evg_http_retry_base_delay', 5, $url));
    $args = array_merge([
        'headers'     => $headers,
        'timeout'     => apply_filters('evg_http_timeout', 30, $url),
        'redirection' => 3,
    ], $args);
    $attempt = 0;
    $resp = null;
    while ($attempt < $max_attempts){
        $attempt++;
        $resp = wp_remote_get($url, $args);
        $ctx = ['method'=>'GET','url'=>$url,'headers'=>$headers,'note'=>'attempt '.$attempt.'/'.$max_attempts];
        if (is_wp_error($resp)){
            $ctx['status']='ERR';
            $ctx['body']=$resp->get_error_message();
            $ctx['note'].=' wp_error';
            evg_debug_log_api($ctx);
            if ($attempt >= $max_attempts){
                return $resp;
            }
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            $retry= wp_remote_retrieve_header($resp,'retry-after');
            $ctx['status']=$code;
            $ctx['body']=$body;
            if($retry){
                $ctx['retry_after']=$retry;
            }
            evg_debug_log_api($ctx);
            // Automatische Token-Erneuerung wenn die API es signalisiert
            $token_refresh_needed = wp_remote_retrieve_header($resp,'tokenRefreshNeeded');
            if ($token_refresh_needed === 'true' || $token_refresh_needed === '1') {
                evg_maybe_refresh_token_once();
                // Request-Header für eventuell folgende Retries aktualisieren
                $new_key = (string) get_option('evg_api_key','');
                $hdr_style = (string) get_option('evg_auth_header','Authorization Bearer');
                if ($hdr_style === 'Authorization Bearer') {
                    $args['headers']['Authorization'] = 'Bearer ' . $new_key;
                } else {
                    $args['headers']['X-API-Key'] = $new_key;
                }
            }
            if ($code === 429 || $code >= 500){
                if ($attempt >= $max_attempts){
                    return $resp;
                }
            } else {
                return $resp;
            }
        }
        if ($attempt < $max_attempts){
            $sleep = isset($retry) && $retry !== null ? (int) $retry : ($base_backoff * $attempt);
            if ($sleep > 60){
                $sleep = 60;
            }
            if ($sleep > 0){
                if (function_exists('wp_sleep')){
                    wp_sleep($sleep);
                } else {
                    sleep($sleep);
                }
            }
        }
    }
    return $resp;
}
