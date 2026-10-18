<?php
if (!defined('ABSPATH')) { exit; }

class EVG_Sync {
    private $state_key='evg_sync_job';
    private $table_prefix='evg';
    private $delta_calls=0;

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

    /**
     * v3.0 liefert selected_options nur als Referenzen (…/select-option/{id}).
     * Löst sie in ihre Anzeigewerte auf; Ergebnisse werden im Sync-State gecacht,
     * damit jede Option pro Lauf nur einmal abgefragt wird.
     */
    private function resolve_select_options(array $refs, array &$s, &$calls){
        $labels = [];
        foreach ($refs as $ref){
            if (is_array($ref) && isset($ref['value'])){ $labels[] = (string)$ref['value']; continue; }
            if (!is_scalar($ref)) continue;
            $ref = (string)$ref;
            if (!preg_match('~/select-option/([0-9]+)~',$ref,$mm) && !preg_match('~^([0-9]+)$~',$ref,$mm)){ $labels[] = $ref; continue; }
            $oid = $mm[1];
            if (!isset($s['select_option_labels'][$oid])){
                $label = $oid;
                $resp  = evg_http_get($this->base().'/api/v3.0/select-option/'.$oid,$this->headers()); $calls++; $this->rate_sleep();
                if (!is_wp_error($resp) && ($c=wp_remote_retrieve_response_code($resp))>=200 && $c<300){
                    $opt = json_decode(wp_remote_retrieve_body($resp),true);
                    if (is_array($opt) && isset($opt['value']) && $opt['value'] !== '') $label = (string)$opt['value'];
                }
                $s['select_option_labels'][$oid] = $label;
            }
            $labels[] = $s['select_option_labels'][$oid];
        }
        return $labels;
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
    // Die Drosselung übernimmt evg_api_throttle() zentral in evg_http_get().
    private function rate_sleep(){}
    private function get_state(){ $s=get_option($this->state_key); return is_array($s)?$s:[]; }
    private function put_state($s){ update_option($this->state_key,$s,false); }
    private function clear_state(){ delete_option($this->state_key); }

    // ── Gemeinsame Schreib-Helfer (voller Sync + Delta-Sync) ────────────────

    /** Gruppe upserten; gibt die Gruppen-ID zurück oder null. */
    private function upsert_group(array $g, $now){
        global $wpdb;
        $gid  = $g['id'] ?? $g['groupId'] ?? $g['uuid'] ?? null;
        if (!$gid) return null;
        $name  = $g['name']  ?? $g['title']     ?? $g['groupName'] ?? (string)$gid;
        $short = $g['short'] ?? $g['shortName'] ?? '';
        $g_table = $this->table('groups');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $g_table (group_id,name,short,updated_at,raw) VALUES (%s,%s,%s,%s,%s)
             ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at),raw=IF(VALUES(raw)<>'' AND VALUES(raw) IS NOT NULL,VALUES(raw),raw),name=IF(VALUES(name)<>'' AND VALUES(name) IS NOT NULL,VALUES(name),name),short=IF(VALUES(short)<>'' AND VALUES(short) IS NOT NULL,VALUES(short),short)",
            $gid, (string)$name, (string)$short, $now,
            wp_json_encode($g,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ));
        return (string)$gid;
    }

    /** Custom-Field-Definition upserten und Typ/Label im State merken; gibt die Feld-ID zurück oder null. */
    private function upsert_custom_field(array $field, array &$s, $now){
        global $wpdb;
        $fid = null;
        foreach (['id','uuid','pk','customFieldId','fieldId'] as $fk){
            if (!empty($field[$fk]) && !is_array($field[$fk])){ $fid=(string)$field[$fk]; break; }
        }
        if (!$fid && isset($field['url'])  && preg_match('~/custom-field[s]?/([0-9a-zA-Z_-]+)~',(string)$field['url'],$mm))  $fid=$mm[1];
        if (!$fid && isset($field['href']) && preg_match('~/custom-field[s]?/([0-9a-zA-Z_-]+)~',(string)$field['href'],$mm)) $fid=$mm[1];
        if (!$fid) return null;
        $name          = (string)($field['name']         ?? '');
        $settings_type = (string)($field['settings_type'] ?? $field['settingsType'] ?? '');
        $kind          = (string)($field['kind']          ?? '');
        $member_show   = (!empty($field['member_show']) || !empty($field['memberShow'])) ? 1 : 0;
        $member_edit   = (!empty($field['member_edit']) || !empty($field['memberEdit'])) ? 1 : 0;
        $position      = (int)($field['position'] ?? 0);
        $collection    = '';
        if (isset($field['collection'])){
            $col = $field['collection'];
            if (is_numeric($col)) $collection = (string)$col;
            elseif (is_string($col)) $collection = $col;
            elseif (is_array($col)){
                if (isset($col['id']))  $collection = (string)$col['id'];
                elseif (isset($col['pk'])) $collection = (string)$col['pk'];
                elseif (!empty($col['href']) && preg_match('~/custom-field-collections?/([0-9a-zA-Z_-]+)~',(string)$col['href'],$mm)) $collection=$mm[1];
            }
        }
        $s['custom_field_types'][$fid]  = $settings_type;
        $s['custom_field_labels'][$fid]  = $name !== '' ? $name : $fid;
        $wpdb->replace($this->table('custom_fields'),[
            'field_id'      => $fid, 'name' => $name, 'settings_type' => $settings_type,
            'kind'          => $kind, 'member_show' => $member_show, 'member_edit' => $member_edit,
            'position'      => $position, 'collection' => $collection, 'updated_at' => $now,
            'raw'           => wp_json_encode($field,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ],['%s','%s','%s','%s','%d','%d','%d','%s','%s','%s']);
        return $fid;
    }

    /** Mitglied (Listenfelder) upserten; gibt die Mitglieds-ID zurück oder null. */
    private function upsert_member_row(array $m, $now){
        global $wpdb;
        $mid = $m['id'] ?? $m['memberId'] ?? $m['uuid'] ?? null;
        if (!$mid) return null;
        $mid = (string)$mid;
        $mno = $m['membership_number'] ?? $m['memberNumber'] ?? $m['membershipNumber'] ?? $m['number'] ?? '';
        $cd  = '';
        if (!empty($m['contactDetails']) && is_string($m['contactDetails']))          $cd = $m['contactDetails'];
        elseif (!empty($m['contact_details']) && is_string($m['contact_details']))    $cd = $m['contact_details'];
        elseif (isset($m['contactDetails']['href']))                                   $cd = (string)$m['contactDetails']['href'];
        elseif (isset($m['contactDetailsId']))  $cd = '/api/v3.0/contact-details/'.intval($m['contactDetailsId']);
        elseif (isset($m['contactId']))         $cd = '/api/v3.0/contact-details/'.intval($m['contactId']);
        if ($cd !== '' && strpos($cd,'http') !== 0) $cd = $this->base().(strpos($cd,'/')===0 ? $cd : '/'.$cd);
        $m_table = $this->table('members');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$m_table} (member_id,member_number,contact_details,updated_at) VALUES (%s,%s,%s,%s)
             ON DUPLICATE KEY UPDATE member_number=VALUES(member_number),contact_details=VALUES(contact_details),updated_at=VALUES(updated_at)",
            $mid, (string)$mno, $cd, $now
        ));
        return $mid;
    }

    /** Kontaktdaten (contact-details-Objekt) in die Mitgliederzeile schreiben. */
    private function apply_contact_details($mid, array $d){
        global $wpdb;
        $m_table = $this->table('members');
        $now = current_time('mysql',1);
        $first  = $d['first_name']  ?? $d['firstName']  ?? '';
        $family = $d['family_name']  ?? $d['familyName'] ?? $d['lastName'] ?? $d['last_name'] ?? '';
        $dob    = $d['date_of_birth'] ?? $d['dateOfBirth'] ?? $d['birthDate'] ?? null;
        $age    = isset($d['age']) ? intval($d['age']) : null;
        $pemail = $d['private_email'] ?? $d['privateEmail'] ?? $d['email_private'] ?? $d['primary_email'] ?? $d['email'] ?? '';
        $zip    = $d['zip']    ?? $d['postalCode']  ?? '';
        $city   = $d['city']   ?? '';
        $street = $d['street'] ?? '';
        $addrS  = $d['address_suffix'] ?? $d['addressSuffix'] ?? $d['addressAddition'] ?? '';
        $birthYear = null;
        if (isset($d['birthYear']))       $birthYear = intval($d['birthYear']);
        elseif (isset($d['yearOfBirth'])) $birthYear = intval($d['yearOfBirth']);
        elseif (!empty($dob) && preg_match('/^\d{4}/',(string)$dob,$mY)) $birthYear = intval($mY[0]);
        if ($birthYear !== null && ($birthYear < 1900 || $birthYear > intval(date('Y'))+1)) $birthYear = null;
        $gender = '';
        if (!empty($d['salutation'])) $gender = is_array($d['salutation']) ? implode(', ',array_filter(array_map('trim',(array)$d['salutation']))) : trim((string)$d['salutation']);
        foreach (['gender','sex','sexType'] as $gk){ if (!empty($d[$gk])){ $gender = is_array($d[$gk]) ? implode(', ',array_filter(array_map('trim',(array)$d[$gk]))) : trim((string)$d[$gk]); break; } }
        if (strlen($gender) > 32) $gender = substr($gender,0,32);
        $phones = [];
        $pKeys = ['private_phone','mobile_phone','company_phone',
                  'phone','privatePhone','phonePrivate','telephone','telephone_number','telephoneNumber',
                  'private_telephone','privateTelephone','mobilePhone','mobile','mobile_private','mobilePrivate',
                  'mobile_telephone','mobileTelephone','phone_mobile','phoneMobile','phone_home','phoneHome','homePhone'];
        if (isset($d['phones']) && is_array($d['phones'])){ foreach($d['phones'] as $p){ if(is_string($p)&&trim($p)!=='') $phones[]=trim($p); if(is_array($p)) foreach($p as $sub) if(is_string($sub)&&trim($sub)!=='') $phones[]=trim($sub); } }
        foreach ($pKeys as $pk){ if(!empty($d[$pk])){ if(is_array($d[$pk])){ foreach($d[$pk] as $sub) if(is_string($sub)&&trim($sub)!=='') $phones[]=trim($sub); } else $phones[]=trim((string)$d[$pk]); } }
        $phones = array_values(array_filter(array_unique($phones)));
        $phone  = substr(implode(', ',array_slice($phones,0,3)),0,120);
        $setParts = ['first_name=%s','family_name=%s','age=%d','email_private=%s','zip=%s','city=%s','street=%s','address_suffix=%s','gender=%s','phone=%s','updated_at=%s'];
        $params   = [$first,$family,$age,$pemail,$zip,$city,$street,(string)$addrS,$gender,$phone,$now];
        if (!empty($dob)&&$dob!=='0000-00-00'&&$dob!=='0000-00-00 00:00:00'){ $setParts[]='date_of_birth=%s'; $params[]=$dob; } else { $setParts[]='date_of_birth=NULL'; }
        if ($birthYear !== null){ $setParts[]='birth_year=%d'; $params[]=$birthYear; } else { $setParts[]='birth_year=NULL'; }
        $params[] = $mid;
        $wpdb->query($wpdb->prepare("UPDATE {$m_table} SET ".implode(',',$setParts)." WHERE member_id=%s",$params));
    }

    /** Feld-ID aus einer Custom-Field-Zuordnung lesen. */
    private function field_id_from_assignment(array $entry){
        $field_id = null;
        $fieldRef = $entry['customField'] ?? $entry['custom_field'] ?? null;
        if (is_string($fieldRef) && $fieldRef !== ''){
            if (preg_match('~/custom-field[s]?/([0-9a-zA-Z_-]+)~',$fieldRef,$mm)) $field_id=$mm[1]; else $field_id=$fieldRef;
        } elseif (is_array($fieldRef)){
            if (!empty($fieldRef['id']))  $field_id=(string)$fieldRef['id'];
            elseif (!empty($fieldRef['pk'])) $field_id=(string)$fieldRef['pk'];
            elseif (!empty($fieldRef['href']) && preg_match('~/custom-field[s]?/([0-9a-zA-Z_-]+)~',(string)$fieldRef['href'],$mm)) $field_id=$mm[1];
        }
        if (!$field_id) $field_id = $entry['customFieldId'] ?? $entry['fieldId'] ?? null;
        if (!$field_id) $field_id = isset($entry['id']) ? (string)$entry['id'] : null;
        return $field_id ? (string)$field_id : null;
    }

    /**
     * Custom-Field-Zuordnung eines Mitglieds speichern.
     * Rückgabe: null (unbrauchbar) oder ['mid','fid','stored'=>bool,'new_value'=>bool].
     * stored=false heißt: Wert ist leer – der Aufrufer entscheidet, ob eine bestehende Zeile gelöscht wird.
     */
    private function store_member_cf_entry(array $entry, array &$s, &$calls, $now){
        global $wpdb;
        $mid = $this->extract_member_id_from_ref($entry['user_object'] ?? null);
        if (!$mid) return null;
        $field_id = $this->field_id_from_assignment($entry);
        if (!$field_id) return null;
        $value_source = null;
        if (array_key_exists('value',$entry))       $value_source = $entry['value'];
        elseif (array_key_exists('valueText',$entry)) $value_source = $entry['valueText'];
        elseif (array_key_exists('value_text',$entry)) $value_source = $entry['value_text'];
        if (($value_source === null||$value_source===''||$value_source===[]) && isset($entry['selectOptions']) && is_array($entry['selectOptions'])) $value_source=$entry['selectOptions'];
        if (($value_source === null||$value_source===''||$value_source===[]) && !empty($entry['selected_options']) && is_array($entry['selected_options'])){
            $value_source = $this->resolve_select_options($entry['selected_options'],$s,$calls);
        }
        $prepared = $this->prepare_custom_field_value($field_id,$value_source);
        if (!$prepared) return ['mid'=>$mid,'fid'=>$field_id,'stored'=>false,'new_value'=>false];
        $cf_table = $this->table('custom_fields');
        $field_label = $s['custom_field_labels'][$field_id] ?? '';
        if ($field_label === ''){
            $field_label = (string)($wpdb->get_var($wpdb->prepare("SELECT name FROM {$cf_table} WHERE field_id=%s LIMIT 1",$field_id)) ?: $field_id);
            $s['custom_field_labels'][$field_id] = $field_label;
        }
        $entry_raw = wp_json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $wpdb->replace($this->table('member_custom_fields'),['member_id'=>$mid,'field_id'=>$field_id,'value_hash'=>$prepared['hash'],'value_text'=>$prepared['label'],'updated_at'=>$now,'raw'=>$entry_raw],['%s','%s','%s','%s','%s','%s']);
        $wpdb->replace($this->table('custom_field_values'),['field_id'=>$field_id,'value_hash'=>$prepared['hash'],'field_label'=>$field_label,'value_label'=>$prepared['label'],'value_raw'=>$prepared['raw'],'updated_at'=>$now],['%s','%s','%s','%s','%s','%s']);
        $cv_key = $field_id.'|'.$prepared['hash'];
        $new_value = !isset($s['custom_field_values_seen'][$cv_key]);
        $s['custom_field_values_seen'][$cv_key] = true;
        return ['mid'=>$mid,'fid'=>$field_id,'stored'=>true,'new_value'=>$new_value];
    }

    /** Gruppen-ID aus einer Gruppen-Zuordnung lesen. */
    private function group_id_from_assignment(array $g){
        $gid = null;
        $mgRef = $g['member_group'] ?? $g['memberGroup'] ?? null;
        if (is_string($mgRef)){
            if (preg_match('~/(?:member-group|groups?)/([0-9]+)~',$mgRef,$mm)) $gid=$mm[1];
            else { $tail=parse_url($mgRef,PHP_URL_PATH); if($tail){ $parts=explode('/',trim($tail,'/')); $last=end($parts); if(ctype_digit($last)) $gid=$last; } }
        } elseif (is_array($mgRef)){
            if (!empty($mgRef['id']) && is_numeric($mgRef['id'])) $gid=(string)$mgRef['id'];
            elseif (!empty($mgRef['href']) && preg_match('~/(?:member-group|groups?)/([0-9]+)~',(string)$mgRef['href'],$mm)) $gid=$mm[1];
        }
        if (!$gid && isset($g['groupId']) && is_numeric($g['groupId'])) $gid=(string)$g['groupId'];
        return $gid;
    }

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
            'phase'  => 'groups',
            'pages'  => ['groups'=>0,'custom_fields'=>0,'members_list'=>0],
            'counts' => ['groups'=>0,'custom_fields'=>0,'custom_field_values'=>0,'members_list'=>0,'details'=>0,'member_custom_fields'=>0,'member_groups'=>0],
            'est'    => ['groups'=>1,'custom_fields'=>1,'custom_field_values'=>1,'members_list'=>1,'details'=>1,'member_custom_fields'=>1,'member_groups'=>1],
            'next'   => [
                'groups'        => $this->normalize_endpoint(get_option('evg_groups_path','/api/v3.0/member-group')),
                'custom_fields' => $this->normalize_endpoint(get_option('evg_custom_fields_path','/api/v3.0/custom-field')),
                'members_list'  => $this->normalize_endpoint(get_option('evg_members_path','/api/v3.0/member')),
            ],
            'member_ids'              => [],
            'member_index'            => 0,
            'custom_field_types'      => [],
            'custom_field_labels'     => [],
            'select_option_labels'    => [],
            'custom_field_values_seen'=> [],
            'cap'  => max(0,(int)$cap),
            'done' => false,
            'started_at' => time()
        ];
        $this->put_state($state);
    }

    /** Returns bulk base URL by stripping /{id} or ?query={id} from a per-item path */
    private function bulk_url_from_path($option_key,$default){
        $path = (string)get_option($option_key,$default);
        // Strip ?key={id} query-string variants
        $path = preg_replace('/\?[^?]*\{id\}[^?]*/','', $path);
        // Strip /{id} path segment
        $path = preg_replace('/\/\{id\}.*$/','',$path);
        $path = rtrim($path,'?&/');
        return $this->normalize_endpoint($path);
    }

    /** Extract member ID from user_object field (URL or scalar) */
    private function extract_member_id_from_ref($ref){
        if ($ref === null) return null;
        if (is_numeric($ref)) return (string)$ref;
        if (is_string($ref)){
            if (preg_match('~/member/([0-9a-zA-Z_-]+)~',$ref,$m)) return $m[1];
            return $ref !== '' ? $ref : null;
        }
        if (is_array($ref)){
            if (!empty($ref['id']))  return (string)$ref['id'];
            if (!empty($ref['pk']))  return (string)$ref['pk'];
            if (!empty($ref['href']) && is_string($ref['href'])){
                if (preg_match('~/member/([0-9a-zA-Z_-]+)~',$ref['href'],$m)) return $m[1];
            }
        }
        return null;
    }

    /** Extract contact-details ID from a stored URL or path */
    private function extract_cd_id($url){
        if (empty($url)) return null;
        if (preg_match('~/contact-details?/([0-9a-zA-Z_-]+)~',$url,$m)) return $m[1];
        return null;
    }

    private function progress($s){
        $weights = [
            'groups'              => 0.05,
            'custom_fields'       => 0.10,
            'custom_field_values' => 0.05,
            'members_list'        => 0.15,
            'details'             => 0.25,
            'member_custom_fields'=> 0.25,
            'member_groups'       => 0.15,
        ];
        $p = 0.0;
        foreach($weights as $k=>$weight){
            $est = max(1, isset($s['est'][$k]) ? $s['est'][$k] : 1);
            $cnt = isset($s['counts'][$k]) ? $s['counts'][$k] : 0;
            $p += $weight * min(1.0, $cnt / $est);
        }
        $label  = (isset($s['phase']) ? $s['phase'] : '');
        $label .= ' G:'  . ($s['counts']['groups']               ?? 0);
        $label .= ' CF:' . ($s['counts']['custom_fields']         ?? 0);
        $label .= ' M:'  . ($s['counts']['members_list']          ?? 0);
        $label .= ' D:'  . ($s['counts']['details']               ?? 0);
        $label .= ' MC:' . ($s['counts']['member_custom_fields']   ?? 0);
        $label .= ' L:'  . ($s['counts']['member_groups']          ?? 0);
        return ['percent'=>100*$p,'label'=>$label,'done'=>!empty($s['done'])];
    }

    public function job_tick(){
        global $wpdb;
        $s = $this->get_state();
        if (empty($s)) return ['ok'=>false,'summary'=>'Kein aktiver Job'];
        if (!empty($s['done'])) return ['ok'=>true,'done'=>true,'percent'=>100,'label'=>'Fertig'];

        // Ensure state keys exist
        foreach (['custom_field_types','custom_field_labels','custom_field_values_seen','member_ids'] as $k){
            if (!isset($s[$k]) || !is_array($s[$k])) $s[$k] = [];
        }
        if (!isset($s['member_index'])) $s['member_index'] = 0;
        if (!isset($s['next']['custom_fields'])){
            $s['next']['custom_fields'] = $this->normalize_endpoint(get_option('evg_custom_fields_path','/api/v3.0/custom-field'));
        }

        $m_table  = $this->table('members');
        $g_table  = $this->table('groups');
        $x_table  = $this->table('member_groups');
        $cf_table = $this->table('custom_fields');
        $mv_table = $this->table('member_custom_fields');
        $cv_table = $this->table('custom_field_values');

        $calls = 0; $max = $this->calls_per_tick();

        while ($calls < $max) {

            // ── PHASE: groups ────────────────────────────────────────────────
            if ($s['phase'] === 'groups') {
                $url = $s['next']['groups'];
                if (!$url || $s['pages']['groups'] >= $this->pages_max()) {
                    $s['phase'] = 'custom_fields'; continue;
                }
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)) return ['ok'=>false,'summary'=>$resp->get_error_message()];
                $code = wp_remote_retrieve_response_code($resp);
                if ($code<200||$code>=300) return ['ok'=>false,'summary'=>'Groups HTTP '.$code];
                $body  = json_decode(wp_remote_retrieve_body($resp),true);
                $items = $this->extract_items_from_payload($body);
                $next  = $this->extract_next_from_payload($body);
                $now   = current_time('mysql',1);
                foreach ((array)$items as $g){
                    if (is_array($g) && $this->upsert_group($g,$now) !== null) $s['counts']['groups']++;
                }
                $s['pages']['groups']++;
                $s['next']['groups'] = $this->next_url($next);

            // ── PHASE: custom_fields ─────────────────────────────────────────
            } elseif ($s['phase'] === 'custom_fields') {
                $url = $s['next']['custom_fields'] ?? null;
                if (!$url || $s['pages']['custom_fields'] >= $this->pages_max()) {
                    $s['phase'] = 'members_list'; continue;
                }
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)) return ['ok'=>false,'summary'=>$resp->get_error_message()];
                $code = wp_remote_retrieve_response_code($resp);
                if ($code<200||$code>=300) return ['ok'=>false,'summary'=>'Custom-Fields HTTP '.$code];
                $body  = json_decode(wp_remote_retrieve_body($resp),true);
                $items = $this->extract_items_from_payload($body);
                $next  = $this->extract_next_from_payload($body);
                $now   = current_time('mysql',1);
                foreach ((array)$items as $field){
                    if (is_array($field) && $this->upsert_custom_field($field,$s,$now) !== null) $s['counts']['custom_fields']++;
                }
                $s['pages']['custom_fields']++;
                $s['next']['custom_fields'] = $this->next_url($next);

            // ── PHASE: members_list ──────────────────────────────────────────
            } elseif ($s['phase'] === 'members_list') {
                $url = $s['next']['members_list'];
                if (!$url || $s['pages']['members_list'] >= $this->pages_max()) {
                    $s['phase'] = 'details';
                    $s['member_index'] = 0;
                    $s['est']['details'] = max(1, count($s['member_ids']));
                    $s['est']['member_custom_fields'] = max(1, count($s['member_ids']));
                    $s['est']['member_groups'] = max(1, count($s['member_ids']));
                    continue;
                }
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)) return ['ok'=>false,'summary'=>$resp->get_error_message()];
                $code = wp_remote_retrieve_response_code($resp);
                if ($code<200||$code>=300) return ['ok'=>false,'summary'=>'Members HTTP '.$code];
                $body  = json_decode(wp_remote_retrieve_body($resp),true);
                $items = $this->extract_items_from_payload($body);
                $next  = $this->extract_next_from_payload($body);
                $now   = current_time('mysql',1);
                foreach ((array)$items as $m){
                    if (!is_array($m)) continue;
                    $mid = $this->upsert_member_row($m,$now);
                    if ($mid === null) continue;
                    $s['member_ids'][] = $mid;
                    $s['counts']['members_list']++;
                    if ($s['cap'] > 0 && count($s['member_ids']) >= $s['cap']) { $next = null; break; }
                }
                $s['pages']['members_list']++;
                $s['next']['members_list'] = $this->next_url($next);
                if ($s['cap'] > 0 && count($s['member_ids']) >= $s['cap']){
                    $s['phase'] = 'details';
                    $s['member_index'] = 0;
                    $s['est']['details'] = count($s['member_ids']);
                    $s['est']['member_custom_fields'] = count($s['member_ids']);
                    $s['est']['member_groups'] = count($s['member_ids']);
                }

            // ── PHASE: details (per-member contact-details) ──────────────────
            } elseif ($s['phase'] === 'details') {
                if ($s['member_index'] >= count($s['member_ids'])){
                    $s['phase'] = 'member_cf';
                    $s['member_index'] = 0;
                    continue;
                }
                $mid  = $s['member_ids'][$s['member_index']];
                $cd   = $wpdb->get_var($wpdb->prepare("SELECT contact_details FROM {$m_table} WHERE member_id=%s LIMIT 1",$mid));
                if ($cd && strpos($cd,'http')===0) { $durl=$cd; }
                elseif ($cd) { $durl=$this->base().$cd; }
                else { $durl=$this->base().str_replace('{id}',rawurlencode($mid),get_option('evg_contact_details_path','/api/v3.0/contact-details/{id}')); }
                $resp = evg_http_get($durl,$this->headers()); $calls++; $this->rate_sleep();
                // 429 trotz Retries: Mitglied nicht überspringen, im nächsten Tick erneut versuchen
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp)===429) break;
                if (!is_wp_error($resp) && ($c=wp_remote_retrieve_response_code($resp))>=200 && $c<300){
                    $raw_body = wp_remote_retrieve_body($resp);
                    $d = json_decode($raw_body,true);
                    if (is_array($d)) $this->apply_contact_details($mid,$d);
                    $s['counts']['details']++;
                }
                $s['member_index']++;

            // ── PHASE: member_cf (per-member custom fields) ──────────────────
            } elseif ($s['phase'] === 'member_cf') {
                if ($s['member_index'] >= count($s['member_ids'])){
                    $s['phase'] = 'member_groups';
                    $s['member_index'] = 0;
                    continue;
                }
                $mid  = $s['member_ids'][$s['member_index']];
                $cf_path = get_option('evg_member_custom_fields_path','/api/v3.0/member-custom-field-assignment?user_object={id}');
                $url  = $this->normalize_endpoint(str_replace('{id}',rawurlencode($mid),$cf_path));
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)){ $s['member_index']++; continue; }
                $code = wp_remote_retrieve_response_code($resp);
                if ($code===429) break; // im nächsten Tick dasselbe Mitglied erneut versuchen
                if ($code<200||$code>=300){ $s['member_index']++; continue; }
                $payload = json_decode(wp_remote_retrieve_body($resp),true);
                $items   = $this->extract_items_from_payload($payload);
                $now     = current_time('mysql',1);
                foreach ((array)$items as $entry){
                    if (!is_array($entry)) continue;
                    $res = $this->store_member_cf_entry($entry,$s,$calls,$now);
                    if ($res === null || !$res['stored']) continue;
                    $s['counts']['member_custom_fields']++;
                    if ($res['new_value']){
                        $s['counts']['custom_field_values']++;
                        if ($s['counts']['custom_field_values'] > ($s['est']['custom_field_values']??1)) $s['est']['custom_field_values']=$s['counts']['custom_field_values'];
                    }
                }
                $s['member_index']++;

            // ── PHASE: member_groups (per-member groups) ─────────────────────
            } elseif ($s['phase'] === 'member_groups') {
                if ($s['member_index'] >= count($s['member_ids'])){
                    $s['done'] = true;
                    update_option('evg_last_sync_completed', current_time('mysql',1), false);
                    // Vollständiger Lauf (ohne Cap) = neuer Ausgangspunkt für den Delta-Sync dieser Tabellen
                    if (empty($s['cap']) && !empty($s['started_at'])) self::set_delta_since($this->table_prefix,(int)$s['started_at']);
                    break;
                }
                $mid  = $s['member_ids'][$s['member_index']];
                $mg_path = get_option('evg_member_groups_path','/api/v3.0/member-group-assignment?user_object={id}');
                $url  = $this->normalize_endpoint(str_replace('{id}',rawurlencode($mid),$mg_path));
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)){ $s['member_index']++; continue; }
                $code = wp_remote_retrieve_response_code($resp);
                if ($code===429) break; // im nächsten Tick dasselbe Mitglied erneut versuchen
                if ($code<200||$code>=300){ $s['member_index']++; continue; }
                $arr   = json_decode(wp_remote_retrieve_body($resp),true);
                $items = $this->extract_items_from_payload($arr);
                $now   = current_time('mysql',1);
                $row   = $wpdb->get_row($wpdb->prepare("SELECT first_name,family_name FROM $m_table WHERE member_id=%s LIMIT 1",$mid),ARRAY_A);
                $member_name = $row ? trim(($row['first_name']??'').' '.($row['family_name']??'')) : '';
                foreach ((array)$items as $g){
                    if (!is_array($g)) continue;
                    $g_mid = $this->extract_member_id_from_ref($g['user_object'] ?? null);
                    if (!$g_mid) continue;
                    $gid = $this->group_id_from_assignment($g);
                    if (!$gid) continue;
                    $group_name = (string)($wpdb->get_var($wpdb->prepare("SELECT name FROM $g_table WHERE group_id=%s LIMIT 1",$gid)) ?? '');
                    $wpdb->replace($x_table,['member_id'=>$g_mid,'group_id'=>$gid,'member_name'=>$member_name,'group_name'=>$group_name,'assigned_at'=>$now],['%s','%s','%s','%s','%s']);
                    $s['counts']['member_groups']++;
                }
                $s['member_index']++;

            } else {
                return ['ok'=>false,'summary'=>'Unbekannte Phase: '.($s['phase']??'none')];
            }
        }
        $this->put_state($s);
        return array_merge(['ok'=>true], $this->progress($s));
    }

    // ── Delta-Sync ───────────────────────────────────────────────────────────
    //
    // Statt jedes Mitglied einzeln abzufragen (~3 Aufrufe pro Mitglied) nutzt der
    // Delta-Sync die Sammel-Endpunkte der API v3.0:
    //   • geänderte Datensätze über updated_at__gte (Mitglieder, Kontaktdaten, Custom Fields)
    //   • schlanke ID-Listen (query={…}), um Löschungen und Gruppen-Zuordnungen abzugleichen
    // Ein Lauf braucht so rund 70–100 Aufrufe statt mehrerer Tausend.

    public static function delta_since_option($prefix){
        return 'evg_delta_since_'.evg_sanitize_table_prefix($prefix);
    }
    public static function get_delta_since($prefix){
        return (int)get_option(self::delta_since_option($prefix),0);
    }
    public static function set_delta_since($prefix,$ts){
        update_option(self::delta_since_option($prefix),(int)$ts,false);
    }
    public static function delta_result_option($prefix){
        return 'evg_delta_last_result_'.evg_sanitize_table_prefix($prefix);
    }

    private function short_url($url){
        return preg_replace('~^https?://[^/]+~','',(string)$url);
    }

    /** GET mit zusätzlicher Wartezeit bei anhaltendem 429; liefert dekodiertes JSON oder WP_Error. */
    private function delta_get($url,$deadline){
        for ($round=0; $round<4; $round++){
            $resp = evg_http_get($url,$this->headers()); $this->delta_calls++; $this->rate_sleep();
            if (is_wp_error($resp)) return $resp;
            $code = wp_remote_retrieve_response_code($resp);
            if ($code === 429){
                if (microtime(true) + 20 > $deadline) break;
                sleep(20);
                continue;
            }
            if ($code<200 || $code>=300) return new WP_Error('evg_http','HTTP '.$code.' bei '.$this->short_url($url));
            $body = json_decode(wp_remote_retrieve_body($resp),true);
            if (!is_array($body)) return new WP_Error('evg_json','Ungültige Antwort bei '.$this->short_url($url));
            return $body;
        }
        return new WP_Error('evg_rate','Rate-Limit (429) bei '.$this->short_url($url));
    }

    /** Alle Seiten eines Sammel-Endpunkts laden; liefert die Einträge oder WP_Error (nie eine Teilliste). */
    private function delta_list($path,array $params,$deadline){
        $url = $this->normalize_endpoint($path);
        $params = array_merge(['limit'=>100],$params);
        $url .= (strpos($url,'?')===false ? '?' : '&').http_build_query($params,'','&',PHP_QUERY_RFC3986);
        $items = []; $pages = 0;
        while ($url){
            if (microtime(true) > $deadline) return new WP_Error('evg_time','Zeitbudget überschritten');
            if (++$pages > 500) return new WP_Error('evg_pages','Zu viele Seiten bei '.$this->short_url($path));
            $body = $this->delta_get($url,$deadline);
            if (is_wp_error($body)) return $body;
            foreach ($this->extract_items_from_payload($body) as $it){
                if (is_array($it)) $items[] = $it;
            }
            $url = $this->next_url($this->extract_next_from_payload($body));
        }
        return $items;
    }

    /** Zeilen löschen, deren Schlüssel in $where_sets (Liste von [spalte=>wert]) steht. */
    private function delete_rows($table,array $where_sets){
        global $wpdb;
        $n = 0;
        foreach ($where_sets as $where){
            $n += (int)$wpdb->delete($table,$where);
        }
        return $n;
    }

    /** Mitglieder samt Zuordnungen löschen. */
    private function delete_members(array $member_ids){
        global $wpdb;
        foreach (array_chunk($member_ids,200) as $chunk){
            $ph = implode(',',array_fill(0,count($chunk),'%s'));
            foreach (['members','member_groups','member_custom_fields'] as $t){
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query($wpdb->prepare("DELETE FROM {$this->table($t)} WHERE member_id IN ($ph)",$chunk));
            }
        }
    }

    /**
     * Delta-Sync in die Tabellen dieses Präfixes.
     * Ändert nur, was sich seit dem letzten erfolgreichen Lauf (bzw. vollen Sync) geändert hat,
     * und gleicht Löschungen über ID-Listen ab. Bricht bei Fehlern ab, ohne den Zeitstempel
     * weiterzusetzen – der nächste Lauf holt dann alles seit demselben Stand nach.
     */
    public function delta_run($time_budget=600){
        $t0 = microtime(true);
        $deadline = $t0 + max(60,(int)$time_budget);
        $this->delta_calls = 0;
        $lock = 'evg_delta_lock_'.$this->table_prefix;

        $st = $this->get_state();
        if (!empty($st) && empty($st['done']) && (time() - (int)($st['started_at'] ?? 0)) < 3 * HOUR_IN_SECONDS){
            $res = ['ok'=>false,'skipped'=>true,'message'=>'Übersprungen: voller Sync für diese Tabellen läuft gerade'];
        } elseif (get_transient($lock)){
            $res = ['ok'=>false,'skipped'=>true,'message'=>'Übersprungen: Delta-Sync läuft bereits'];
        } else {
            set_transient($lock,1,20 * MINUTE_IN_SECONDS);
            try {
                $res = $this->delta_run_inner($deadline);
            } finally {
                delete_transient($lock);
            }
        }
        $res['prefix']   = $this->table_prefix;
        $res['seconds']  = round(microtime(true) - $t0,1);
        $res['calls']    = $this->delta_calls;
        $res['finished'] = time();
        if (empty($res['skipped'])) update_option(self::delta_result_option($this->table_prefix),$res,false);
        return $res;
    }

    private function delta_run_inner($deadline){
        global $wpdb;
        $this->ensure_custom_field_schema();
        $run_start = time();
        $now = current_time('mysql',1);

        $m_table  = $this->table('members');
        $g_table  = $this->table('groups');
        $x_table  = $this->table('member_groups');
        $mv_table = $this->table('member_custom_fields');
        $cv_table = $this->table('custom_field_values');

        $local_members = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$m_table}");
        if ($local_members === 0){
            return ['ok'=>false,'message'=>'Tabellen sind leer – zuerst einen vollständigen Sync ausführen'];
        }
        $since_ts = self::get_delta_since($this->table_prefix);
        if ($since_ts <= 0) $since_ts = $run_start - 3 * DAY_IN_SECONDS;
        // 10 Minuten Überlappung, damit nichts zwischen zwei Läufen verloren geht
        $since = gmdate('Y-m-d\TH:i:s\Z',$since_ts - 10 * MINUTE_IN_SECONDS);

        $s = ['custom_field_types'=>[],'custom_field_labels'=>[],'select_option_labels'=>[],'custom_field_values_seen'=>[]];
        $stats = [];
        $fail = function($err) use (&$stats){
            return ['ok'=>false,'message'=>is_wp_error($err) ? $err->get_error_message() : (string)$err,'stats'=>$stats];
        };

        // 1) Gruppen (wenige Seiten) – vollständig upserten, entfernte Gruppen löschen
        $groups = $this->delta_list(get_option('evg_groups_path','/api/v3.0/member-group'),[],$deadline);
        if (is_wp_error($groups)) return $fail($groups);
        $remote_groups = [];
        foreach ($groups as $g){
            $gid = $this->upsert_group($g,$now);
            if ($gid !== null) $remote_groups[$gid] = true;
        }
        $stats['groups'] = count($remote_groups);
        $stats['groups_deleted'] = 0;
        if ($remote_groups){
            $gone = array_diff($wpdb->get_col("SELECT group_id FROM {$g_table}"),array_keys($remote_groups));
            $stats['groups_deleted'] = $this->delete_rows($g_table,array_map(function($id){ return ['group_id'=>$id]; },$gone));
        }

        // 2) Custom-Field-Definitionen (für Labels/Typen)
        $fields = $this->delta_list(get_option('evg_custom_fields_path','/api/v3.0/custom-field'),[],$deadline);
        if (is_wp_error($fields)) return $fail($fields);
        foreach ($fields as $f) $this->upsert_custom_field($f,$s,$now);
        $stats['custom_fields'] = count($s['custom_field_labels']);

        // 3) Alle Mitglieds-IDs → gelöschte Mitglieder erkennen
        $members_path = get_option('evg_members_path','/api/v3.0/member');
        $id_rows = $this->delta_list($members_path,['query'=>'{id}'],$deadline);
        if (is_wp_error($id_rows)) return $fail($id_rows);
        $remote_ids = [];
        foreach ($id_rows as $r){ if (isset($r['id'])) $remote_ids[(string)$r['id']] = true; }
        if (count($remote_ids) < 0.5 * $local_members){
            return $fail(sprintf('Mitgliederliste unplausibel kurz (%d statt ca. %d) – Abbruch ohne Löschungen',count($remote_ids),$local_members));
        }
        $existing = array_fill_keys($wpdb->get_col("SELECT member_id FROM {$m_table}"),true);
        $removed  = array_keys(array_diff_key($existing,$remote_ids));
        if ($removed) $this->delete_members($removed);
        $stats['members_total']   = count($remote_ids);
        $stats['members_deleted'] = count($removed);

        // 4) Geänderte Mitglieder (Mitgliedsnummer, Verweis auf Kontaktdaten, neue Mitglieder)
        $changed = $this->delta_list($members_path,['updated_at__gte'=>$since],$deadline);
        if (is_wp_error($changed)) return $fail($changed);
        $changed_ids = [];
        foreach ($changed as $m){
            $mid = $this->upsert_member_row($m,$now);
            if ($mid !== null) $changed_ids[$mid] = true;
        }
        $new_ids = array_diff_key($changed_ids,$existing);
        $stats['members_changed'] = count($changed_ids);
        $stats['members_new']     = count($new_ids);

        // 5) Geänderte Kontaktdaten – nur die benötigten Felder (keine Bankdaten)
        $cd_query = '{id,member,first_name,family_name,age,date_of_birth,private_email,primary_email,salutation,zip,city,street,address_suffix,private_phone,mobile_phone,company_phone}';
        $cd_path  = $this->bulk_url_from_path('evg_contact_details_path','/api/v3.0/contact-details/{id}');
        $cds = $this->delta_list($cd_path,['updated_at__gte'=>$since,'query'=>$cd_query],$deadline);
        if (is_wp_error($cds)) return $fail($cds);
        $cd_map = [];
        foreach ($wpdb->get_results("SELECT member_id,contact_details FROM {$m_table}",ARRAY_A) as $r){
            $cid = $this->extract_cd_id($r['contact_details']);
            if ($cid) $cd_map[$cid] = $r['member_id'];
        }
        $cd_done = [];
        foreach ($cds as $d){
            $mid = $this->extract_member_id_from_ref($d['member'] ?? null);
            if (!$mid || !isset($remote_ids[$mid])) $mid = $cd_map[(string)($d['id'] ?? '')] ?? null;
            if (!$mid) continue; // Kontakt ohne Mitgliedschaft
            $this->apply_contact_details($mid,$d);
            $cd_done[$mid] = true;
        }
        // Neue Mitglieder, deren Kontaktdaten nicht frisch geändert wurden, einzeln nachladen
        foreach (array_keys($new_ids) as $mid){
            if (isset($cd_done[$mid])) continue;
            $cd = (string)$wpdb->get_var($wpdb->prepare("SELECT contact_details FROM {$m_table} WHERE member_id=%s",$mid));
            if ($cd === '') continue;
            $url = (strpos($cd,'http')===0 ? $cd : $this->base().$cd);
            $d = $this->delta_get($url.(strpos($url,'?')===false?'?':'&').'query='.rawurlencode($cd_query),$deadline);
            if (is_wp_error($d)) return $fail($d);
            $this->apply_contact_details($mid,$d);
            $cd_done[$mid] = true;
        }
        $stats['contacts_updated'] = count($cd_done);

        // 6) Gruppen-Zuordnungen: schlanke Gesamtliste → Tabelle abgleichen
        $mg_path = $this->bulk_url_from_path('evg_member_groups_path','/api/v3.0/member-group-assignment?user_object={id}');
        $assign  = $this->delta_list($mg_path,['query'=>'{id,user_object,member_group}'],$deadline);
        if (is_wp_error($assign)) return $fail($assign);
        $remote_pairs = [];
        foreach ($assign as $a){
            $mid = $this->extract_member_id_from_ref($a['user_object'] ?? null);
            $gid = $this->group_id_from_assignment($a);
            if ($mid && $gid && isset($remote_ids[$mid])) $remote_pairs[$mid.'|'.$gid] = [$mid,$gid];
        }
        $local_pairs = [];
        foreach ($wpdb->get_results("SELECT member_id,group_id FROM {$x_table}",ARRAY_A) as $r){
            $local_pairs[$r['member_id'].'|'.$r['group_id']] = [$r['member_id'],$r['group_id']];
        }
        if (count($remote_pairs) < 0.5 * count($local_pairs)){
            return $fail(sprintf('Gruppen-Zuordnungen unplausibel wenige (%d statt ca. %d) – Abbruch ohne Löschungen',count($remote_pairs),count($local_pairs)));
        }
        $add = array_diff_key($remote_pairs,$local_pairs);
        $del = array_diff_key($local_pairs,$remote_pairs);
        if ($add){
            $names = [];
            foreach ($wpdb->get_results("SELECT member_id,first_name,family_name FROM {$m_table}",ARRAY_A) as $r){
                $names[$r['member_id']] = trim(($r['first_name'] ?? '').' '.($r['family_name'] ?? ''));
            }
            $gnames = [];
            foreach ($wpdb->get_results("SELECT group_id,name FROM {$g_table}",ARRAY_A) as $r) $gnames[$r['group_id']] = (string)$r['name'];
            foreach ($add as list($mid,$gid)){
                $wpdb->replace($x_table,['member_id'=>$mid,'group_id'=>$gid,'member_name'=>$names[$mid] ?? '','group_name'=>$gnames[$gid] ?? '','assigned_at'=>$now],['%s','%s','%s','%s','%s']);
            }
        }
        $this->delete_rows($x_table,array_map(function($p){ return ['member_id'=>$p[0],'group_id'=>$p[1]]; },array_values($del)));
        // Namen in den Zuordnungen nachziehen (geänderte Kontaktdaten / umbenannte Gruppen)
        $wpdb->query("UPDATE {$x_table} x JOIN {$m_table} m ON m.member_id=x.member_id
                      SET x.member_name=TRIM(CONCAT(IFNULL(m.first_name,''),' ',IFNULL(m.family_name,'')))
                      WHERE x.member_name <> TRIM(CONCAT(IFNULL(m.first_name,''),' ',IFNULL(m.family_name,'')))");
        $wpdb->query("UPDATE {$x_table} x JOIN {$g_table} g ON g.group_id=x.group_id SET x.group_name=g.name WHERE x.group_name <> g.name");
        $stats['group_assignments']         = count($remote_pairs);
        $stats['group_assignments_added']   = count($add);
        $stats['group_assignments_removed'] = count($del);

        // 7) Custom-Field-Werte: geänderte Zuordnungen schreiben, entfernte über die ID-Liste löschen
        $mcf_path = $this->bulk_url_from_path('evg_member_custom_fields_path','/api/v3.0/member-custom-field-assignment?user_object={id}');
        $cf_changed = $this->delta_list($mcf_path,['updated_at__gte'=>$since],$deadline);
        if (is_wp_error($cf_changed)) return $fail($cf_changed);
        $opt_calls = 0; $cf_written = 0; $cf_cleared = 0;
        foreach ($cf_changed as $e){
            $mid = $this->extract_member_id_from_ref($e['user_object'] ?? null);
            if (!$mid || !isset($remote_ids[$mid])) continue;
            $res = $this->store_member_cf_entry($e,$s,$opt_calls,$now);
            if ($res === null) continue;
            if ($res['stored']) { $cf_written++; continue; }
            $cf_cleared += (int)$wpdb->delete($mv_table,['member_id'=>$res['mid'],'field_id'=>$res['fid']]);
        }
        $this->delta_calls += $opt_calls;

        $cf_all = $this->delta_list($mcf_path,['query'=>'{id,user_object,custom_field}'],$deadline);
        if (is_wp_error($cf_all)) return $fail($cf_all);
        $remote_cf = [];
        foreach ($cf_all as $e){
            $mid = $this->extract_member_id_from_ref($e['user_object'] ?? null);
            $fid = $this->field_id_from_assignment($e);
            if ($mid && $fid) $remote_cf[$mid.'|'.$fid] = true;
        }
        $local_cf = [];
        foreach ($wpdb->get_results("SELECT member_id,field_id FROM {$mv_table}",ARRAY_A) as $r){
            $local_cf[$r['member_id'].'|'.$r['field_id']] = [$r['member_id'],$r['field_id']];
        }
        if (count($remote_cf) < 0.5 * count($local_cf)){
            return $fail(sprintf('Custom-Field-Zuordnungen unplausibel wenige (%d statt ca. %d) – Abbruch ohne Löschungen',count($remote_cf),count($local_cf)));
        }
        $cf_gone = array_diff_key($local_cf,$remote_cf);
        $cf_cleared += $this->delete_rows($mv_table,array_map(function($p){ return ['member_id'=>$p[0],'field_id'=>$p[1]]; },array_values($cf_gone)));
        // Werte-Katalog (Filteroptionen) von nicht mehr verwendeten Einträgen befreien
        $wpdb->query("DELETE cv FROM {$cv_table} cv LEFT JOIN {$mv_table} mv ON mv.field_id=cv.field_id AND mv.value_hash=cv.value_hash WHERE mv.member_id IS NULL");
        $stats['custom_values_written'] = $cf_written;
        $stats['custom_values_removed'] = $cf_cleared;

        self::set_delta_since($this->table_prefix,$run_start);
        update_option('evg_last_delta_sync',current_time('mysql',1),false);

        $msg = sprintf('%d Mitglieder (%d geändert, %d neu, %d gelöscht), %d Kontaktdaten, Gruppen +%d/−%d, Zusatzfelder %d geschrieben/%d entfernt',
            $stats['members_total'],$stats['members_changed'],$stats['members_new'],$stats['members_deleted'],
            $stats['contacts_updated'],$stats['group_assignments_added'],$stats['group_assignments_removed'],
            $cf_written,$cf_cleared);
        return ['ok'=>true,'message'=>$msg,'since'=>$since,'stats'=>$stats];
    }
}
