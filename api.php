<?php

// The FusionPBX bootstrap supplies settings and opens the session.
// An explicit native login must not inherit an older portal identity.
$mobileCredentialLogin = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (array_key_exists('username', $_POST) || array_key_exists('key', $_POST));
// For this login only, check CIDR against the host tenant below.
$no_cidr = $mobileCredentialLogin;
unset($_GET['domain_change'], $_REQUEST['domain_change']);
require_once dirname(__DIR__, 2) . '/resources/require.php';
ini_set('display_errors', '0');

function callassist_mobile_deny(int $status): never {
    // Existing Mobile clients silently reauthenticate on this HTML session probe.
    if ($status === 401
        && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && str_starts_with((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'Dart/')
        && !empty($_COOKIE[session_name()])
        && $_GET === [] && $_POST === []
        && empty($_SERVER['HTTP_AUTHORIZATION'])
        && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
        && empty($_SERVER['PHP_AUTH_USER'])
        && file_get_contents('php://input') === '') {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        echo '<html><body>Authentication required</body></html>';
        exit;
    }

    http_response_code($status);
    echo '[]';
    exit;
}

function callassist_mobile_user(database $database, string $domainUuid, string $userUuid): bool {
    return (bool) $database->select(
        "select 1 from v_users u join v_domains d on d.domain_uuid = u.domain_uuid
         where u.domain_uuid = :domain_uuid and u.user_uuid = :user_uuid
           and coalesce(d.domain_enabled::text, 'true') in ('true', 't') and u.user_enabled is distinct from false
           and (
              exists (
               select 1 from v_user_groups g
               join v_group_permissions p on p.group_name = g.group_name
               where g.user_uuid = u.user_uuid
                 and (g.domain_uuid is null or g.domain_uuid = u.domain_uuid)
                 and (p.domain_uuid is null or p.domain_uuid = u.domain_uuid)
                 and p.permission_assigned = 'true'
                 and p.permission_name in ('callassist_view', 'callassist_user')
              )
              or exists (
                select 1 from v_user_groups mobile_membership
                join v_groups mobile_group on mobile_group.group_uuid = mobile_membership.group_uuid
                where mobile_membership.user_uuid = u.user_uuid
                  and mobile_membership.domain_uuid = u.domain_uuid
                  and (mobile_group.domain_uuid is null or mobile_group.domain_uuid = u.domain_uuid)
                  and mobile_group.group_name = 'CallAssist Mobile User'
                  and mobile_group.group_uuid = 'a9380266-748d-4afe-92a2-8e041237ab88'
              )
            )",
        ['domain_uuid' => $domainUuid, 'user_uuid' => $userUuid],
        'column'
    );
}

// Use the same client binding as FusionPBX's portal session.
$validationFields = $conf['session.validate'] ?? ['HTTP_USER_AGENT'];
$serverValues = [];
foreach ($validationFields as $field) {
    $serverValues[] = $_SERVER[$field] ??= '';
}
$clientHash = hash('sha256', implode($serverValues));
$domainUuid = $_SESSION['domain_uuid'] ?? '';
$domainName = $_SESSION['domain_name'] ?? '';
$host = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''), 2)[0]);

if ($mobileCredentialLogin) {
    $loginDomain = $database->select(
        'select domain_uuid, domain_name from v_domains where domain_name = :domain_name',
        ['domain_name' => $host],
        'row'
    );
    $domainUuid = $loginDomain['domain_uuid'] ?? '';
    $domainName = $loginDomain['domain_name'] ?? '';
}

// GET still carries legacy action parameters. A browser may only issue these
// calls from the same origin. Mobile HTTP clients do not send browser headers.
$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($fetchSite, ['same-site', 'cross-site'], true)
    || ($origin !== '' && strcasecmp($origin, 'https://' . ($_SERVER['HTTP_HOST'] ?? '')) !== 0)) {
    callassist_mobile_deny(403);
}

if (!is_uuid($domainUuid) || $host === '' || strcasecmp($domainName, $host) !== 0) {
    callassist_mobile_deny(401);
}

