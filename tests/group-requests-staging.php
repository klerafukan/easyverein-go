<?php
/** Isolierte Integrationstests: eigene Tabellen/Testkonten, alle HTTP- und Mail-Aufrufe abgefangen. */
if (!defined('WP_CLI') || !WP_CLI) exit;
if (wp_get_environment_type() !== 'staging') WP_CLI::error('Nur auf WordPress-Staging ausführen.');
global $wpdb;
$source = $args[0] ?? '';
if (!class_exists('EVG_App_Push')) require $source.'/class-evg-app-push.php';
if (!class_exists('EVG_Group_Requests')) require $source.'/class-evg-group-requests.php';
foreach (['EVG_App_Push'=>'class-evg-app-push.php','EVG_Group_Requests'=>'class-evg-group-requests.php'] as $class=>$file) {
    if (hash_file('sha256',(new ReflectionClass($class))->getFileName())!==hash_file('sha256',$source.'/'.$file)) {
        WP_CLI::error('Geladener Plugin-Stand weicht von der Testquelle ab. Erst den passenden Stand auf Staging bereitstellen.');
    }
}
$server = rest_get_server();
$push = new EVG_App_Push(); $push->routes();
$joins = new EVG_Group_Requests(); $joins->routes();
$original_prefix = $wpdb->prefix;
$prefix = $original_prefix.'gjtest_'.substr(md5(wp_generate_uuid4()),0,8).'_';
$tables = ['evg_members','evg_groups','evg_member_groups','evg_group_join_requests','evg_app_devices','evg_app_push_jobs'];
$users = []; $checks=0; $fails=0; $assigned=false; $upstream_fail=false; $posts=0; $sent=0; $receipt_error=null; $push_fail=false; $timeout_after_write=false; $fail_readback=false;
$rules=[];
add_filter('pre_option_evg_group_join_rules', function() use (&$rules) { return $rules; });
add_filter('pre_option_evg_api_key', function() { return 'testsuite-token'; });
add_filter('pre_option_evg_api_requests_per_minute', function() { return 100000; });
add_filter('pre_wp_mail','__return_true');
add_filter('pre_schedule_event', function($pre,$event) { return $event->hook===EVG_App_Push::HOOK ? false : $pre; },10,2);
add_filter('pre_http_request', function($pre,$options,$url) use (&$assigned,&$upstream_fail,&$posts,&$sent,&$receipt_error,&$push_fail,&$timeout_after_write,&$fail_readback) {
    $response = function($body,$status=200) { return ['headers'=>[],'body'=>wp_json_encode($body),'response'=>['code'=>$status,'message'=>'testsuite'],'cookies'=>[]]; };
    if (strpos($url,'/api/v3.0/member-group-assignment')!==false) {
        if ($upstream_fail) return new WP_Error('testsuite_down','Upstream unavailable');
        if (($options['method'] ?? 'GET')==='POST') {
            $body=json_decode($options['body'],true);
            if (($body['user_object'] ?? null)!==990001 || ($body['member_group'] ?? null)!==990002) throw new Exception('Falsches Mitglied oder falsche Gruppe im EasyVerein-POST.');
            $posts++; $assigned=true;
            if ($timeout_after_write) return new WP_Error('testsuite_timeout','Antwort nach erfolgreichem Schreiben verloren');
            return $response(['id'=>999],201);
        }
        if ($fail_readback && $assigned) return new WP_Error('testsuite_readback','Nachlesen fehlgeschlagen');
        return $response(['results'=>$assigned ? [['id'=>999,'user_object'=>990001,'member_group'=>990002]] : []]);
    }
    if ($url==='https://exp.host/--/api/v2/push/send') { if ($push_fail) return new WP_Error('testsuite_offline','Offline'); $sent++; return $response(['data'=>['status'=>'ok','id'=>'test-ticket-'.$sent]]); }
    if ($url==='https://exp.host/--/api/v2/push/getReceipts') {
        $ids=json_decode($options['body'],true)['ids']; $data=[];
        foreach ($ids as $id) $data[$id]=$receipt_error ? ['status'=>'error','details'=>['error'=>$receipt_error]] : ['status'=>'ok'];
        return $response(['data'=>$data]);
    }
    return new WP_Error('testsuite_blocked','Externer HTTP-Aufruf im Test blockiert.');
},999,3);
$check = function($ok,$label) use (&$checks,&$fails) { $checks++; if (!$ok) $fails++; WP_CLI::line(($ok?'ok ':'not ok ').$checks.' - '.$label); };
$api = function($user,$method,$path,$body=[]) {
    wp_set_current_user($user);
    $request=new WP_REST_Request($method,'/easyverein-go/v1'.$path);
    if ($method==='GET') $request->set_query_params($body);
    else { $request->set_header('Content-Type','application/json'); $request->set_body(wp_json_encode($body)); }
    $result=rest_do_request($request); return [$result->get_status(),$result->get_data()];
};
try {
    $wpdb->prefix=$prefix;
    $wpdb->query("CREATE TABLE {$prefix}evg_members (member_id varchar(191) PRIMARY KEY,first_name varchar(120),family_name varchar(120))");
    $wpdb->query("CREATE TABLE {$prefix}evg_groups (group_id varchar(191) PRIMARY KEY,name varchar(255))");
    $wpdb->query("CREATE TABLE {$prefix}evg_member_groups (member_id varchar(191),group_id varchar(191),member_name varchar(255),group_name varchar(255),assigned_at datetime,PRIMARY KEY(member_id,group_id))");
    EVG_App_Push::ensure_tables(true); EVG_Group_Requests::ensure_table(true);
    foreach (['applicant','reviewer','other'] as $role) {
        $id=wp_insert_user(['user_login'=>'gjtest_'.$role.'_'.substr(md5($prefix),0,8),'user_pass'=>wp_generate_password(40),'role'=>'subscriber','display_name'=>'[testsuite] '.$role]);
        if (is_wp_error($id)) throw new Exception('Testkonto konnte nicht angelegt werden.');
        $users[$role]=(int)$id;
    }
    update_user_meta($users['applicant'],'evg_member_id','990001');
    $wpdb->insert($prefix.'evg_members',['member_id'=>'990001','first_name'=>'Test','family_name'=>'Mitglied']);
    $wpdb->insert($prefix.'evg_groups',['group_id'=>'990002','name'=>'[testsuite] Handball']);
    $rules=['990002'=>['approvers'=>[$users['reviewer']],'payment_active'=>false]];
    $check($api(0,'GET','/membership/groups')[0]===401,'Anonyme erhalten keine Gruppenliste');
    $check($api($users['applicant'],'GET','/membership/groups')[1]['groups'][0]['label']==='[testsuite] Handball','Nur freigegebene Gruppen werden angeboten');
    $check($api($users['other'],'POST','/membership/requests',['group_id'=>'990002'])[0]===403,'Keine Anfrage ohne verknüpftes Vereinsmitglied');
    $check($api($users['applicant'],'POST','/membership/requests',['group_id'=>'not-enabled'])[0]===400,'Nicht freigegebene Gruppen werden abgewiesen');
    [$status,$row]=$api($users['applicant'],'POST','/membership/requests',['group_id'=>'990002','member_id'=>'injected-other-member','note'=>'[testsuite] Beitritt']);
    $check($status===201 && $row['status']==='pending','Antrag wird gespeichert und wartet auf Freigabe'); $id=$row['id'];
    $check($posts===0,'Ein Antrag schreibt noch keine Mitgliedschaft nach EasyVerein');
    $duplicate=$api($users['applicant'],'POST','/membership/requests',['group_id'=>'990002']);
    $check($duplicate[0]===200 && $duplicate[1]['id']===$id,'Wiederholtes Absenden erzeugt keinen zweiten offenen Antrag');
    $check($api($users['other'],'GET','/membership/requests',['scope'=>'mine'])[1]['total']===0,'Eigene Liste enthält keine fremden Anträge');
    $check($api($users['other'],'GET','/membership/requests',['scope'=>'approvals'])[0]===403,'Unzuständiger Benutzer kann keine Freigaben lesen');
    $check($api($users['reviewer'],'GET','/membership/requests',['scope'=>'approvals'])[1]['total']===1,'Zuständiger Genehmiger sieht den Antrag');
    $check($api($users['applicant'],'POST',"/membership/requests/$id/decision",['action'=>'approve'])[0]===403,'Keine Selbstgenehmigung');
    $check($api($users['other'],'POST',"/membership/requests/$id/decision",['action'=>'approve'])[0]===403,'Unzuständiger Benutzer darf nicht genehmigen');
    $check($api($users['other'],'DELETE',"/membership/requests/$id")[0]===403,'Fremde Anträge können nicht zurückgezogen werden');
    $upstream_fail=true;
    $check($api($users['reviewer'],'POST',"/membership/requests/$id/decision",['action'=>'approve'])[0]===502,'EasyVerein-Ausfall wird nicht als Genehmigung gespeichert');
    $check($api($users['applicant'],'GET','/membership/requests',['scope'=>'mine'])[1]['items'][0]['status']==='pending','Antrag bleibt nach Upstream-Fehler erneut prüfbar');
    $upstream_fail=false;
    $approved=$api($users['reviewer'],'POST',"/membership/requests/$id/decision",['action'=>'approve']);
    $check($approved[0]===200 && $approved[1]['status']==='approved','Genehmigung bestätigt die tatsächliche Mitgliedschaft');
    $check($posts===1,'EasyVerein erhält genau einen POST mit serverseitig bestimmtem Mitglied');
    $repeat=$api($users['reviewer'],'POST',"/membership/requests/$id/decision",['action'=>'approve']);
    $check($repeat[0]===200 && $posts===1,'Wiederholte Genehmigung ist idempotent');
    $check(get_user_meta($users['applicant'],'evg_groups',true)==='','Gruppenmitgliedschaft gewährt keine App-Datenrechte');
    $check($api($users['applicant'],'POST','/membership/requests',['group_id'=>'990002'])[0]===409,'Bestehende Mitgliedschaft kann nicht erneut beantragt werden');
    // Ein erneuter Beitritt nach einem späteren Austritt ist wieder möglich.
    $wpdb->delete($prefix.'evg_member_groups',['member_id'=>'990001','group_id'=>'990002']);
    $assigned=false;
    $rules['990002']['payment_active']=true;
    $check($api($users['applicant'],'POST','/membership/requests',['group_id'=>'990002'])[0]===400,'Gruppenzahlungen benötigen eine ausdrückliche Bestätigung');
    $new=$api($users['applicant'],'POST','/membership/requests',['group_id'=>'990002','acknowledge_payments'=>true]); $id2=$new[1]['id'];
    $check($new[0]===201,'Bestätigter Antrag mit Gruppenzahlungen wird gespeichert');
    $check($api($users['reviewer'],'POST',"/membership/requests/$id2/decision",['action'=>'reject'])[0]===400,'Ablehnung verlangt einen Grund');
    $check($api($users['reviewer'],'POST',"/membership/requests/$id2/decision",['action'=>'reject','note'=>'[testsuite] Ausgebucht'])[1]['status']==='rejected','Ablehnung wird mit Begründung gespeichert');
    $third=$api($users['applicant'],'POST','/membership/requests',['group_id'=>'990002','acknowledge_payments'=>true]); $id3=$third[1]['id'];
    $check($api($users['applicant'],'DELETE',"/membership/requests/$id3")[1]['status']==='withdrawn','Eigener offener Antrag kann zurückgezogen werden');
    $device=['installation_id'=>'11111111-1111-4111-8111-111111111111','secret'=>str_repeat('a',64),'token'=>'ExpoPushToken[testsuite-device-token]','platform'=>'android','project_id'=>EVG_App_Push::PROJECT_ID];
    $check($api($users['reviewer'],'POST','/push-devices',$device)[0]===200,'Push-Gerät wird am eigenen Konto registriert');
    $wrong=$device; $wrong['secret']=str_repeat('b',64);
    $check($api($users['other'],'POST','/push-devices',$wrong)[0]===403,'Anderes Konto kann ein Gerät ohne Widerrufsgeheimnis nicht übernehmen');
    $check($api(0,'DELETE','/push-devices/'.$device['installation_id'],['secret'=>str_repeat('b',64)])[0]===403,'Falsches Widerrufsgeheimnis wird abgewiesen');
    $fourth=$api($users['applicant'],'POST','/membership/requests',['group_id'=>'990002','acknowledge_payments'=>true]); $id4=$fourth[1]['id'];
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}evg_app_push_jobs")===1,'Neuer Antrag legt einen dauerhaften Push-Auftrag an');
    EVG_App_Push::work();
    $job=$wpdb->get_row("SELECT * FROM {$prefix}evg_app_push_jobs LIMIT 1",ARRAY_A);
    $check($sent===1 && $job['status']==='receipt','Expo-Ticket wird gespeichert statt Zustellung vorzutäuschen');
    $wpdb->update($prefix.'evg_app_push_jobs',['available_at'=>'2000-01-01 00:00:00'],['id'=>$job['id']]);
    $receipt_error='DeviceNotRegistered'; EVG_App_Push::work();
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}evg_app_devices")===0,'Ungültige Geräte werden nach Expo-Receipt entfernt');
    $api($users['reviewer'],'POST','/push-devices',$device);
    $check($api(0,'DELETE','/push-devices/'.$device['installation_id'],['secret'=>$device['secret']])[0]===200,'Gerät kann nach Ablauf des Logins mit eigenem Geheimnis widerrufen werden');
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}evg_app_devices")===0,'Widerruf entfernt das Push-Ziel');
    $assigned=false; $timeout_after_write=true;
    $recovered=$api($users['reviewer'],'POST',"/membership/requests/$id4/decision",['action'=>'approve']);
    $check($recovered[0]===200 && $recovered[1]['status']==='approved' && $posts===2,'Erfolgreicher EasyVerein-POST mit verlorener Antwort wird durch Nachlesen erkannt');
    $timeout_after_write=false;
    $wpdb->delete($prefix.'evg_member_groups',['member_id'=>'990001','group_id'=>'990002']); $assigned=false;
    $api($users['reviewer'],'POST','/push-devices',$device);
    $api($users['applicant'],'POST','/membership/requests',['group_id'=>'990002','acknowledge_payments'=>true]);
    $push_fail=true; EVG_App_Push::work();
    $retry=$wpdb->get_row("SELECT * FROM {$prefix}evg_app_push_jobs ORDER BY id DESC LIMIT 1",ARRAY_A);
    $check($retry['status']==='pending' && (int)$retry['attempts']===1,'Push-Ausfall bleibt als erneut zustellbarer Auftrag bestehen');
    $push_fail=false; $rules['990002']['approvers']=[];
    $wpdb->update($prefix.'evg_app_push_jobs',['available_at'=>'2000-01-01 00:00:00'],['id'=>$retry['id']]);
    EVG_App_Push::work();
    $check($sent===1,'Entzogene Genehmigungsrechte werden vor einem späteren Push-Versuch erneut geprüft');
    $rules['990002']['approvers']=[$users['reviewer']];
    $uncertain_id=(int)$wpdb->get_var("SELECT MAX(id) FROM {$prefix}evg_group_join_requests");
    $fail_readback=true;
    $check($api($users['reviewer'],'POST',"/membership/requests/$uncertain_id/decision",['action'=>'approve'])[0]===502,'Fehlgeschlagenes Nachlesen meldet keinen Genehmigungserfolg');
    $check($wpdb->get_var($wpdb->prepare("SELECT status FROM {$prefix}evg_group_join_requests WHERE id=%d",$uncertain_id))==='processing','Unbestätigter Schreibversuch bleibt zur Nachprüfung markiert');
    $check($api($users['applicant'],'DELETE',"/membership/requests/$uncertain_id")[0]===409,'Unklare Mitgliedschaft kann nicht zurückgezogen werden');
    $check($api($users['reviewer'],'POST',"/membership/requests/$uncertain_id/decision",['action'=>'reject','note'=>'Abbruch'])[0]===409,'Unklare Mitgliedschaft kann nicht abgelehnt werden');
    $fail_readback=false; $before_retry=$posts;
    $check($api($users['reviewer'],'POST',"/membership/requests/$uncertain_id/decision",['action'=>'approve'])[1]['status']==='approved' && $posts===$before_retry,'Nachprüfung bestätigt die Mitgliedschaft ohne zweiten Schreibversuch');


} catch (Throwable $error) {
    $fails++; WP_CLI::warning('Unerwarteter Testfehler: '.$error->getMessage());
} finally {
    foreach ($tables as $table) $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
    $wpdb->prefix=$original_prefix;
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach ($users as $id) wp_delete_user($id);
}
if ($fails) WP_CLI::error("{$fails} Fehler bei {$checks} Prüfungen.");
WP_CLI::success("{$checks} Prüfungen bestanden; Testtabellen und Konten entfernt.");
