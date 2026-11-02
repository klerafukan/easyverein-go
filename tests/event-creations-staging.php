<?php
/** Isolierte WordPress-Integration. Keine echten EasyVerein-Aufrufe, eigene Tabellen/Konten. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='staging') WP_CLI::error('Nur auf Staging ausführen.');
if (class_exists('EVG_Events_Sync')) WP_CLI::error('Mit --skip-plugins ausführen, damit exakt die Testquelle geladen wird.');
require $args[0].'/class-evg-events-sync.php'; require $args[0].'/class-evg-event-writes.php';
class EVG_Plugin { public static function live_table_prefix(){ return 'evg'; } }
function evg_api_throttle() {}
function evg_http_get($url,$headers){ return wp_remote_get($url,['headers'=>$headers]); }
function evg_debug_dir(){ return sys_get_temp_dir(); }
add_filter('pre_option_evg_api_key',function(){return 'isolated-test-key';});
add_filter('pre_option_evg_events_calendar_filter',function(){return '';});
add_filter('pre_wp_mail','__return_true');
$events=[]; $posts=0; $fail_read=false; $lost_response=false; $reject=false; $block_get=false;
add_filter('pre_http_request',function($pre,$opts,$url)use(&$events,&$posts,&$fail_read,&$lost_response,&$reject){
    $response=function($body,$code=200){return ['body'=>wp_json_encode($body),'response'=>['code'=>$code],'headers'=>[],'cookies'=>[]];};
    if (!str_contains($url,'/api/v3.0/')) return new WP_Error('blocked','Alle externen Aufrufe im Test blockiert.');
    $path=parse_url($url,PHP_URL_PATH); parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
    if (($opts['method']??'GET')==='POST') {
        $posts++; if ($reject) return $response(['error'=>'invalid'],400);
        $body=json_decode($opts['body'],true); $id=900000+$posts; $body['id']=$id;
        $events[$id]=$body;
        return $lost_response ? new WP_Error('timeout','Antwort verloren') : $response($body,201);
    }
    if ($fail_read) return new WP_Error('offline','Nachlesen ausgefallen');
    if (str_ends_with($path,'/calendar')) return $response(['results'=>[['id'=>11,'name'=>'Jugend','allowed_groups'=>[101]],['id'=>12,'name'=>'Erwachsene','allowed_groups'=>[102]],['id'=>13,'name'=>'Verein','allowed_groups'=>[]]],'next'=>null]);
    if (str_ends_with($path,'/location')) return $response(['results'=>[],'next'=>null]);
    if (preg_match('#/event/(\d+)$#',$path,$m)) return isset($events[$m[1]])?$response($events[$m[1]]):$response([],404);
    $items=array_values($events);
    if (isset($query['calendar'])) $items=array_values(array_filter($items,function($e)use($query){return (int)$e['calendar']===(int)$query['calendar'];}));
    if (isset($query['start'])) $items=array_values(array_filter($items,function($e)use($query){return $e['start']===$query['start'];}));
    return $response(['results'=>$items,'next'=>null]);
},999,3);
global $wpdb; $original=$wpdb->prefix; $prefix=$original.'ectest_'.substr(md5(wp_generate_uuid4()),0,8).'_';
$tables=['evg_calendars','evg_locations','evg_events','evg_event_writes','evg_member_groups']; $users=[];$checks=0;$fails=0;
$options=['evg_events_schema','evg_event_writes_schema','evg_events_synced_at',EVG_Events_Sync::RESULT_OPTION];$old=[];foreach($options as $key)$old[$key]=get_option($key,null);
$check=function($yes,$label)use(&$checks,&$fails){$checks++;if(!$yes)$fails++;WP_CLI::line(($yes?'ok ':'not ok ').$checks.' - '.$label);};
$api=function($uid,$method,$path,$body=[]) {wp_set_current_user($uid);$r=new WP_REST_Request($method,'/easyverein-go/v1'.$path);if($method==='POST'){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}$res=rest_do_request($r);return [$res->get_status(),$res->get_data()];};
try {
    $wpdb->prefix=$prefix;
    EVG_Events_Sync::ensure_tables(); EVG_Event_Writes::ensure_table();
    $wpdb->query("CREATE TABLE {$prefix}evg_member_groups (member_id varchar(50),group_id varchar(50))");
    foreach(['trainer','member','other','admin'] as $role){$id=wp_insert_user(['user_login'=>'ectest_'.$role.'_'.substr(md5($prefix),0,8),'user_pass'=>wp_generate_password(40),'role'=>$role==='admin'?'administrator':'subscriber']);if(is_wp_error($id))throw new Exception('Testkonto fehlt.');$users[$role]=(int)$id;update_user_meta($id,'evg_member_id',(string)$id);}
    foreach(['trainer','member']as $role)$wpdb->insert($prefix.'evg_member_groups',['member_id'=>(string)$users[$role],'group_id'=>'101']);
    update_user_meta($users['trainer'],'evg_is_uebungsleiter',1);
    $wpdb->insert($prefix.'evg_calendars',['id'=>11,'name'=>'Jugend','allowed_groups'=>'101','synced_at'=>current_time('mysql')]);
    $wpdb->insert($prefix.'evg_calendars',['id'=>12,'name'=>'Erwachsene','allowed_groups'=>'102','synced_at'=>current_time('mysql')]);
    $wpdb->insert($prefix.'evg_calendars',['id'=>13,'name'=>'Verein','allowed_groups'=>'','synced_at'=>current_time('mysql')]);
    $server=rest_get_server();$writes=new EVG_Event_Writes();$writes->routes();
    $day=wp_date('Y-m-d',strtotime('+2 days'));
    $input=['request_id'=>wp_generate_uuid4(),'name'=>'Training','calendar_id'=>'11','start'=>$day.'T18:00:00','end'=>$day.'T19:30:00','all_day'=>false,'description'=>'Testbeschreibung','location_name'=>'Halle'];
    $check($api(0,'POST','/event-creations',$input)[0]===401,'Anonyme dürfen keine Termine anlegen');
    $check($api($users['member'],'POST','/event-creations',$input)[0]===403 && $posts===0,'Gruppenmitgliedschaft allein ist kein Schreibrecht');
    $check(array_column($api($users['trainer'],'GET','/event-creation-options')[1]['calendars'],'calendar_id')===['11'],'Übungsleiter erhalten nur Kalender ihrer Gruppen');
    $check(count($api($users['admin'],'GET','/event-creation-options')[1]['calendars'])===3,'Administratoren erhalten alle Kalender');
    $bad=$input;$bad['calendar_id']='12';$check($api($users['trainer'],'POST','/event-creations',$bad)[0]===403 && !$posts,'Fremde Kalender serverseitig gesperrt');
    $bad=$input;$bad['end']=$bad['start'];$check($api($users['trainer'],'POST','/event-creations',$bad)[0]===400,'Ende muss nach Beginn liegen');
    $bad=$input;$bad['start']='2026-02-30T18:00:00';$check($api($users['trainer'],'POST','/event-creations',$bad)[0]===400,'Ungültige Datumswerte werden abgewiesen');
    [$code,$result]=$api($users['trainer'],'POST','/event-creations',$input);$eid=$result['event_id']??0;
    $check($code===200 && $result['status']==='confirmed' && $eid,'Erfolg erst nach EasyVerein-Rücklesen');
    $stored=$wpdb->get_row("SELECT * FROM {$prefix}evg_events WHERE id=".(int)$eid,ARRAY_A);
    $check($stored && $stored['start_dt']===$day.' 18:00:00' && $stored['calendar_id']==11 && !$stored['is_public'],'Kanonischer Termin mit lokaler Uhrzeit und Kalender im Cache');
    $check($events[$eid]['is_public']===false && $events[$eid]['access']===0,'Neue Termine sind nicht öffentlich, keine Teilnahme oder Mail ausgelöst');
    $api($users['trainer'],'POST','/event-creations',$input);$check($posts===1,'Wiederholtes Speichern mit derselben Kennung erzeugt keinen zweiten Termin');
    $check($api($users['other'],'GET','/event-creations/'.$input['request_id'])[0]===404,'Fremde Vorgänge sind nicht lesbar');
    $events[$eid]['name']='In EasyVerein geändert';$sync=(new EVG_Events_Sync())->run('test');
    $check($sync['ok'] && $wpdb->get_var("SELECT name FROM {$prefix}evg_events WHERE id=".(int)$eid)==='In EasyVerein geändert','EasyVerein-Änderung kommt über Vollsync zurück');
    $lost=$input;$lost['request_id']=wp_generate_uuid4();$lost['name']='Antwortverlust';$lost_response=true;$fail_read=true;
    [$code,$r]=$api($users['trainer'],'POST','/event-creations',$lost);$check($code===202 && $r['status']==='uncertain','Verlorene POST-Antwort bleibt offen, kein vorgetäuschter Erfolg');
    $different=$input;$different['request_id']=wp_generate_uuid4();$check($api($users['trainer'],'POST','/event-creations',$different)[0]===409,'Zweite Vorgangskennung wird bei offener Speicherung blockiert');
    $count=$posts;$api($users['trainer'],'POST','/event-creations',$lost);$check($posts===$count,'Offene Speicherung wird niemals blind erneut gepostet');
    $history=$api($users['trainer'],'GET','/event-creations')[1]['items'];$check(count(array_filter($history,function($i)use($lost){return $i['request_id']===$lost['request_id'] && $i['input']['name']==='Antwortverlust';}))===1,'Offener Vorgang nach Abmeldung serverseitig wiederherstellbar');
    $fail_read=false;$lost_response=false;[$code,$r]=$api($users['trainer'],'GET','/event-creations/'.$lost['request_id']);
    $check($r['status']==='confirmed' && $posts===$count,'Nachlesen findet interne Kennung und bestätigt ohne weiteren POST');
    $reject=true;$bad=$input;$bad['request_id']=wp_generate_uuid4();$r=$api($users['trainer'],'POST','/event-creations',$bad)[1];$check($r['status']==='rejected','Eindeutige EasyVerein-Ablehnung wird als solche angezeigt');
    update_user_meta($users['trainer'],'evg_is_uebungsleiter',0);$input['request_id']=wp_generate_uuid4();$count=$posts;
    $check($api($users['trainer'],'POST','/event-creations',$input)[0]===403 && $posts===$count,'Entzogenes Übungsleiterrecht wirkt vor dem nächsten Schreibzugriff');
    $lock=EVG_Events_Sync::acquire_write_lock();$check($lock,'Datenbanksperre für Vollsync und Terminanlage verfügbar');
    $second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    $lock_name=(new ReflectionMethod('EVG_Events_Sync','write_lock_name'))->invoke(null);
    $check((string)$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$lock_name))==='0','Andere Verbindung kann Vollsync und Anlage nicht gleichzeitig ausführen');
    EVG_Events_Sync::release_write_lock();$second->close();
} catch(Throwable $e){$fails++;WP_CLI::warning($e->getMessage());}
finally {
    EVG_Events_Sync::release_write_lock();
    foreach($tables as $table)$wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
    $wpdb->prefix=$original;foreach($old as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $uid)wp_delete_user($uid);
}
if($fails)WP_CLI::error("{$fails} Fehler, {$checks} Prüfungen.");WP_CLI::success("{$checks} Prüfungen bestanden; Testtabellen/Konten entfernt.");
