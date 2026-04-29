<?php
if (!defined('ABSPATH')) { exit; }

class EVG_Sync {
    private $state_key='evg_sync_job';
    private $table_prefix='evg';

    public static function sanitize_table_prefix($prefix){
        return evg_sanitize_table_prefix($prefix);
    }

    public function __construct($table_prefix='evg'){
        $this->table_prefix=self::sanitize_table_prefix($table_prefix);
        $this->state_key=($this->table_prefix==='evg')
            ? 'evg_sync_job'
            : sprintf('evg_sync_job_%s',$this->table_prefix);
    }

    public function get_state_option_key(){
        return $this->state_key;
    }

    public function get_table_prefix(){
        return $this->table_prefix;
    }

    private function table($suffix){
        global $wpdb;
        return $wpdb->prefix.$this->table_prefix.'_'.$suffix;
    }

    private function ensure_custom_field_schema(){
        if (class_exists('EVG_Plugin')) {
            EVG_Plugin::ensure_schema_for_prefix($this->table_prefix);
            return;
        }

        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $prefix = self::sanitize_table_prefix($this->table_prefix);
        $base = $wpdb->prefix.$prefix.'_' ;
        $charset = $wpdb->get_charset_collate();
        $schemas = [
            "CREATE TABLE {$base}custom_fields (
                field_id varchar(191) NOT NULL,
                name varchar(255) DEFAULT '',
                settings_type varchar(32) DEFAULT '',
                kind varchar(32) DEFAULT '',
                member_show tinyint(1) DEFAULT 0,
                member_edit tinyint(1) DEFAULT 0,
                position int DEFAULT 0,
                collection varchar(191) DEFAULT '',
                updated_at datetime NULL,
                raw longtext,
                PRIMARY KEY  (field_id)
            ) {$charset};",
            "CREATE TABLE {$base}member_custom_fields (
                member_id varchar(191) NOT NULL,
                field_id varchar(191) NOT NULL,
                value_hash varchar(64) DEFAULT '',
                value_text longtext,
                updated_at datetime NULL,
                raw longtext,
                PRIMARY KEY  (member_id,field_id),
                KEY idx_field (field_id),
                KEY idx_value_hash (value_hash)
            ) {$charset};",
            "CREATE TABLE {$base}custom_field_values (
                field_id varchar(191) NOT NULL,
                value_hash varchar(64) NOT NULL,
                field_label varchar(255) DEFAULT '',
                value_label varchar(255) DEFAULT '',
                value_raw longtext,
                updated_at datetime NULL,
                PRIMARY KEY  (field_id, value_hash),
                KEY idx_field_label (field_id, value_label)
            ) {$charset};"
        ];
        foreach ($schemas as $sql){
            dbDelta($sql);
        }
    }

    private function is_indexed_array($value){
        if (!is_array($value)){
            return false;
        }
        if ($value === []){
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function extract_items_from_payload($payload){
        if ($this->is_indexed_array($payload)){
            return $payload;
        }
        if (!is_array($payload)){
            return [];
        }
        $candidates = ['results','data','items','entries','list','objects'];
        foreach ($candidates as $key){
            if (isset($payload[$key])){
                $candidate = $payload[$key];
                if ($this->is_indexed_array($candidate)){
                    return $candidate;
                }
                if (is_array($candidate)){
                    $nested = $this->extract_items_from_payload($candidate);
                    if (!empty($nested)){
                        return $nested;
                    }
                }
            }
        }
        return [];
    }

    private function extract_next_from_payload($payload){
        if (!is_array($payload)){
            return null;
        }
        $direct_keys = ['next','nextLink','next_page','next_page_url'];
        foreach ($direct_keys as $key){
            if (!empty($payload[$key])){
                if (is_string($payload[$key])){
                    return $payload[$key];
                }
                if (is_array($payload[$key]) && !empty($payload[$key]['href']) && is_string($payload[$key]['href'])){
                    return $payload[$key]['href'];
                }
            }
        }
        $nested_sources = ['links','pagination'];
        foreach ($nested_sources as $source){
            if (isset($payload[$source]) && is_array($payload[$source])){
                foreach ($direct_keys as $key){
                    if (!empty($payload[$source][$key])){
                        $candidate = $payload[$source][$key];
                        if (is_string($candidate)){
                            return $candidate;
                        }
                        if (is_array($candidate) && !empty($candidate['href']) && is_string($candidate['href'])){
                            return $candidate['href'];
                        }
                    }
                }
            }
        }
        return null;
    }

    private function flatten_custom_field_value($value){
        $result = [];
        $walker = function($item) use (&$result, &$walker){
            if (is_null($item)){
                return;
            }
            if (is_scalar($item)){
                $string = trim((string)$item);
                if ($string !== ''){
                    $result[] = $string;
                }
                return;
            }
            if (is_array($item)){
                foreach ($item as $sub){
                    $walker($sub);
                }
                return;
            }
            if (is_object($item)){
                foreach (get_object_vars($item) as $sub){
                    $walker($sub);
                }
            }
        };
        $walker($value);
        return $result;
    }

    private function normalize_custom_field_values(array $values){
        $normalized = [];
        foreach ($values as $value){
            $value = preg_replace('/\s+/u',' ', trim((string)$value));
            if ($value === ''){
                continue;
            }
            $normalized[] = $value;
        }
        $normalized = array_values(array_unique($normalized));
        if (count($normalized) > 1){
            if (function_exists('mb_strtolower')){
                usort($normalized, function($a,$b){
                    return strcasecmp($a, $b);
                });
            } else {
                sort($normalized, SORT_NATURAL | SORT_FLAG_CASE);
            }
        }
        return $normalized;
    }

    private function prepare_custom_field_value($field_id, $value_source){
        $raw_string = '';
        $value_candidates = [];

        if (is_array($value_source) || is_object($value_source)){
            $raw_string = wp_json_encode($value_source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $value_candidates = $this->flatten_custom_field_value($value_source);
        } else {
            $raw_string = trim((string)$value_source);
            if ($raw_string !== ''){
                $decoded = json_decode($raw_string, true);
                if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded)) ){
                    $value_candidates = $this->flatten_custom_field_value($decoded);
                } else {
                    $value_candidates = [$raw_string];
                }
            }
        }

        $normalized_values = $this->normalize_custom_field_values($value_candidates);
        if (empty($normalized_values)){
            $fallback = preg_replace('/\s+/u',' ', trim($raw_string));
            if ($fallback === ''){
                return null;
            }
            $normalized_values = [$fallback];
        }

        $label = implode(', ', $normalized_values);
        $hash_input = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        $hash = md5($field_id.'|'.$hash_input);

        return [
            'label' => $label,
            'hash'  => $hash,
            'raw'   => $raw_string
        ];
    }

    private function headers(){
        $key=get_option('evg_api_key',''); $hdr=get_option('evg_auth_header','Authorization Bearer');
        $h=['Accept'=>'application/json']; if($hdr==='Authorization Bearer') $h['Authorization']='Bearer '.$key; else $h['X-API-Key']=$key; return $h;
    }
    private function base(){ return rtrim((string)get_option('evg_api_base',''),'/'); }
    private function next_url($next){
        if(!$next) return null;
        if(preg_match('#^https?://#i',$next)) return $next;
        $b=$this->base(); return rtrim($b,'/').(strpos($next,'/')===0?$next:'/'.$next);
    }
    private function normalize_endpoint($path){
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = $this->base();
        if ($base === '') {
            return $path;
        }
        if ($path !== '' && $path[0] !== '/') {
            $path = '/'.$path;
        }
        return $base.$path;
    }
    private function calls_per_tick(){ return max(1,(int)get_option('evg_sync_calls_per_tick',5)); }
    private function pages_max(){ return max(1,(int)get_option('evg_sync_next_pages_max',100)); }
    private function rate_sleep(){ $r=max(1,(int)get_option('evg_sync_rate_per_sec',5)); usleep((int)(1000000 / max(1,$r))); }
    private function get_state(){ $s=get_option($this->state_key); return is_array($s)?$s:[]; }
    private function put_state($s){ update_option($this->state_key,$s,false); }
    private function clear_state(){ delete_option($this->state_key); }

    public function job_start($cap=0){
        global $wpdb;
        $this->ensure_custom_field_schema();
        $wpdb->query('TRUNCATE TABLE '.$this->table('member_groups'));
        $wpdb->query('TRUNCATE TABLE '.$this->table('members'));
        $wpdb->query('TRUNCATE TABLE '.$this->table('groups'));
        $wpdb->query('TRUNCATE TABLE '.$this->table('custom_fields'));
        $wpdb->query('TRUNCATE TABLE '.$this->table('member_custom_fields'));
        $wpdb->query('TRUNCATE TABLE '.$this->table('custom_field_values'));
        $state=[
            'phase'=>'groups',
            'pages'=>['groups'=>0,'custom_fields'=>0,'members_list'=>0],
            'counts'=>[
                'groups'=>0,
                'custom_fields'=>0,
                'custom_field_values'=>0,
                'members_list'=>0,
                'details'=>0,
                'member_custom_fields'=>0,
                'member_groups'=>0
            ],
            'est'=>[
                'groups'=>1,
                'custom_fields'=>1,
                'custom_field_values'=>1,
                'members_list'=>1,
                'details'=>0,
                'member_custom_fields'=>1,
                'member_groups'=>1
            ],
            'next'=>[ 
                'groups'=>$this->normalize_endpoint(get_option('evg_groups_path','/api/v3.0/member-group')),
                'custom_fields'=>$this->normalize_endpoint(get_option('evg_custom_fields_path','/api/v3.0/custom-field')),
                'members_list'=>$this->normalize_endpoint(get_option('evg_members_path','/api/v3.0/member'))
            ],
            'member_ids'=>[],
            'custom_field_types'=>[],
            'custom_field_labels'=>[],
            'custom_field_values_seen'=>[],
            'member_index'=>0,
            'cap'=>max(0,(int)$cap),
            'done'=>false
        ];
        $this->put_state($state);
    }

    private function progress($s){
        $weights = [
            'groups'=>0.10,
            'custom_fields'=>0.15,
            'custom_field_values'=>0.10,
            'members_list'=>0.25,
            'details'=>0.15,
            'member_custom_fields'=>0.15,
            'member_groups'=>0.10
        ];
        $p = 0.0;
        foreach($weights as $k=>$weight){
            $est = max(1, isset($s['est'][$k]) ? $s['est'][$k] : 1);
            $cnt = isset($s['counts'][$k]) ? $s['counts'][$k] : 0;
            $p += $weight * min(1.0, $cnt / $est);
        }
        $label  = (isset($s['phase']) ? $s['phase'] : '');
        $label .= ' – G:' . (isset($s['counts']['groups']) ? (string)$s['counts']['groups'] : '0');
        $label .= ' CF:' . (isset($s['counts']['custom_fields']) ? (string)$s['counts']['custom_fields'] : '0');
        $label .= ' CFV:' . (isset($s['counts']['custom_field_values']) ? (string)$s['counts']['custom_field_values'] : '0');
        $label .= ' M:' . (isset($s['counts']['members_list']) ? (string)$s['counts']['members_list'] : '0');
        $label .= ' D:' . (isset($s['counts']['details']) ? (string)$s['counts']['details'] : '0');
        $label .= ' MC:' . (isset($s['counts']['member_custom_fields']) ? (string)$s['counts']['member_custom_fields'] : '0');
        $label .= ' L:' . (isset($s['counts']['member_groups']) ? (string)$s['counts']['member_groups'] : '0');
        return ['percent'=>100*$p,'label'=>$label,'done'=>!empty($s['done'])];
    }

    public function job_tick(){
        global $wpdb;
        $s = $this->get_state();
        if (empty($s)) return ['ok'=>false,'summary'=>'Kein aktiver Job'];
        if (!empty($s['done'])) return ['ok'=>true,'done'=>true,'percent'=>100,'label'=>'Fertig'];

        if (!isset($s['custom_field_types']) || !is_array($s['custom_field_types'])){
            $s['custom_field_types'] = [];
        }
        if (!isset($s['custom_field_labels']) || !is_array($s['custom_field_labels'])){
            $s['custom_field_labels'] = [];
        }
        if (!isset($s['custom_field_values_seen']) || !is_array($s['custom_field_values_seen'])){
            $s['custom_field_values_seen'] = [];
        }
        if (!isset($s['pages']['custom_fields'])){
            $s['pages']['custom_fields'] = 0;
        }
        if (!isset($s['counts']['custom_fields'])){
            $s['counts']['custom_fields'] = 0;
        }
        if (!isset($s['counts']['custom_field_values'])){
            $s['counts']['custom_field_values'] = 0;
        }
        if (!isset($s['counts']['member_custom_fields'])){
            $s['counts']['member_custom_fields'] = 0;
        }
        if (!isset($s['est']['custom_fields'])){
            $s['est']['custom_fields'] = 1;
        }
        if (!isset($s['est']['custom_field_values'])){
            $s['est']['custom_field_values'] = 1;
        }
        if (!isset($s['est']['member_custom_fields'])){
            $s['est']['member_custom_fields'] = 1;
        }
        if (!isset($s['next']['custom_fields'])){
            $s['next']['custom_fields'] = $this->normalize_endpoint(get_option('evg_custom_fields_path','/api/v3.0/custom-field'));
        }

        $m_table=$this->table('members');
        $g_table=$this->table('groups');
        $x_table=$this->table('member_groups');
        $cf_table=$this->table('custom_fields');
        $mv_table=$this->table('member_custom_fields');
        $cv_table=$this->table('custom_field_values');

        $calls=0; $max=$this->calls_per_tick();
        while($calls<$max){
            if($s['phase']==='groups'){
                $url=$s['next']['groups'];
                if(!$url || $s['pages']['groups'] >= $this->pages_max()){
                    $s['phase']='custom_fields';
                    $s['est']['custom_fields']=max(1,$s['counts']['custom_fields'] ?: 1);
                    continue;
                }
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)) return ['ok'=>false,'summary'=>$resp->get_error_message()];
                $code=wp_remote_retrieve_response_code($resp);
                if ($code<200||$code>=300) return ['ok'=>false,'summary'=>'Groups HTTP '.$code];
                $body=json_decode(wp_remote_retrieve_body($resp),true);
                $items=$this->extract_items_from_payload($body);
                $next=$this->extract_next_from_payload($body);
                $now=current_time('mysql',1);
                foreach((array)$items as $g){
                    if (!is_array($g)){
                        continue;
                    }
                    $gid = isset($g['id']) ? $g['id'] : (isset($g['groupId']) ? $g['groupId'] : (isset($g['uuid']) ? $g['uuid'] : null));
                    if(!$gid) continue;
                    $name = isset($g['name']) ? $g['name'] : (isset($g['title']) ? $g['title'] : (isset($g['groupName']) ? $g['groupName'] : $gid));
                    $short = isset($g['short']) ? $g['short'] : (isset($g['shortName']) ? $g['shortName'] : '');
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO $g_table (group_id,name,short,updated_at,raw)
                         VALUES (%s,%s,%s,%s,%s)
                         ON DUPLICATE KEY UPDATE
                           updated_at = VALUES(updated_at),
                           raw        = IF(VALUES(raw) <> '' AND VALUES(raw) IS NOT NULL, VALUES(raw), raw),
                           name       = IF(VALUES(name) <> '' AND VALUES(name) IS NOT NULL, VALUES(name), name),
                           short      = IF(VALUES(short) <> '' AND VALUES(short) IS NOT NULL, VALUES(short), short)",
                        $gid, ($name ?? ''), ($short ?? ''), $now, wp_json_encode($g, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                    ));
                    $s['counts']['groups']++;
                }
                $s['pages']['groups']++;
                $s['next']['groups']=$this->next_url($next);
            }
            elseif($s['phase']==='custom_fields'){
                $url = isset($s['next']['custom_fields']) ? $s['next']['custom_fields'] : null;
                if(!$url || $s['pages']['custom_fields'] >= $this->pages_max()){
                    $s['phase']='members_list';
                    $s['est']['members_list']=max(1,count($s['member_ids']));
                    continue;
                }
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)) return ['ok'=>false,'summary'=>$resp->get_error_message()];
                $code=wp_remote_retrieve_response_code($resp);
                if ($code<200||$code>=300) return ['ok'=>false,'summary'=>'Custom-Fields HTTP '.$code];
                $body=json_decode(wp_remote_retrieve_body($resp),true);
                $items=$this->extract_items_from_payload($body);
                $next=$this->extract_next_from_payload($body);
                $now=current_time('mysql',1);
                foreach((array)$items as $field){
                    if (!is_array($field)){
                        continue;
                    }
                    $fid = null;
                    foreach(['id','uuid','pk','customFieldId','fieldId'] as $fidKey){
                        if (isset($field[$fidKey]) && $field[$fidKey] !== ''){
                            if (is_array($field[$fidKey])){
                                continue;
                            }
                            $fid = (string)$field[$fidKey];
                            break;
                        }
                    }
                    if (!$fid && isset($field['url']) && is_string($field['url'])){
                        if (preg_match('~/(?:custom-field|custom-fields?)/([0-9a-zA-Z_-]+)~',$field['url'],$m)){
                            $fid=$m[1];
                        }
                    }
                    if (!$fid && isset($field['href']) && is_string($field['href'])){
                        if (preg_match('~/(?:custom-field|custom-fields?)/([0-9a-zA-Z_-]+)~',$field['href'],$m)){
                            $fid=$m[1];
                        }
                    }
                    if(!$fid) continue;
                    $name = isset($field['name']) ? (string)$field['name'] : '';
                    $settings_type = isset($field['settings_type']) ? (string)$field['settings_type'] : (isset($field['settingsType']) ? (string)$field['settingsType'] : '');
                    $kind = isset($field['kind']) ? (string)$field['kind'] : '';
                    $member_show = !empty($field['member_show']) || !empty($field['memberShow']) ? 1 : 0;
                    $member_edit = !empty($field['member_edit']) || !empty($field['memberEdit']) ? 1 : 0;
                    $position = isset($field['position']) ? intval($field['position']) : 0;
                    $collection = '';
                    if (isset($field['collection'])){
                        if (is_numeric($field['collection'])){
                            $collection = (string)$field['collection'];
                        } elseif (is_string($field['collection'])){
                            $collection = $field['collection'];
                        } elseif (is_array($field['collection'])){
                            if (isset($field['collection']['id'])){
                                $collection = (string)$field['collection']['id'];
                            } elseif (isset($field['collection']['pk'])){
                                $collection = (string)$field['collection']['pk'];
                            } elseif (isset($field['collection']['href']) && is_string($field['collection']['href'])){
                                if (preg_match('~/(?:custom-field-collection|custom-field-collections)/([0-9a-zA-Z_-]+)~',$field['collection']['href'],$m)){
                                    $collection=$m[1];
                                }
                            }
                        }
                    }
                    $s['custom_field_types'][(string)$fid] = $settings_type;
                    $wpdb->replace(
                        $cf_table,
                        [
                            'field_id'     => (string)$fid,
                            'name'         => $name,
                            'settings_type'=> $settings_type,
                            'kind'         => $kind,
                            'member_show'  => $member_show,
                            'member_edit'  => $member_edit,
                            'position'     => $position,
                            'collection'   => $collection,
                            'updated_at'   => $now,
                            'raw'          => wp_json_encode($field, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                        ],
                        ['%s','%s','%s','%s','%d','%d','%d','%s','%s','%s']
                    );
                    $s['custom_field_labels'][(string)$fid] = $name !== '' ? $name : (string)$fid;
                    $s['counts']['custom_fields']++;
                }
                $s['pages']['custom_fields']++;
                $s['next']['custom_fields']=$this->next_url($next);
                if (!$s['next']['custom_fields']){
                    $s['est']['members_list']=max(1,count($s['member_ids']));
                }
            }
            elseif($s['phase']==='members_list'){
                $url=$s['next']['members_list'];
                if(!$url || $s['pages']['members_list'] >= $this->pages_max()){
                    $s['phase']='details';
                    $s['member_index']=0;
                    $s['est']['details']=max(1,count($s['member_ids']));
                    $s['est']['member_custom_fields']=max(1,count($s['member_ids']));
                    continue;
                }
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)) return ['ok'=>false,'summary'=>$resp->get_error_message()];
                $code=wp_remote_retrieve_response_code($resp);
                if ($code<200||$code>=300) return ['ok'=>false,'summary'=>'Members HTTP '.$code];
                $body=json_decode(wp_remote_retrieve_body($resp),true);
                $items=$this->extract_items_from_payload($body);
                $next=$this->extract_next_from_payload($body);
                $now=current_time('mysql',1);
                foreach((array)$items as $m){
                    if (!is_array($m)){
                        continue;
                    }
                    $mid = isset($m['id']) ? $m['id'] : (isset($m['memberId']) ? $m['memberId'] : (isset($m['uuid']) ? $m['uuid'] : null));
                    $mno = isset($m['memberNumber']) ? $m['memberNumber'] : (isset($m['membershipNumber']) ? $m['membershipNumber'] : (isset($m['number']) ? $m['number'] : ''));
                    $cd  = '';
                    if (isset($m['contactDetails']) && is_string($m['contactDetails'])) { $cd = $m['contactDetails']; }
                    elseif (isset($m['contact_details']) && is_string($m['contact_details'])) { $cd = $m['contact_details']; }
                    elseif (isset($m['contactDetails']) && is_array($m['contactDetails']) && isset($m['contactDetails']['href'])) { $cd = $m['contactDetails']['href']; }
                    elseif (isset($m['contactDetailsId'])) { $cd = '/api/v3.0/contact-details/'.intval($m['contactDetailsId']); }
                    elseif (isset($m['contactId'])) { $cd = '/api/v3.0/contact-details/'.intval($m['contactId']); }
                    if($cd !== '' && strpos($cd,'http')!==0) { $cd = $this->base().(strpos($cd,'/')===0?$cd:'/'.$cd); }
                    if(!$mid) continue;
                    $wpdb->query($wpdb->prepare(
                        "INSERT INTO {$m_table} (member_id, member_number, contact_details, updated_at)
                         VALUES (%s,%s,%s,%s)
                         ON DUPLICATE KEY UPDATE member_number=VALUES(member_number),
                                                 contact_details=VALUES(contact_details),
                                                 updated_at=VALUES(updated_at)",
                        $mid, $mno, $cd, $now
                    ));
                    $s['member_ids'][]=(string)$mid;
                    $s['counts']['members_list']++;
                    if($s['cap']>0 && count($s['member_ids']) >= $s['cap']) { $next=null; break; }
                }
                $s['pages']['members_list']++;
                $s['next']['members_list']=$this->next_url($next);
                if($s['cap']>0 && count($s['member_ids']) >= $s['cap']){
                    $s['phase']='details';
                    $s['member_index']=0;
                    $s['est']['details']=count($s['member_ids']);
                    $s['est']['member_custom_fields']=count($s['member_ids']);
                }
            }
            elseif($s['phase']==='details'){
                if ($s['member_index'] >= count($s['member_ids'])){
                    $s['phase']='member_custom_fields';
                    $s['member_index']=0;
                    $s['est']['member_custom_fields']=max(1,count($s['member_ids']));
                    continue;
                }
                $mid=$s['member_ids'][$s['member_index']];
                $cd = $wpdb->get_var( $wpdb->prepare("SELECT contact_details FROM {$m_table} WHERE member_id=%s LIMIT 1", $mid) );
                if ($cd && strpos($cd,'http')===0) { $durl=$cd; }
                elseif ($cd) { $durl=$this->base().$cd; }
                else { $durl=$this->base().str_replace('{id}',rawurlencode($mid),get_option('evg_contact_details_path','/api/v3.0/contact-details/{id}')); }
                $resp=evg_http_get($durl,$this->headers()); $calls++; $this->rate_sleep();
                if (!is_wp_error($resp) && ( $c=wp_remote_retrieve_response_code($resp) )>=200 && $c<300){
                    $d=json_decode(wp_remote_retrieve_body($resp),true);
                    if(is_array($d)){
                        $first = isset($d['firstName']) ? $d['firstName'] : (isset($d['first_name']) ? $d['first_name'] : '');
                        $family= isset($d['familyName']) ? $d['familyName'] : (isset($d['lastName']) ? $d['lastName'] : (isset($d['last_name']) ? $d['last_name'] : ''));
                        $dob   = isset($d['dateOfBirth']) ? $d['dateOfBirth'] : (isset($d['date_of_birth']) ? $d['date_of_birth'] : (isset($d['birthDate']) ? $d['birthDate'] : null));
                        $age   = isset($d['age']) ? intval($d['age']) : null;
                        $pemail= isset($d['privateEmail']) ? $d['privateEmail'] : (isset($d['email_private']) ? $d['email_private'] : (isset($d['emailPrivate']) ? $d['emailPrivate'] : (isset($d['email']) ? $d['email'] : '')));
                        $zip   = isset($d['zip']) ? $d['zip'] : (isset($d['postalCode']) ? $d['postalCode'] : '');
                        $city  = isset($d['city']) ? $d['city'] : '';
                        $street= isset($d['street']) ? $d['street'] : '';
                        $addrS = isset($d['addressSuffix']) ? $d['addressSuffix'] : (isset($d['address_suffix']) ? $d['address_suffix'] : (isset($d['addressAddition']) ? $d['addressAddition'] : ''));
                        $birthYear = null;
                        if (isset($d['birthYear'])) {
                            $birthYear = intval($d['birthYear']);
                        } elseif (isset($d['yearOfBirth'])) {
                            $birthYear = intval($d['yearOfBirth']);
                        } elseif (!empty($dob) && is_string($dob) && preg_match('/^\d{4}/', $dob, $mY)) {
                            $birthYear = intval($mY[0]);
                        }
                        if ($birthYear !== null && ($birthYear < 1900 || $birthYear > intval(date('Y')) + 1)) {
                            $birthYear = null;
                        }
                        $gender = '';
                        if (!empty($d['salutation'])) {
                            $gender = is_array($d['salutation'])
                                ? implode(', ', array_filter(array_map('trim', (array) $d['salutation'])))
                                : trim((string) $d['salutation']);
                        }
                        $genderCandidates = ['gender','sex','sexType'];
                        foreach ($genderCandidates as $gKey){
                            if (!empty($d[$gKey])) { 
                                $gender = is_array($d[$gKey]) ? implode(', ', array_filter(array_map('trim',(array)$d[$gKey]))) : trim((string)$d[$gKey]);
                                break;
                            }
                        }
                        if (strlen($gender) > 32) {
                            $gender = substr($gender, 0, 32);
                        }
                        $phones = [];
                        $phoneCandidates = [
                            'phone','private_phone','privatePhone','phonePrivate','telephone','telephone_number','telephoneNumber','private_telephone','privateTelephone',
                            'mobile_phone','mobilePhone','mobile','mobile_private','mobilePrivate','mobile_telephone','mobileTelephone','phone_mobile','phoneMobile','phone_home','phoneHome','homePhone'
                        ];
                        if (isset($d['phones']) && is_array($d['phones'])) {
                            foreach ($d['phones'] as $p){
                                if (is_string($p) && trim($p) !== '') $phones[] = trim($p);
                                if (is_array($p)){
                                    foreach ($p as $sub){
                                        if (is_string($sub) && trim($sub) !== '') $phones[] = trim($sub);
                                    }
                                }
                            }
                        }
                        foreach ($phoneCandidates as $pKey){
                            if (!empty($d[$pKey])) {
                                if (is_array($d[$pKey])){
                                    foreach ($d[$pKey] as $sub){
                                        if (is_string($sub) && trim($sub) !== '') $phones[] = trim($sub);
                                    }
                                } else {
                                    $phones[] = trim((string)$d[$pKey]);
                                }
                            }
                        }
                        $phones = array_values(array_filter(array_unique($phones)));
                        $phone = implode(', ', array_slice($phones, 0, 3));
                        if (strlen($phone) > 120) {
                            $phone = substr($phone, 0, 120);
                        }
                        $now_detail = current_time('mysql',1);
                        $setParts = [
                            'first_name=%s',
                            'family_name=%s',
                            'age=%d',
                            'email_private=%s',
                            'zip=%s',
                            'city=%s',
                            'street=%s',
                            'address_suffix=%s',
                            'gender=%s',
                            'phone=%s',
                            'updated_at=%s'
                        ];
                        $params = [$first,$family,$age,$pemail,$zip,$city,$street,$addrS,$gender,$phone,$now_detail];
                        if (!empty($dob) && $dob !== '0000-00-00' && $dob !== '0000-00-00 00:00:00'){
                            $setParts[] = 'date_of_birth=%s';
                            $params[] = $dob;
                        } else {
                            $setParts[] = 'date_of_birth=NULL';
                        }
                        if ($birthYear !== null){
                            $setParts[] = 'birth_year=%d';
                            $params[] = $birthYear;
                        } else {
                            $setParts[] = 'birth_year=NULL';
                        }
                        $params[] = $mid;
                        $sql = "UPDATE {$m_table} SET ".implode(',', $setParts)." WHERE member_id=%s";
                        $wpdb->query($wpdb->prepare($sql, $params));
                    }
                    $s['counts']['details']++;
                }
                $s['member_index']++;
            }
            elseif($s['phase']==='member_custom_fields'){
                if ($s['member_index'] >= count($s['member_ids'])){
                    $s['phase']='member_groups';
                    $s['member_index']=0;
                    $s['est']['member_groups']=max(1,count($s['member_ids']));
                    continue;
                }
                $mid=$s['member_ids'][$s['member_index']];
                $cf_path = get_option('evg_member_custom_fields_path','/api/v3.0/member-custom-field-assignment?user_object={id}');
                $cf_path = str_replace('{id}', rawurlencode($mid), $cf_path);
                $cf_url = $this->normalize_endpoint($cf_path);
                $resp=evg_http_get($cf_url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)) return ['ok'=>false,'summary'=>$resp->get_error_message()];
                $code = wp_remote_retrieve_response_code($resp);
                if ($code<200 || $code>=300) return ['ok'=>false,'summary'=>'Member-Custom-Fields HTTP '.$code];
                $payload=json_decode(wp_remote_retrieve_body($resp),true);
                $items=$this->extract_items_from_payload($payload);
                $now=current_time('mysql',1);
                foreach((array)$items as $entry){
                    if (!is_array($entry)){
                        continue;
                    }
                    $field_id=null;
                    $fieldRef=$entry['customField']??$entry['custom_field']??null;
                    if (is_string($fieldRef) && $fieldRef!==''){
                        if (preg_match('~/(?:custom-field|custom-fields?)/([0-9a-zA-Z_-]+)~',$fieldRef,$m)){
                            $field_id=$m[1];
                        } else {
                            $field_id=$fieldRef;
                        }
                    } elseif (is_array($fieldRef)){
                        if (!empty($fieldRef['id'])){
                            $field_id=(string)$fieldRef['id'];
                        } elseif (!empty($fieldRef['pk'])){
                            $field_id=(string)$fieldRef['pk'];
                        } elseif (!empty($fieldRef['href']) && is_string($fieldRef['href'])){
                            if (preg_match('~/(?:custom-field|custom-fields?)/([0-9a-zA-Z_-]+)~',$fieldRef['href'],$m)){
                                $field_id=$m[1];
                            }
                        }
                    }
                    if (!$field_id && isset($entry['customFieldId'])){
                        $field_id=(string)$entry['customFieldId'];
                    }
                    if (!$field_id && isset($entry['fieldId'])){
                        $field_id=(string)$entry['fieldId'];
                    }
                    if (!$field_id) continue;
                    $value_source = null;
                    if (array_key_exists('value',$entry)){
                        $value_source = $entry['value'];
                    } elseif (array_key_exists('valueText',$entry)){
                        $value_source = $entry['valueText'];
                    } elseif (array_key_exists('value_text',$entry)){
                        $value_source = $entry['value_text'];
                    }
                    if (($value_source === null || $value_source === '' || $value_source === []) && isset($entry['selectOptions']) && is_array($entry['selectOptions'])){
                        $value_source = $entry['selectOptions'];
                    }
                    $prepared = $this->prepare_custom_field_value((string)$field_id, $value_source);
                    if (!$prepared){
                        continue;
                    }
                    $field_label = '';
                    if (isset($s['custom_field_labels'][(string)$field_id]) && $s['custom_field_labels'][(string)$field_id] !== ''){
                        $field_label = $s['custom_field_labels'][(string)$field_id];
                    } else {
                        $fetched_label = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$cf_table} WHERE field_id=%s LIMIT 1", (string)$field_id));
                        if ($fetched_label === null || $fetched_label === ''){
                            $field_label = (string)$field_id;
                        } else {
                            $field_label = (string)$fetched_label;
                        }
                        $s['custom_field_labels'][(string)$field_id] = $field_label;
                    }

                    $entry_raw = wp_json_encode($entry, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    $wpdb->replace(
                        $mv_table,
                        [
                            'member_id'  => $mid,
                            'field_id'   => (string)$field_id,
                            'value_hash' => $prepared['hash'],
                            'value_text' => $prepared['label'],
                            'updated_at' => $now,
                            'raw'        => $entry_raw
                        ],
                        ['%s','%s','%s','%s','%s','%s']
                    );
                    $s['counts']['member_custom_fields']++;

                    $cv_key = (string)$field_id.'|'.$prepared['hash'];
                    $wpdb->replace(
                        $cv_table,
                        [
                            'field_id'    => (string)$field_id,
                            'value_hash'  => $prepared['hash'],
                            'field_label' => $field_label !== '' ? $field_label : (string)$field_id,
                            'value_label' => $prepared['label'],
                            'value_raw'   => $prepared['raw'],
                            'updated_at'  => $now
                        ],
                        ['%s','%s','%s','%s','%s','%s']
                    );
                    if (!isset($s['custom_field_values_seen'][$cv_key])){
                        $s['custom_field_values_seen'][$cv_key] = true;
                        $s['counts']['custom_field_values']++;
                        if ($s['counts']['custom_field_values'] > $s['est']['custom_field_values']){
                            $s['est']['custom_field_values'] = $s['counts']['custom_field_values'];
                        }
                    }
                }
                $s['member_index']++;
            }
            elseif($s['phase']==='member_groups'){
                if ($s['member_index'] >= count($s['member_ids'])) { $s['done']=true; break; }
                $mid=$s['member_ids'][$s['member_index']];
                $gurl=$this->base().str_replace('{id}',rawurlencode($mid),get_option('evg_member_groups_path','/api/v3.0/member-group-assignment?user_object={id}'));
                $resp=evg_http_get($gurl,$this->headers()); $calls++; $this->rate_sleep();
                if (!is_wp_error($resp) && ( $c=wp_remote_retrieve_response_code($resp) )>=200 && $c<300){
                    $arr=json_decode(wp_remote_retrieve_body($resp),true); $items=[];
                    if(is_array($arr)){
                        if(array_keys($arr)===range(0,count($arr)-1)) $items=$arr; else $items=$arr['results']??$arr['data']??$arr['items']??[];
                    }
                    $now=current_time('mysql',1);
                    
                    // Get member's name for speaking column
                    $member_name = '';
                    $member_data = $wpdb->get_row($wpdb->prepare("SELECT first_name, family_name FROM $m_table WHERE member_id = %s", $mid), ARRAY_A);
                    if ($member_data) {
                        $member_name = trim(($member_data['first_name'] ?? '') . ' ' . ($member_data['family_name'] ?? ''));
                    }
                    
                    foreach((array)$items as $g){
                        $gid = null;
                        // Prefer the real group id from the member_group URL (e.g. .../api/v3.0/member-group/266553457)
                        $memberGroupRef = $g['member_group'] ?? $g['memberGroup'] ?? null;
                        if (isset($memberGroupRef)) { $g['memberGroup'] = $memberGroupRef; }
                        if (isset($g['memberGroup'])) {
                            if (is_string($g['memberGroup'])) {
                                $mg = $g['memberGroup'];
                                if (preg_match('~/(?:member-group|groups?)/([0-9]+)~', $mg, $m)) {
                                    $gid = $m[1];
                                } else {
                                    // try last path segment as numeric
                                    $tail = parse_url($mg, PHP_URL_PATH);
                                    if ($tail) {
                                        $parts = explode('/', trim($tail,'/'));
                                        $last  = end($parts);
                                        if (ctype_digit($last)) $gid = $last;
                                    }
                                }
                            } elseif (is_array($g['memberGroup'])) {
                                // if API returns an object with id/href
                                if (!empty($g['memberGroup']['id']) && is_numeric($g['memberGroup']['id'])) {
                                    $gid = (string)$g['memberGroup']['id'];
                                } elseif (!empty($g['memberGroup']['href']) && is_string($g['memberGroup']['href'])) {
                                    $mg = $g['memberGroup']['href'];
                                    if (preg_match('~/(?:member-group|groups?)/([0-9]+)~', $mg, $m)) {
                                        $gid = $m[1];
                                    }
                                }
                            }
                        }
                        // Conservative fallbacks (do NOT use relation id)
                        if (!$gid && isset($g['groupId']) && is_numeric($g['groupId'])) {
                            $gid = (string)$g['groupId'];
                        }
                        if (!$gid && isset($g['uuid']) && is_numeric($g['uuid'])) {
                            $gid = (string)$g['uuid'];
                        }
                        if(!$gid) continue;

                        // Only read the group name from local evg_groups; do NOT write to evg_groups here
                        $group_name_local = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT name FROM $g_table WHERE group_id = %s LIMIT 1",
                                $gid
                            )
                        );
                        if ($group_name_local === null) {
                            $group_name_local = '';
                        }

                        // Create/refresh member->group relationship using resolved group_name
                        $wpdb->replace(
                            $x_table,
                            [
                                'member_id'   => $mid,
                                'group_id'    => $gid,
                                'member_name' => $member_name,
                                'group_name'  => $group_name_local,
                                'assigned_at' => $now
                            ],
                            ['%s','%s','%s','%s','%s']
                        );
                        $s['counts']['member_groups']++;
                    }
                }
                $s['member_index']++;
            }
            else {
                return ['ok'=>false,'summary'=>'Unbekannte Phase: '.(isset($s['phase'])?$s['phase']:'<none>')];
            }
        }
        $this->put_state($s);
        return array_merge(['ok'=>true], $this->progress($s));
    }
}