if (!$mobileCredentialLogin && !empty($_SESSION['authorized'])) {
    if (!hash_equals($clientHash, (string) ($_SESSION['user_hash'] ?? ''))
        || !is_uuid($_SESSION['user_uuid'] ?? '')
        || ($_SESSION['user']['domain_uuid'] ?? '') !== $domainUuid) {
        callassist_mobile_deny(401);
    }
    if (!callassist_mobile_user($database, $domainUuid, $_SESSION['user_uuid'])) {
        callassist_mobile_deny(403);
    }
} else {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $key = $_POST['key'] ?? '';
    $login = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && (array_key_exists('username', $_POST) || array_key_exists('key', $_POST));

    if ($login) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            callassist_mobile_deny(503);
        }
        if (!is_string($username) || !is_string($password) || !is_string($key)
            || ($key === '' && ($username === '' || $password === ''))
            || strlen($username) > 254 || strlen($password) > 1024
            || ($key !== '' && (strlen($key) <= 30 || strlen($key) > 512))) {
            callassist_mobile_deny(401);
        }

        // Start a clean, endpoint-owned login. The native client's form POST
        // has no browser token. Bind a stock login token to this request after
        // rejecting cross-origin browser traffic, then use the stock backend.
        $_SESSION = ['domain_uuid' => $domainUuid, 'domain_name' => $domainName];
        $settings = new settings(['database' => $database, 'domain_uuid' => $domainUuid]);
        if (!check_cidr($settings->get('domain', 'cidr', []), $_SERVER['REMOTE_ADDR'] ?? '')) {
            callassist_mobile_deny(403);
        }
        $form = ['username' => $username, 'domain_name' => $domainName];
        if ($key !== '') {
            $form['key'] = $key;
        } else {
            $form['password'] = $password;
        }
        $token = (new token())->create('login');
        $form[$token['name']] = $token['hash'];
        $originalRequest = $_REQUEST;
        $originalPost = $_POST;
        $originalCookies = $_COOKIE;
        $_REQUEST = $form;
        $_POST = $form;
        unset($_COOKIE['remember_me']);

        $auth = new authentication(['database' => $database, 'settings' => $settings]);
        $auth->domain_uuid = $domainUuid;
        $auth->domain_name = $domainName;
        // Some configured authentication plugins emit a web challenge. Never
        // let a page fragment precede the legacy API response.
        $authenticationReturned = false;
        $bufferLevel = ob_get_level();
        ob_start();
        register_shutdown_function(static function () use (&$authenticationReturned, $bufferLevel): void {
            if (!$authenticationReturned) {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }
                $_SESSION = [];
                header_remove('Location');
                http_response_code(401);
                echo '[]';
            }
        });
        try {
            $auth->validate();
        } catch (Throwable $exception) {
            $_SESSION = [];
        } finally {
            $authenticationReturned = true;
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $_REQUEST = $originalRequest;
            $_POST = $originalPost;
            $_COOKIE = $originalCookies;
        }
        if (empty($_SESSION['authorized'])
            || ($_SESSION['domain_uuid'] ?? '') !== $domainUuid
            || !is_uuid($_SESSION['user_uuid'] ?? '')) {
            $_SESSION = [];
            $safeUser = preg_replace('/[\r\n\t]/', '', $username);
            syslog(LOG_WARNING, '[' . ($_SERVER['REMOTE_ADDR'] ?? '') . '] authentication failed for ' . $safeUser);
            callassist_mobile_deny(401);
        }

        if (!callassist_mobile_user($database, $domainUuid, $_SESSION['user_uuid'])) {
            $_SESSION = [];
            callassist_mobile_deny(403);
        }

        // Persist the verified context beneath the API namespace. The same
        // cookie continues to work here, without becoming a portal login.
        $mobileSession = array_intersect_key($_SESSION, array_flip([
            'domain_uuid', 'domain_name', 'user_uuid', 'username', 'context', 'user_context',
            'user', 'user_hash', 'domains', 'groups', 'permissions', 'time_zone'
        ]));
        $_SESSION = ['callassist_mobile' => $mobileSession];
    } else {
        $mobileSession = $_SESSION['callassist_mobile'] ?? null;
        if (!is_array($mobileSession)
            || ($mobileSession['domain_uuid'] ?? '') !== $domainUuid
            || !is_uuid($mobileSession['user_uuid'] ?? '')
            || !hash_equals($clientHash, (string) ($mobileSession['user_hash'] ?? ''))) {
            callassist_mobile_deny(401);
        }
        if (!callassist_mobile_user($database, $domainUuid, $mobileSession['user_uuid'])) {
            unset($_SESSION['callassist_mobile']);
            callassist_mobile_deny(403);
        }
    }
    session_write_close();
    $_SESSION = $mobileSession;
}

$domain_uuid = $_SESSION['domain_uuid'];
$settings = new settings([
    'database' => $database,
    'domain_uuid' => $domain_uuid,
    'user_uuid' => $_SESSION['user_uuid'],
]);
if (!check_cidr($settings->get('domain', 'cidr', []), $_SERVER['REMOTE_ADDR'] ?? '')) {
    callassist_mobile_deny(403);
}

// Refresh assignments so a revoked extension is unavailable on an existing session.
$assigned = $database->select(
    "select e.extension_uuid, e.extension as \"user\", e.number_alias, e.user_context,
            e.outbound_caller_id_number
       from v_extensions e join v_extension_users u on u.extension_uuid = e.extension_uuid
      where e.domain_uuid = :domain_uuid and u.user_uuid = :user_uuid and e.enabled = 'true'
      order by e.extension",
    ['domain_uuid' => $domain_uuid, 'user_uuid' => $_SESSION['user_uuid']],
    'all'
);
$_SESSION['user']['extension'] = is_array($assigned) ? $assigned : [];
$byUuid = array_column($_SESSION['user']['extension'], null, 'extension_uuid');
$byNumber = array_column($_SESSION['user']['extension'], null, 'user');
$getAction = $_GET['action'] ?? '';
$action = $_REQUEST['action'] ?? '';

