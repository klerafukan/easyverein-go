<?php
/** Terminanlage über den Server. EasyVerein bleibt führend; kein automatisches POST-Retry. */
if (!defined('ABSPATH')) exit;
class EVG_Event_Writes {
    public function __construct() {
        add_action('init',[__CLASS__,'ensure_table']);
        add_action('rest_api_init',[$this,'routes']);
    }
    public static function ensure_table() {
        global $wpdb;
        if ((int)get_option('evg_event_writes_schema')===1 && $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}evg_event_writes'")) return;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$wpdb->prefix}evg_event_writes (
            request_id varchar(36) NOT NULL,
            user_id bigint unsigned NOT NULL,
            payload_hash varchar(64) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'prepared',
            event_id bigint unsigned DEFAULT NULL,
            message text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (request_id),
            KEY owner_status (user_id,status)
        ) {$wpdb->get_charset_collate()};");
        update_option('evg_event_writes_schema',1,false);
    }
    public static function calendar_ids($uid) {
        global $wpdb;
        if (!$uid) return [];
        $rows=(array)$wpdb->get_results("SELECT id,allowed_groups FROM {$wpdb->prefix}evg_calendars",ARRAY_A);
        $admin=user_can($uid,'manage_options');
        if (!$admin && !(bool)(int)get_user_meta($uid,'evg_is_uebungsleiter',true)) return [];
        if (!$admin && !(string)get_user_meta($uid,'evg_member_id',true)) return [];
        $groups=$admin ? [] : EVG_Events_Sync::member_group_ids($uid,(string)get_user_meta($uid,'evg_member_id',true));
        $map=EVG_Training_Calendars::mappings();
        $assigned=[];
        foreach ($groups as $group) $assigned=array_merge($assigned,$map[$group]??[]);
        $filter=array_filter(array_map('trim',explode(',',(string)get_option('evg_events_calendar_filter',''))));
        $ids=[];
        foreach ($rows as $row) {
            $id=(string)$row['id'];
            if ($filter && !in_array($id,$filter,true)) continue;
            // Sichtbarkeit ist nur eine zusätzliche Leseschranke, niemals die Quelle des Schreibrechts.
            $visible=$row['allowed_groups']==='' || array_intersect($groups,explode(',',(string)$row['allowed_groups']));
            if ($admin || (in_array($id,$assigned,true) && $visible)) $ids[]=$id;
        }
        return $ids;
    }
    public function routes() {
        $auth=function(){return is_user_logged_in();};
        register_rest_route('easyverein-go/v1','/event-creation-options',['methods'=>'GET','permission_callback'=>$auth,'callback'=>[$this,'options']]);
        register_rest_route('easyverein-go/v1','/event-creations',[[ 'methods'=>'POST','permission_callback'=>$auth,'callback'=>[$this,'create'] ],[ 'methods'=>'GET','permission_callback'=>$auth,'callback'=>[$this,'history'] ]]);
        register_rest_route('easyverein-go/v1','/event-creations/(?P<id>[a-fA-F0-9-]{36})',['methods'=>'GET','permission_callback'=>$auth,'callback'=>[$this,'status']]);
    }
    public function options() {
        global $wpdb;
        $ids=self::calendar_ids(get_current_user_id());
        $calendars=[];
        foreach ((array)$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}evg_calendars ORDER BY name",ARRAY_A) as $r) {
            if (in_array((string)$r['id'],$ids,true)) $calendars[]=['calendar_id'=>(string)$r['id'],'name'=>$r['name']];
        }
        return new WP_REST_Response(['calendars'=>$calendars,'timezone'=>wp_timezone_string(),'min_date'=>wp_date('Y-m-d'),'max_date'=>wp_date('Y-m-d',strtotime('+12 months'))],200);
    }
    private static function error($code,$message,$status=400) {return new WP_Error($code,$message,['status'=>$status]);}
    private static function date($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00$/D',$value)) return false;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$value,wp_timezone());
        // Rundlauf erkennt ungültige Daten und die übersprungene Stunde beim Sommerzeitwechsel.
        return $d && $d->format('Y-m-d\TH:i:s')===$value ? $d : false;
    }
    public static function validate($input,$uid) {
        if (!is_array($input)) return self::error('evg_event_input','Ungültiger Termin.');
        foreach (['name'=>500,'description'=>10000,'location_name'=>500,'calendar_id'=>20,'start'=>19,'end'=>19] as $key=>$limit) {
            if (!isset($input[$key]) || !is_string($input[$key]) || mb_strlen($input[$key])>$limit) return self::error('evg_event_input','Bitte Titel, Kalender, Datum und Uhrzeit vollständig und gültig angeben.');
        }
        if (!is_bool($input['all_day']??null)) return self::error('evg_event_input','Ungültige Ganztagsangabe.');
        if (!in_array($input['calendar_id'],self::calendar_ids($uid),true)) return self::error('evg_event_calendar','Für diesen Kalender fehlt die Berechtigung zur Terminanlage.',403);
        $name=trim(sanitize_text_field($input['name']));
        $start=self::date($input['start']); $end=self::date($input['end']);
        if (!$name || !$start || !$end || $end<=$start) return self::error('evg_event_dates','Bitte einen Titel und ein Ende nach dem Beginn angeben.');
        $min=new DateTimeImmutable('today',wp_timezone()); $max=$min->modify('+12 months')->setTime(23,59,59);
        if ($start<$min || $start>$max || $end>$max->modify('+1 day')) return self::error('evg_event_range','Neue Termine müssen zwischen heute und zwölf Monaten in der Zukunft liegen.');
        if ($input['all_day'] && ($start->format('H:i:s')!=='00:00:00' || $end->format('H:i:s')!=='00:00:00')) return self::error('evg_event_dates','Ganztagstermine müssen an Tagesgrenzen beginnen und enden.');
        return ['name'=>$name,'description'=>sanitize_textarea_field($input['description']),'calendar'=>(int)$input['calendar_id'],
            'start'=>$start->format(DateTimeInterface::ATOM),'end'=>$end->format(DateTimeInterface::ATOM),'all_day'=>$input['all_day'],
            'location_name'=>sanitize_text_field($input['location_name']),'is_public'=>false,'is_reservation'=>false,'access'=>0,'show_memberarea'=>true,'send_mail_check'=>false];
    }
    private static function request($method,$path,$body=null) {
        $key=(string)get_option('evg_api_key','');
        if (!$key) return self::error('evg_event_upstream','EasyVerein ist nicht eingerichtet.',503);
        $headers=['Accept'=>'application/json','Content-Type'=>'application/json'];
        if (get_option('evg_auth_header','Authorization Bearer')==='Authorization Bearer') $headers['Authorization']='Bearer '.$key;
        else $headers['X-API-Key']=$key;
        $args=['method'=>$method,'headers'=>$headers,'timeout'=>25,'redirection'=>0];
        if ($body!==null) $args['body']=wp_json_encode($body);
        evg_api_throttle();
        return wp_remote_request(rtrim(get_option('evg_api_base','https://easyverein.com'),'/').'/api/v3.0/'.$path,$args);
    }
    private static function data($response) {
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)<200 || wp_remote_retrieve_response_code($response)>=300) return null;
        $data=json_decode(wp_remote_retrieve_body($response),true);
        return is_array($data) ? $data : null;
    }
    private static function row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}evg_event_writes WHERE request_id=%s",$id),ARRAY_A);
    }
    private static function update($id,$fields) {
        global $wpdb; $fields['updated_at']=current_time('mysql');
        return $wpdb->update($wpdb->prefix.'evg_event_writes',$fields,['request_id'=>$id]);
    }
    private static function ref_id($value) {
        if (is_array($value)) $value=$value['id']??0;
        if (is_numeric($value)) return (int)$value;
        return preg_match('#/(\d+)/?$#',(string)$value,$m) ? (int)$m[1] : 0;
    }
    /** Nur durch Marker und Kalender bestätigte Datensätze übernehmen. */
    private static function confirm($row,$event) {
        $payload=json_decode($row['payload'],true);
        if (empty($event['id']) || !isset($event['note']) || strpos($event['note'],'EVG-App:'.$row['request_id'])===false || self::ref_id($event['calendar']??0)!==(int)$payload['calendar']) return false;
        self::update($row['request_id'],['event_id'=>(int)$event['id']]);
        if (!(new EVG_Events_Sync())->store_event($event)) return false;
        return self::update($row['request_id'],['status'=>'confirmed','message'=>'Termin in EasyVerein gespeichert.'])!==false;
    }
    public static function observe_event($event) {
        if (!preg_match('/EVG-App:([a-f0-9-]{36})/',(string)($event['note']??''),$m)) return;
        $row=self::row($m[1]);
        if ($row && in_array($row['status'],['sending','uncertain'],true)) self::confirm($row,$event);
    }
    private static function reconcile($row) {
        if (!in_array($row['status'],['sending','uncertain'],true)) return;
        if (!empty($row['event_id'])) {
            $data=self::data(self::request('GET','event/'.(int)$row['event_id']));
            if ($data) self::confirm($row,$data);
            return;
        }
        $payload=json_decode($row['payload'],true);
        // Auch nach verlorener POST-Antwort: exakte interne Kennung suchen, niemals erneut anlegen.
        for ($offset=0;$offset<1000;$offset+=100) {
            $path='event?limit=100&offset='.$offset.'&calendar='.(int)$payload['calendar'].'&start='.rawurlencode($payload['start']);
            $data=self::data(self::request('GET',$path));
            if (!$data || !isset($data['results']) || !is_array($data['results'])) return;
            foreach ($data['results'] as $event) if (self::confirm($row,$event)) return;
            if (empty($data['next'])) return;
        }
    }
    private static function response($row) {
        return new WP_REST_Response(['request_id'=>$row['request_id'],'status'=>$row['status'],'event_id'=>$row['event_id'] ? (string)$row['event_id'] : null,'message'=>$row['message'] ?: 'Die Speicherung wird geprüft. Bitte keinen zweiten Termin anlegen.'],in_array($row['status'],['prepared','sending','uncertain'],true)?202:200);
    }
    public function create(WP_REST_Request $request) {
        global $wpdb;
        $input=$request->get_json_params(); $id=is_array($input)?($input['request_id']??''):''; $uid=get_current_user_id();
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$id)) return self::error('evg_event_id','Ungültige Vorgangskennung.');
        if (!EVG_Events_Sync::acquire_write_lock()) return self::error('evg_event_busy','Der Terminabgleich läuft gerade. Bitte gleich erneut versuchen.',503);
        try {
            $row=self::row($id);
            if ($row) {
                if ((int)$row['user_id']!==$uid) return self::error('evg_event_owner','Vorgang nicht gefunden.',404);
                if ($row['status']!=='prepared') {self::reconcile($row);return self::response(self::row($id));}
            }
            $payload=self::validate($input,$uid);
            if (is_wp_error($payload)) return $payload;
            $hash=hash('sha256',wp_json_encode($payload));
            if ($row && $hash!==$row['payload_hash']) return self::error('evg_event_conflict','Die Vorgangskennung gehört zu einem anderen Termin.',409);
            if (!$row) {
                $open=$wpdb->get_var($wpdb->prepare("SELECT request_id FROM {$wpdb->prefix}evg_event_writes WHERE user_id=%d AND status IN ('prepared','sending','uncertain') LIMIT 1",$uid));
                if ($open) return self::error('evg_event_pending','Eine frühere Speicherung ist noch offen. Bitte diesen Vorgang zuerst prüfen.',409);
                $ok=$wpdb->insert($wpdb->prefix.'evg_event_writes',['request_id'=>$id,'user_id'=>$uid,'payload_hash'=>$hash,'payload'=>wp_json_encode($payload),'status'=>'prepared','message'=>'','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
                if (!$ok) return self::error('evg_event_storage','Vorgang konnte nicht gespeichert werden.',503);
            }
            if (self::update($id,['status'=>'sending'])===false) return self::error('evg_event_storage','Vorgang konnte nicht gestartet werden.',503);
            $payload['note']='EVG-App:'.$id;
            $response=self::request('POST','event',$payload);
            $code=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);
            if (in_array($code,[400,401,403,404,422,429],true)) {
                self::update($id,['status'=>'rejected','message'=>'EasyVerein hat den Termin nicht angenommen (HTTP '.$code.'). Bitte Angaben und Kalenderberechtigung prüfen.']);
            } else {
                $data=self::data($response);
                if ($data && !empty($data['id'])) self::update($id,['event_id'=>(int)$data['id']]);
                self::update($id,['status'=>'uncertain','message'=>'Speicherung noch nicht bestätigt. Bitte Status prüfen; der Termin wird nicht erneut angelegt.']);
                self::reconcile(self::row($id));
            }
            return self::response(self::row($id));
        } finally {EVG_Events_Sync::release_write_lock();}
    }
    public function history() {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}evg_event_writes WHERE user_id=%d ORDER BY (status IN ('prepared','sending','uncertain')) DESC,created_at DESC LIMIT 30",get_current_user_id()),ARRAY_A);
        $items=[];
        foreach ((array)$rows as $row) {
            $p=json_decode($row['payload'],true);
            $start=(new DateTimeImmutable($p['start']))->setTimezone(wp_timezone());
            $end=(new DateTimeImmutable($p['end']))->setTimezone(wp_timezone());
            $items[]=array_merge(self::response($row)->get_data(),['name'=>$p['name'],'input'=>[
                'request_id'=>$row['request_id'],'name'=>$p['name'],'calendar_id'=>(string)$p['calendar'],
                'start'=>$start->format('Y-m-d\TH:i:s'),'end'=>$end->format('Y-m-d\TH:i:s'),
                'all_day'=>(bool)$p['all_day'],'description'=>$p['description'],'location_name'=>$p['location_name']
            ]]);
        }
        return new WP_REST_Response(['items'=>$items],200);
    }
    public function status(WP_REST_Request $request) {
        $row=self::row($request['id']);
        if (!$row || (int)$row['user_id']!==get_current_user_id()) return self::error('evg_event_owner','Vorgang nicht gefunden.',404);
        if (EVG_Events_Sync::acquire_write_lock()) {
            try {self::reconcile($row);} finally {EVG_Events_Sync::release_write_lock();}
        }
        return self::response(self::row($row['request_id']));
    }
}
