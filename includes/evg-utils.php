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
function evg_debug_log_api($context){
    if (!get_option('evg_debug',0)) return;
    $dir = trailingslashit(WP_CONTENT_DIR).'easyverein-debug';
    if (!file_exists($dir)){ wp_mkdir_p($dir); @file_put_contents($dir.'/.htaccess',"Require all denied\n"); @file_put_contents($dir.'/index.html',""); }
    $headers = (array)($context['headers']??[]);
    foreach($headers as $k=>$v){
        if (stripos($k,'authorization')!==false) $headers[$k] = 'Bearer XXXXX';
        if (stripos($k,'x-api-key')!==false)     $headers[$k] = 'XXXXX';
    }
    $status = $context['status'] ?? 'ERR';
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