if (in_array($getAction, ['setoutboundcallerid', 'setdnd', 'setforwardall', 'setbusy', 'setnoanswer'], true)
    && isset($_GET['extension_uuid'])) {
    $extensionUuid = $_GET['extension_uuid'];
    if (!is_string($extensionUuid) || !isset($byUuid[$extensionUuid])
        || (isset($_GET['extension']) && $_GET['extension'] !== $byUuid[$extensionUuid]['user'])) {
        callassist_mobile_deny(403);
    }
}

header('HTTP/1.1 200 OK', true, 200);


    if($action == "cdr")
    {

    $allowRecording = false;

    // settings ophalen
    $settings_sql = "select user_setting_subcategory, user_setting_value 
                     from v_user_settings 
                     where user_uuid = :user_uuid 
                     and user_setting_category = 'callassist'";

    $settings_parameters['user_uuid'] = $_SESSION['user_uuid'];
    $database = new database;
    $settings_row = $database->select($settings_sql, $settings_parameters, 'all');

    foreach ((array) $settings_row as $setting) {
        if ($setting['user_setting_subcategory'] == 'allowrecording') {
            $allowRecording = ($setting['user_setting_value'] === 'true');
            break;
        }
    }

    define('TIME_24HR', 1);
    $limit = 50;

    // extensions verzamelen
    $extension_uuids = [];
    foreach ($_SESSION['user']['extension'] as $row) {
        if (!empty($row['extension_uuid'])) {
            $extension_uuids[] = $row['extension_uuid'];
        }
    }

    if (empty($extension_uuids)) {
        echo json_encode([]);
        exit;
    }

    // SQL
    $sql = "SELECT
                xml_cdr_uuid as uuid,
                start_stamp,
                direction,
                caller_id_name,
                caller_id_number,
                destination_number,
                hangup_cause,
                billsec as duration,
                record_path,
                record_name
            FROM v_xml_cdr
            WHERE
                domain_uuid = :domain_uuid
                AND hangup_cause <> 'LOSE_RACE'
                AND (cc_side IS NULL OR cc_side != 'agent')
                AND leg = 'a'
                AND extension_uuid IN ('" . implode("','", $extension_uuids) . "')
            ORDER BY start_stamp DESC
            LIMIT :limit";

    $parameters = [];
    $parameters['domain_uuid'] = $domain_uuid;
    $parameters['limit'] = $limit;

    $database = new database;
    $result = $database->select($sql, $parameters, 'all');

    $resultnew = [];

    foreach ((array) $result as $call) {

        $newline = [];

        $newline["uuid"] = $call["uuid"];
        //$newline["start_stamp"] = $call["start_stamp"];
	 $newline["start_stamp"] = !empty($call["start_stamp"]) ? date('Y-m-d H:i:s', strtotime($call["start_stamp"])) : "";
        $newline["direction"] = $call["direction"];
        $newline["caller_id_name"] = $call["caller_id_name"];
        $newline["destination_number"] = $call["destination_number"];
        $newline["caller_id_number"] = $call["caller_id_number"];
        $newline["hangup_cause"] = $call["hangup_cause"];
        $newline["duration"] = (int)$call["duration"];

        $newline["recording"] = (
            $allowRecording &&
            !empty($call["record_path"]) &&
            !empty($call["record_name"])
        );

        $resultnew[] = $newline;
    }

    echo json_encode($resultnew, JSON_FORCE_OBJECT);


    }
	else if(
		$getAction == "download" &&
		isset($_GET['id'])
	)
    {

	$obj = new xml_cdr;
	$obj->recording_uuid = $_GET['id'];
	$obj->binary = isset($_GET['t']) && $_GET['t'] == 'bin' ? true : false;
	$obj->download();


    }	else if(
		$getAction == "voicemail"
	)
    {
	$vm = new voicemail;
	$vm->domain_uuid = $_SESSION['domain_uuid'];
	if (!empty($voicemail_uuid) && is_uuid($voicemail_uuid)) {
		$vm->voicemail_uuid = $voicemail_uuid;
	}
	else if (!empty($voicemail_id) && is_numeric($voicemail_id)) {
		$vm->voicemail_id = $voicemail_id;
	}
	$vm->order_by = $order_by ?? null;
	$vm->order = $order ?? null;
	$voicemails = $vm->messages();

	$result = [];

	foreach ((array) $voicemails as $vm) {
	    if (empty($vm['messages'])) continue;

	    foreach ($vm['messages'] as $msg) {

	        // epoch ? nette datetime
	        $datetime = date('Y-m-d H:i:s', $msg['created_epoch']);

		if($msg['file_size'] > 0)
		{

	        $result[] = [
	            'voicemail_id' => $msg['voicemail_id'],
	            'message_length' => $msg['message_length_label'],
	            'caller_id_number' => $msg['caller_id_number'],
	            'caller_id_name' => $msg['caller_id_name'],
	            'created_date' => $datetime,
	            'voicemail_uuid' => $msg['voicemail_uuid'],
	            'voicemail_message_uuid' => $msg['voicemail_message_uuid'],
	            'sort_epoch' => $msg['created_epoch'] // alleen voor sort
	        ];
		}
	    }
	}

	// sorteren: nieuwste bovenaan
	usort($result, function ($a, $b) {
	    return $b['sort_epoch'] <=> $a['sort_epoch'];
	});

	// sort veld verwijderen
	$result = array_map(function ($item) {
	    unset($item['sort_epoch']);
	    return $item;
	}, $result);

	// JSON output
	echo json_encode($result, JSON_PRETTY_PRINT);

    }

