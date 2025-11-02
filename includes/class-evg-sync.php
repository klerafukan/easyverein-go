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
    private function calls_per_tick(){ return max(1,(int)get_option('evg_sync_calls_per_tick',5)); }
    private function pages_max(){ return max(1,(int)get_option('evg_sync_next_pages_max',100)); }
    private function rate_sleep(){ $r=max(1,(int)get_option('evg_sync_rate_per_sec',5)); usleep((int)(1000000 / max(1,$r))); }
    private function get_state(){ $s=get_option($this->state_key); return is_array($s)?$s:[]; }
    private function put_state($s){ update_option($this->state_key,$s,false); }
    private function clear_state(){ delete_option($this->state_key); }

    public function job_start($cap=0){
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE '.$this->table('member_groups'));
        $wpdb->query('TRUNCATE TABLE '.$this->table('members'));
        $wpdb->query('TRUNCATE TABLE '.$this->table('groups'));
        $skip = (int) get_option('evg_sync_skip_groups', 0 );
        $state=[
            'phase'=>'groups',
            'skip_groups'=>($skip?1:0),
            'pages'=>['groups'=>0,'members_list'=>0],
            'counts'=>['groups'=>0,'members_list'=>0,'details'=>0,'member_groups'=>0],
            'est'=>['groups'=>1,'members_list'=>1,'details'=>0,'member_groups'=>($skip?0:1)],
            'next'=>[ 
                'groups'=>$this->base().get_option('evg_groups_path','/api/v2.0/member-group'),
                'members_list'=>$this->base().get_option('evg_members_path','/api/v2.0/member') 
            ],
            'member_ids'=>[],
            'member_index'=>0,
            'cap'=>max(0,(int)$cap),
            'done'=>false
        ];
        $this->put_state($state);
    }

    private function progress($s){
        $only = !empty($s['skip_groups']);
        $weights = $only ? ['groups'=>0.1,'members_list'=>0.45,'details'=>0.45] : ['groups'=>0.1,'members_list'=>0.4,'details'=>0.4,'member_groups'=>0.1];
        $p = 0.0;
        foreach($weights as $k=>$weight){
            $est = max(1, isset($s['est'][$k]) ? $s['est'][$k] : 1);
            $cnt = isset($s['counts'][$k]) ? $s['counts'][$k] : 0;
            $p += $weight * min(1.0, $cnt / $est);
        }
        $label  = ($only ? '(ohne Gruppen) ' : '');
        $label .= (isset($s['phase']) ? $s['phase'] : '');
        $label .= ' – G:' . (isset($s['counts']['groups']) ? (string)$s['counts']['groups'] : '0');
        $label .= ' M:' . (isset($s['counts']['members_list']) ? (string)$s['counts']['members_list'] : '0');
        $label .= ' D:' . (isset($s['counts']['details']) ? (string)$s['counts']['details'] : '0');
        $label .= ' L:' . (isset($s['counts']['member_groups']) ? (string)$s['counts']['member_groups'] : '0');
        return ['percent'=>100*$p,'label'=>$label,'done'=>!empty($s['done'])];
    }

    public function job_tick(){
        global $wpdb;
        $s = $this->get_state();
        if (empty($s)) return ['ok'=>false,'summary'=>'Kein aktiver Job'];
        if (!empty($s['done'])) return ['ok'=>true,'done'=>true,'percent'=>100,'label'=>'Fertig'];

        $m_table=$this->table('members');
        $g_table=$this->table('groups');
        $x_table=$this->table('member_groups');

        $calls=0; $max=$this->calls_per_tick();
        while($calls<$max){
            if($s['phase']==='groups'){
                $url=$s['next']['groups'];
                if(!$url || $s['pages']['groups'] >= $this->pages_max()){
                    $s['phase']='members_list';
                    $s['est']['members_list']=max(1,count($s['member_ids']));
                    continue;
                }
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)) return ['ok'=>false,'summary'=>$resp->get_error_message()];
                $code=wp_remote_retrieve_response_code($resp);
                if ($code<200||$code>=300) return ['ok'=>false,'summary'=>'Groups HTTP '.$code];
                $body=json_decode(wp_remote_retrieve_body($resp),true);
                $items=[]; $next=null;
                if (is_array($body)){
                    if (array_keys($body)===range(0,count($body)-1)) $items=$body;
                    else { $items=$body['results']??$body['data']??$body['items']??[]; $next=$body['next']??null; }
                }
                $now=current_time('mysql',1);
                foreach((array)$items as $g){
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
            elseif($s['phase']==='members_list'){
                $url=$s['next']['members_list'];
                if(!$url || $s['pages']['members_list'] >= $this->pages_max()){
                    $s['phase']='details';
                    $s['member_index']=0;
                    $s['est']['details']=max(1,count($s['member_ids']));
                    continue;
                }
                $resp = evg_http_get($url,$this->headers()); $calls++; $this->rate_sleep();
                if (is_wp_error($resp)) return ['ok'=>false,'summary'=>$resp->get_error_message()];
                $code=wp_remote_retrieve_response_code($resp);
                if ($code<200||$code>=300) return ['ok'=>false,'summary'=>'Members HTTP '.$code];
                $body=json_decode(wp_remote_retrieve_body($resp),true);
                $items=[]; $next=null;
                if (is_array($body)){
                    if (array_keys($body)===range(0,count($body)-1)) $items=$body;
                    else { $items=$body['results']??$body['data']??$body['items']??[]; $next=$body['next']??null; }
                }
                $now=current_time('mysql',1);
                foreach((array)$items as $m){
                    $mid = isset($m['id']) ? $m['id'] : (isset($m['memberId']) ? $m['memberId'] : (isset($m['uuid']) ? $m['uuid'] : null));
                    $mno = isset($m['memberNumber']) ? $m['memberNumber'] : (isset($m['membershipNumber']) ? $m['membershipNumber'] : (isset($m['number']) ? $m['number'] : ''));
                    $cd  = '';
                    if (isset($m['contactDetails']) && is_string($m['contactDetails'])) { $cd = $m['contactDetails']; }
                    elseif (isset($m['contact_details']) && is_string($m['contact_details'])) { $cd = $m['contact_details']; }
                    elseif (isset($m['contactDetails']) && is_array($m['contactDetails']) && isset($m['contactDetails']['href'])) { $cd = $m['contactDetails']['href']; }
                    elseif (isset($m['contactDetailsId'])) { $cd = '/api/v2.0/contact-details/'.intval($m['contactDetailsId']); }
                    elseif (isset($m['contactId'])) { $cd = '/api/v2.0/contact-details/'.intval($m['contactId']); }
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
                    $s['phase']='details'; $s['member_index']=0; $s['est']['details']=count($s['member_ids']);
                }
            }
            elseif($s['phase']==='details'){
                if ($s['member_index'] >= count($s['member_ids'])){
                    if (!empty($s['skip_groups'])) { $s['done']=true; break; }
                    $s['phase']='member_groups';
                    $s['member_index']=0;
                    $s['est']['member_groups']=max(1,count($s['member_ids']));
                    continue;
                }
                $mid=$s['member_ids'][$s['member_index']];
                $cd = $wpdb->get_var( $wpdb->prepare("SELECT contact_details FROM {$m_table} WHERE member_id=%s LIMIT 1", $mid) );
                if ($cd && strpos($cd,'http')===0) { $durl=$cd; }
                elseif ($cd) { $durl=$this->base().$cd; }
                else { $durl=$this->base().str_replace('{id}',rawurlencode($mid),get_option('evg_contact_details_path','/api/v2.0/contact-details/{id}')); }
                $resp=evg_http_get($durl,$this->headers()); $calls++; $this->rate_sleep();
                if (!is_wp_error($resp) && ( $c=wp_remote_retrieve_response_code($resp) )>=200 && $c<300){
                    $d=json_decode(wp_remote_retrieve_body($resp),true);
                    if(is_array($d)){
                        $first = isset($d['firstName']) ? $d['firstName'] : (isset($d['first_name']) ? $d['first_name'] : '');
                        $family= isset($d['familyName']) ? $d['familyName'] : (isset($d['lastName']) ? $d['lastName'] : (isset($d['last_name']) ? $d['last_name'] : ''));
                        $dob   = isset($d['dateOfBirth']) ? $d['dateOfBirth'] : (isset($d['birthDate']) ? $d['birthDate'] : null);
                        $age   = isset($d['age']) ? intval($d['age']) : null;
                        $pemail= isset($d['privateEmail']) ? $d['privateEmail'] : (isset($d['emailPrivate']) ? $d['emailPrivate'] : (isset($d['email']) ? $d['email'] : ''));
                        $zip   = isset($d['zip']) ? $d['zip'] : (isset($d['postalCode']) ? $d['postalCode'] : '');
                        $city  = isset($d['city']) ? $d['city'] : '';
                        $street= isset($d['street']) ? $d['street'] : '';
                        $addrS = isset($d['addressSuffix']) ? $d['addressSuffix'] : (isset($d['addressAddition']) ? $d['addressAddition'] : '');
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
                            'phone','privatePhone','phonePrivate','telephone','telephoneNumber','privateTelephone',
                            'mobilePhone','mobile','mobilePrivate','mobileTelephone','phoneMobile','phoneHome','homePhone'
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
                            'date_of_birth=%s',
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
                        $params = [$first,$family,$dob,$age,$pemail,$zip,$city,$street,$addrS,$gender,$phone,$now_detail];
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
            else {
                if ($s['member_index'] >= count($s['member_ids'])) { $s['done']=true; break; }
                $mid=$s['member_ids'][$s['member_index']];
                $gurl=$this->base().str_replace('{id}',rawurlencode($mid),get_option('evg_member_groups_path','/api/v2.0/member/{id}/groups'));
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
                        // Prefer the real group id from the memberGroup URL (e.g. .../api/v2.0/member-group/266553457)
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
        }
        $this->put_state($s);
        return array_merge(['ok'=>true], $this->progress($s));
    }
}