/// /app/callassist_mobile/api.php?action=downloadvoicemail&id=209&voicemail_uuid=e3bd45f4-c7a3-48dd-826c-32b9119bfc5f&uuid=dbc5cf4c-cc07-48bd-8fc9-2a5ba6aa5a24

	else if (
		$getAction == "downloadvoicemail"
		&& !empty($_REQUEST["id"]) 
		&& !empty($_REQUEST["uuid"])
		&& !empty($_REQUEST["voicemail_uuid"]) 
	) {

    $domain_uuid = $_SESSION['domain_uuid'];
    $domain_name = $_SESSION['domain_name']; // <-- DEZE MIS JE

    $voicemail = new voicemail;
    $voicemail->domain_uuid = $domain_uuid;
    $voicemail->type = 'bin';
    $voicemail->voicemail_id = $_REQUEST['id'];
    $voicemail->voicemail_uuid = $_REQUEST['voicemail_uuid'];
    $voicemail->voicemail_message_uuid = $_REQUEST['uuid'];

    if (!$voicemail->message_download($domain_name)) {
        echo "unable to download voicemail";
    }

    unset($voicemail);
    exit;

	}



    else if(
		$getAction == "getcallrouting" &&
		isset($_GET['ext'])
	)
    {
    
			$sql = "select outbound_caller_id_number, do_not_disturb, forward_all_enabled, forward_all_destination, forward_busy_enabled, forward_busy_destination, forward_no_answer_enabled, forward_no_answer_destination from v_extensions ";
			$sql .= "where domain_uuid = '$domain_uuid' ";
			$sql .= "and extension = '" . check_str($_GET['ext']) . "' ";
				if (count($_SESSION['user']['extension']) > 0) {
					$sql .= "and (";
					$x = 0;
					foreach($_SESSION['user']['extension'] as $row) {
						if ($x > 0) { $sql .= "or "; }
						$sql .= "extension = '".$row['user']."' ";
						$x++;
					}
					$sql .= ")";
				}
				else {
					//hide any results when a user has not been assigned an extension
					$sql .= "and extension = 'disabled' ";
				}

            $database = new database;
            $result = $database->select($sql, null, 'all');
            unset($parameters);  

			if(($_GET['device'] ?? '') == "mobile")
			{
				if(($result[0]["outbound_caller_id_number"] ?? null) == null)
					$result[0]["outbound_caller_id_number"] = "";

				$result[0]["do_not_disturb"] = filter_var($result[0]["do_not_disturb"] ?? false, FILTER_VALIDATE_BOOLEAN);

				
				$result[0]["forward_all_enabled"] = filter_var($result[0]["forward_all_enabled"] ?? false, FILTER_VALIDATE_BOOLEAN);

				if(($result[0]["forward_all_destination"] ?? null) == null)
					$result[0]["forward_all_destination"] = "";
	

				$result[0]["forward_busy_enabled"] = filter_var($result[0]["forward_busy_enabled"] ?? false, FILTER_VALIDATE_BOOLEAN);
	
				if(($result[0]["forward_busy_destination"] ?? null) == null)
					$result[0]["forward_busy_destination"] = "";


				$result[0]["forward_no_answer_enabled"] = filter_var($result[0]["forward_no_answer_enabled"] ?? false, FILTER_VALIDATE_BOOLEAN);

				if(($result[0]["forward_no_answer_destination"] ?? null) == null )
					$result[0]["forward_no_answer_destination"] = "";
			}

			if(($_GET['device'] ?? '') == "web")
				echo json_encode($result[0] ?? null);
			else
				echo json_encode($result[0] ?? null, JSON_FORCE_OBJECT);

    } 
    else if(
        $getAction == "setoutboundcallerid" && 
        isset($_GET['extension_uuid']) &&
        isset($_GET['extension']) &&
        isset($_GET['number']) 
    ) {
		
        $extension_uuid = check_str($_GET['extension_uuid']);
        $extension = check_str($_GET['extension']);
        $outbound_caller_id_number = check_str($_GET['number']);

        $extensions['domain_uuid'] = $_SESSION['domain_uuid'];
        $extensions['extension_uuid'] = $extension_uuid;
        $extensions['outbound_caller_id_number'] = $outbound_caller_id_number;

        $array['extensions'][] = $extensions;
        

    //add the dialplan permission
if (method_exists('permissions', 'new')) {
    $p = permissions::new();   // nieuwe Fusion
} else {
    $p = new permissions;      // oude Fusion
}
        $p->add("extension_edit", "temp");
        
        $database = new database;
        $database->app_name = 'extensions';
        $database->app_uuid = null;
        $database->save($array);
        
    //remove the temporary permission
        $p->delete("extension_edit", "temp");

        //clear the cache
	$sql = "select extension, number_alias, user_context from v_extensions ";
	$sql .= "where extension_uuid = :extension_uuid ";
	$parameters['extension_uuid'] = $extension_uuid;
	$database = new database;
	$extension = $database->select($sql, $parameters, 'row');
	$cache = new cache;
	$cache->delete("directory:".$extension["extension"]."@".$extension["user_context"]);
	$cache->delete("directory:".$extension["number_alias"]."@".$extension["user_context"]);
    
        echo "Outbound CallerID:" . $outbound_caller_id_number;
    } else if(
        $getAction == "setdnd" && 
        isset($_GET['extension_uuid']) &&
        isset($_GET['extension']) &&
        isset($_GET['status']) 
    ) {

$extension_uuid = check_str($_GET['extension_uuid'] ?? '');
$extension = check_str($_GET['extension'] ?? '');

// boolean fix
$dnd_enabled = filter_var($_GET['status'] ?? false, FILTER_VALIDATE_BOOLEAN);
$dnd_enabled = $dnd_enabled ? 'true' : 'false';

// DND object (zoals origineel)
$dnd = new do_not_disturb;
$dnd->domain_uuid = $_SESSION['domain_uuid'];
$dnd->domain_name = $_SESSION['domain_name'];
$dnd->extension_uuid = $extension_uuid;
$dnd->extension = $extension;
$dnd->enabled = $dnd_enabled;
$dnd->set();
$dnd->user_status();
unset($dnd);

	// Clear cache
	$sql = "select extension, number_alias, user_context from v_extensions ";
	$sql .= "where extension_uuid = :extension_uuid ";
	$parameters['extension_uuid'] = $extension_uuid;
	$database = new database;
	$extension = $database->select($sql, $parameters, 'row');
	$cache = new cache;
	$cache->delete("directory:".$extension["extension"]."@".$extension["user_context"]);
	$cache->delete("directory:".$extension["number_alias"]."@".$extension["user_context"]);

echo "DND:" . $dnd_enabled;

    } else if(
        $getAction == "setforwardall" && 
        isset($_GET['extension_uuid']) &&
        isset($_GET['dest']) &&
        isset($_GET['status']) 
    ) {

$extension_uuid = check_str($_GET['extension_uuid'] ?? '');

// boolean fix
$forward_all_enabled = filter_var($_GET['status'] ?? false, FILTER_VALIDATE_BOOLEAN);
$forward_all_enabled = $forward_all_enabled ? 'true' : 'false';

// destination fix
$forward_all_destination = check_str($_GET['dest'] ?? '');

// array fix (index 0 gebruiken, geen [])
$array = [];
$array['extensions'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
$array['extensions'][0]['extension_uuid'] = $extension_uuid;
$array['extensions'][0]['forward_all_enabled'] = $forward_all_enabled;
$array['extensions'][0]['forward_all_destination'] = $forward_all_destination;

// permissions
if (method_exists('permissions', 'new')) {
    $p = permissions::new();   // nieuwe Fusion
} else {
    $p = new permissions;      // oude Fusion
}
$p->add("extension_edit", "temp");

// save
$database = new database;
$database->app_name = 'call_routing';
$database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
$database->save($array);

// permission cleanup
$p->delete("extension_edit", "temp");

// cache clear
	$sql = "select extension, number_alias, user_context from v_extensions ";
	$sql .= "where extension_uuid = :extension_uuid ";
	$parameters['extension_uuid'] = $extension_uuid;
	$database = new database;
	$extension = $database->select($sql, $parameters, 'row');
	$cache = new cache;
	$cache->delete("directory:".$extension["extension"]."@".$extension["user_context"]);
	$cache->delete("directory:".$extension["number_alias"]."@".$extension["user_context"]);

echo "Forward ALL:" . $forward_all_enabled;

    } else if($getAction == "setbusy" && 
        isset($_GET['extension_uuid']) &&
        isset($_GET['dest']) &&
        isset($_GET['status']) ) {

$extension_uuid = check_str($_GET['extension_uuid'] ?? '');

// boolean fix
$forward_busy_enabled = filter_var($_GET['status'] ?? false, FILTER_VALIDATE_BOOLEAN);
$forward_busy_enabled = $forward_busy_enabled ? 'true' : 'false';

// destination
$forward_busy_destination = check_str($_GET['dest'] ?? '');

// juiste sanitizing (Fusion verwacht dit)
$forward_busy_destination = preg_replace('#[^\*0-9]#', '', $forward_busy_destination);

// jouw +31 logica
if (strpos($forward_busy_destination, '0') === 0) {
    $forward_busy_destination = "31" . ltrim($forward_busy_destination, "0");
}

// array structuur fix
$array = [];
$array['extensions'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
$array['extensions'][0]['extension_uuid'] = $extension_uuid;
$array['extensions'][0]['forward_busy_enabled'] = $forward_busy_enabled;
$array['extensions'][0]['forward_busy_destination'] = $forward_busy_destination;

// permissions (juiste manier)
if (method_exists('permissions', 'new')) {
    $p = permissions::new();   // nieuwe Fusion
} else {
    $p = new permissions;      // oude Fusion
}
$p->add("extension_edit", "temp");

// save
$database = new database;
$database->app_name = 'call_routing';
$database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
$database->save($array);

// permission cleanup
$p->delete("extension_edit", "temp");

	// clear cache
	$sql = "select extension, number_alias, user_context from v_extensions ";
	$sql .= "where extension_uuid = :extension_uuid ";
	$parameters['extension_uuid'] = $extension_uuid;
	$database = new database;
	$extension = $database->select($sql, $parameters, 'row');
	$cache = new cache;
	$cache->delete("directory:".$extension["extension"]."@".$extension["user_context"]);
	$cache->delete("directory:".$extension["number_alias"]."@".$extension["user_context"]);

	echo "Forward BUSY:" . $forward_busy_enabled;
    } else if(
        $getAction == "setnoanswer" && 
        isset($_GET['extension_uuid']) &&
        isset($_GET['dest']) &&
        isset($_GET['status']) 
    ) {

$extension_uuid = check_str($_GET['extension_uuid'] ?? '');

// boolean fix
$forward_no_answer_enabled = filter_var($_GET['status'] ?? false, FILTER_VALIDATE_BOOLEAN);
$forward_no_answer_enabled = $forward_no_answer_enabled ? 'true' : 'false';

// destination
$forward_no_answer_destination = check_str($_GET['dest'] ?? '');

// juiste sanitizing (Fusion verwacht dit)
$forward_no_answer_destination = preg_replace('#[^\*0-9]#', '', $forward_no_answer_destination);

// jouw +31 logica
if (strpos($forward_no_answer_destination, '0') === 0) {
    $forward_no_answer_destination = "31" . ltrim($forward_no_answer_destination, "0");
}

// array structuur fix
$array = [];
$array['extensions'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
$array['extensions'][0]['extension_uuid'] = $extension_uuid;
$array['extensions'][0]['forward_no_answer_enabled'] = $forward_no_answer_enabled;
$array['extensions'][0]['forward_no_answer_destination'] = $forward_no_answer_destination;

// permissions (juiste manier)
if (method_exists('permissions', 'new')) {
    $p = permissions::new();   // nieuwe Fusion
} else {
    $p = new permissions;      // oude Fusion
}
$p->add("extension_edit", "temp");

// save
$database = new database;
$database->app_name = 'call_routing';
$database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
$database->save($array);

// permission cleanup
$p->delete("extension_edit", "temp");

	// Clear cache
	$sql = "select extension, number_alias, user_context from v_extensions ";
	$sql .= "where extension_uuid = :extension_uuid ";
	$parameters['extension_uuid'] = $extension_uuid;
	$database = new database;
	$extension = $database->select($sql, $parameters, 'row');
	$cache = new cache;
	$cache->delete("directory:".$extension["extension"]."@".$extension["user_context"]);
	$cache->delete("directory:".$extension["number_alias"]."@".$extension["user_context"]);

    echo "Forward NOANSWER:" . $forward_no_answer_enabled;

    }
    else if($getAction == "contacts")
    {

        $sql = "SELECT 
                    v_contacts.contact_uuid, contact_name_given,contact_name_middle,contact_name_family,contact_organization,
        
                    (SELECT contact_phone_uuid FROM v_contact_phones WHERE v_contact_phones.contact_uuid = v_contacts.contact_uuid AND phone_label <> 'mobile' ORDER BY phone_primary ASC LIMIT 1 ) AS contact_work_uuid,
                    (SELECT phone_number FROM v_contact_phones WHERE v_contact_phones.contact_uuid = v_contacts.contact_uuid AND phone_label <> 'mobile' ORDER BY phone_primary ASC LIMIT 1 ) AS contact_work_number,
                    
                    (SELECT contact_phone_uuid FROM v_contact_phones WHERE v_contact_phones.contact_uuid = v_contacts.contact_uuid AND phone_label = 'mobile' LIMIT 1 ) AS contact_mobile_uuid,
                    (SELECT phone_number FROM v_contact_phones WHERE v_contact_phones.contact_uuid = v_contacts.contact_uuid AND phone_label = 'mobile' LIMIT 1 ) AS contact_mobile_number,
                
                    v_contact_users.user_uuid 
                
                FROM 
                    v_contacts             
                LEFT OUTER JOIN 
                    v_contact_users on v_contacts.contact_uuid = v_contact_users.contact_uuid AND v_contact_users.domain_uuid = '" . $_SESSION['domain_uuid'] . "'	
                WHERE 
                    v_contacts.domain_uuid = '".$_SESSION['domain_uuid']."' AND (v_contact_users.user_uuid = '" . $_SESSION['user_uuid'] . "' OR v_contact_users.user_uuid IS NULL)
                ORDER BY 
                    contact_name_given 
                ASC";

        $database = new database;
        $contacts = $database->select($sql, null, 'all');
        unset($parameters);  
	
		//build the response
		$x = 0;
		foreach((array) $contacts as &$row) {

			//add the extension details
            if($row['contact_name_middle'] == null)
                $row['contact_name_middle'] = '';
			$array[$x] = $row;

			//increment the row
			$x++;
		}

//reindex array using extension instead of auto-incremented value

		foreach (($array ?? []) as $index => $subarray) {
			foreach ($subarray as $field => $value) {
				$array[$subarray['contact_uuid']][$field] = $array[$index][$field];
				unset($array[$index][$field]);
			}
			unset($array[$subarray['contact_uuid']]['contact_uuid']);
			unset($array[$index]);
		}

        echo json_encode($array ?? null);
    } 
	else if($getAction == "c2c")
    {
        $sourceInput = $_REQUEST['src'] ?? '';
        $destinationInput = $_REQUEST['dest'] ?? '';
        $sourceExtension = $_REQUEST['src_ext'] ?? '';
        if (!is_string($sourceInput) || !is_string($destinationInput) || !is_string($sourceExtension)
            || !isset($byNumber[$sourceExtension])) {
            callassist_mobile_deny(403);
        }
        $src = check_str($sourceInput);
        $src = str_replace(array('.', '(', ')', '-', ' ', '+'), '', $src);

        $src_ext = check_str($_REQUEST['src_ext']);
        
        $dest = urldecode(check_str($_REQUEST['dest']));
        $dest = str_replace(array('.', '(', ')', '-', ' ', '+'), '', $dest); //strip the periods for phone numbers.
        if (!preg_match('/\A[0-9*#]{1,32}\z/', $src)
            || !preg_match('/\A[0-9*#]{1,32}\z/', $dest)
            || (strlen($src) < 7 && !isset($byNumber[$src]))) {
            callassist_mobile_deny(403);
        }
        
        $src_cid_name = "CallAssistMobileCall";
                
        $context = $_SESSION['context'];
        
        $sql = "select outbound_caller_id_number from v_extensions ";
        $sql .= "where domain_uuid = '".$_SESSION['domain_uuid']."' ";
        $sql .= "and extension = '$src_ext' ";

        $database = new database;
        $result = $database->select($sql, null, 'all');
        unset($parameters);  

        foreach ((array) $result as &$row) {
                $src_cid_number = $row["outbound_caller_id_number"];
                $dest_cid_number = $row["outbound_caller_id_number"];
            break; //limit to 1 row
        }
        unset ($prep_statement);	


        $ringback_value = "\'%(2000,4000,440.0,480.0)\'";

        if (strlen($src) < 7 ) {
            $source = "{originate_timeout=45,click_to_call=true,origination_caller_id_name='$src_cid_name',origination_caller_id_number=$src_cid_number,instant_ringback=true,ringback=$ringback_value,presence_id=$src@".$_SESSION['domain_name'].",call_direction=outbound,domain_uuid=".$domain_uuid.",domain_name=".$_SESSION['domain_name']."}user/$src@".$_SESSION['domain_name'];       
            $switch_cmd = "bgapi originate $source &transfer('".$dest." XML ".$context."')";        
        }
        else {
            
            $bridge_array = outbound_route_to_bridge ($_SESSION['domain_uuid'], $dest);
            $destination = "{originate_timeout=45,origination_caller_id_number=$src_cid_number}" . ($bridge_array[0] ?? "");
        
            $bridge_array = outbound_route_to_bridge ($_SESSION['domain_uuid'], $src);
            $source = "{originate_timeout=45,ignore_early_media=true,effective_caller_id_name='$src_cid_number',origination_caller_id_number=$src_cid_number}" . $bridge_array[0];
            
            $switch_cmd = "bgapi originate $source &bridge($destination)";
        }
        
        if (!preg_match('/[\r\n\x00]/', $switch_cmd)) { event_socket::command($switch_cmd); }
        echo "Request dispatched";

    } else if ($getAction == "registerdevice") {

      $device_id = check_str($_GET['deviceid'] ?? '');
    $token = check_str($_GET['token'] ?? '');

    if (empty($device_id) || empty($token)) {
        echo "missing params";
        exit;
    }

    $setting_subcategory = "device_" . $device_id;

    $sql = "select user_setting_uuid 
            from v_user_settings 
            where domain_uuid = :domain_uuid
            and user_uuid = :user_uuid
            and user_setting_category = 'callassist'
            and user_setting_subcategory = :setting_subcategory
            and user_setting_name = 'text'";

    $parameters = [];
    $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
    $parameters['user_uuid'] = $_SESSION['user_uuid'];
    $parameters['setting_subcategory'] = $setting_subcategory;

    $database = new database;
    $row = $database->select($sql, $parameters, 'row');

    $array = [];

    if (!empty($row['user_setting_uuid'])) {
        $array['user_settings'][0]['user_setting_uuid'] = $row['user_setting_uuid'];
    } else {
        $array['user_settings'][0]['user_setting_uuid'] = uuid();
        $array['user_settings'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
        $array['user_settings'][0]['user_uuid'] = $_SESSION['user_uuid'];
        $array['user_settings'][0]['user_setting_category'] = 'callassist';
        $array['user_settings'][0]['user_setting_subcategory'] = $setting_subcategory;
        $array['user_settings'][0]['user_setting_name'] = 'text';
        $array['user_settings'][0]['user_setting_enabled'] = 'true';
    }

    $array['user_settings'][0]['user_setting_value'] = $token;

    $database = new database;
    $database->save($array);

    echo "registered";
} else if ($getAction == "unregisterdevice") {

     $device_id = check_str($_GET['deviceid'] ?? '');

    if (empty($device_id)) {
        echo "missing device_id";
        exit;
    }

    $setting_subcategory = "device_" . $device_id;

    $sql = "select user_setting_uuid 
            from v_user_settings 
            where domain_uuid = :domain_uuid
            and user_uuid = :user_uuid
            and user_setting_category = 'callassist'
            and user_setting_subcategory = :setting_subcategory
            and user_setting_name = 'text'";

    $parameters = [];
    $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
    $parameters['user_uuid'] = $_SESSION['user_uuid'];
    $parameters['setting_subcategory'] = $setting_subcategory;

    $database = new database;
    $row = $database->select($sql, $parameters, 'row');

    if (!empty($row['user_setting_uuid'])) {
        $array = [];
        $array['user_settings'][0]['user_setting_uuid'] = $row['user_setting_uuid'];

        $database = new database;
        $database->delete($array);
    }

    echo "unregistered";

} else {

 //return user details
        // Performing SQL query
	$sql = "SELECT 
            v_users.username,
            v_extensions.extension,
            v_extensions.extension_uuid,
            v_extensions.outbound_caller_id_number,
            '" . $_SESSION['domain_name'] . "' as accountcode,
            v_extensions.enabled,
            v_extensions.description,
            v_voicemails.voicemail_enabled
        FROM
            v_extensions
        JOIN v_extension_users 
            ON v_extensions.extension_uuid = v_extension_users.extension_uuid
        JOIN v_users 
            ON v_extension_users.user_uuid = v_users.user_uuid
        LEFT JOIN v_voicemails 
            ON v_voicemails.voicemail_id = COALESCE(NULLIF(v_extensions.number_alias, ''), v_extensions.extension)
            AND v_voicemails.domain_uuid = v_extensions.domain_uuid
        WHERE 
            v_users.user_uuid = :user_uuid AND
            v_extensions.domain_uuid = :domain_uuid
        ORDER BY
            v_extensions.extension ASC;
	";	
         
        $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
        $parameters['user_uuid'] = $_SESSION['user_uuid'];
        $database = new database;
        $extensions = $database->select($sql, $parameters, 'all');

        unset($parameters);                

        // Get User settings
        $sql = "SELECT user_setting_subcategory, user_setting_name, user_setting_value
                FROM
                    v_user_settings
                WHERE 
                    user_uuid = :user_uuid AND
                    domain_uuid = :domain_uuid AND
                    user_setting_category = 'callassist';";	

        $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
        $parameters['user_uuid'] = $_SESSION['user_uuid'];
        $database = new database;
        $usersettings = $database->select($sql, $parameters, 'all');

        unset($parameters);  

        $usersettingsnew = array();
	 $usersettingsnew["numbers"] = array();	
        foreach ((array) $usersettings as $setting)
        {

            if($setting["user_setting_subcategory"] == "numbers" && !empty($setting["user_setting_value"]) && !in_array($setting["user_setting_value"], $usersettingsnew["numbers"]))
	     {
	        $raw = (string)$setting["user_setting_value"];

	        foreach (explode(',', $raw) as $value) {
	            $value = trim($value);

       	     if ($value !== '' && !in_array($value, $usersettingsnew["numbers"], true)) {
	                $usersettingsnew["numbers"][] = $value;
       	     }
	        }
	    }
        }		

        foreach ((array) $extensions as $extension)
        {
            if(!empty($extension["outbound_caller_id_number"])  && !in_array($extension["outbound_caller_id_number"], $usersettingsnew["numbers"]))
                $usersettingsnew["numbers"][] = $extension["outbound_caller_id_number"];
        }
    
        $extensionsnew = array();
        foreach ((array) $extensions as $extension)
        {
            $extension["settings"] = $usersettingsnew;
            $extension["voicemail_enabled"] = filter_var($extension["voicemail_enabled"], FILTER_VALIDATE_BOOLEAN);
            $extension["enabled"] = filter_var($extension["enabled"], FILTER_VALIDATE_BOOLEAN);
            if($extension["outbound_caller_id_number"] == null )
                $extension["outbound_caller_id_number"] = "";
            $extensionsnew[] = $extension;
        }

        echo json_encode($extensionsnew);
    
    }
