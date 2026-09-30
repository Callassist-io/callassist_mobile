<?php

//error reporting
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

	header('HTTP/1.1 200 OK', true, 200);	
    $action = $_REQUEST['action'];

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

    foreach ($settings_row as $setting) {
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

    foreach ($result as $call) {

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
		$_GET['action'] == "download" &&
		isset($_GET['id'])
	)
    {

	$obj = new xml_cdr;
	$obj->recording_uuid = $_GET['id'];
	$obj->binary = isset($_GET['t']) && $_GET['t'] == 'bin' ? true : false;
	$obj->download();


    }	else if(
		$_GET['action'] == "voicemail"
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
	$vm->order_by = $order_by;
	$vm->order = $order;
	$voicemails = $vm->messages();

	$result = [];

	foreach ($voicemails as $vm) {
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
		$_GET['action'] == "downloadvoicemail"
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
		$_GET['action'] == "getcallrouting" &&
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
            $result = $database->select($sql, $null, 'all');
            unset($parameters);  

			if($_GET['device'] == "mobile")
			{
				if($result[0]["outbound_caller_id_number"] == null)
					$result[0]["outbound_caller_id_number"] = "";

				$result[0]["do_not_disturb"] = filter_var($result[0]["do_not_disturb"], FILTER_VALIDATE_BOOLEAN);

				
				$result[0]["forward_all_enabled"] = filter_var($result[0]["forward_all_enabled"], FILTER_VALIDATE_BOOLEAN);

				if($result[0]["forward_all_destination"] == null)
					$result[0]["forward_all_destination"] = "";
	

				$result[0]["forward_busy_enabled"] = filter_var($result[0]["forward_busy_enabled"], FILTER_VALIDATE_BOOLEAN);
	
				if($result[0]["forward_busy_destination"] == null)
					$result[0]["forward_busy_destination"] = "";


				$result[0]["forward_no_answer_enabled"] = filter_var($result[0]["forward_no_answer_enabled"], FILTER_VALIDATE_BOOLEAN);

				if($result[0]["forward_no_answer_destination"] == null )
					$result[0]["forward_no_answer_destination"] = "";
			}

			if($_GET['device'] == "web")
				echo json_encode($result[0]);
			else
				echo json_encode($result[0], JSON_FORCE_OBJECT);

    } 
    else if(
        $_GET['action'] == "setoutboundcallerid" && 
        isset($_GET['extension_uuid']) &&
        isset($_GET['extension']) &&
        isset($_GET['number']) 
    ) {
		
        $extension_uuid = check_str($_GET['extension_uuid']);
        $extension = check_str($_GET['extension']);
        $outbound_caller_id_number = check_str($_GET['number']);

        //$extensions['domain_uuid'] = $_SESSION['domain_uuid'];
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
        $_GET['action'] == "setdnd" && 
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
        $_GET['action'] == "setforwardall" && 
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

    } else if($_GET['action'] == "setbusy" && 
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
        $_GET['action'] == "setnoanswer" && 
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
    else if($_GET['action'] == "contacts")
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
        $contacts = $database->select($sql, $null, 'all');
        unset($parameters);  
	
		//build the response
		$x = 0;
		foreach($contacts as &$row) {

			//add the extension details
            if($row['contact_name_middle'] == null)
                $row['contact_name_middle'] = '';
			$array[$x] = $row;

			//increment the row
			$x++;
		}

//reindex array using extension instead of auto-incremented value

		foreach ($array as $index => $subarray) {
			foreach ($subarray as $field => $value) {
				$array[$subarray['contact_uuid']][$field] = $array[$index][$field];
				unset($array[$index][$field]);
			}
			unset($array[$subarray['contact_uuid']]['contact_uuid']);
			unset($array[$index]);
		}

        echo json_encode($array);
    } 
	else if($_GET['action'] == "c2c")
    {
        $src = check_str($_REQUEST['src']);
        $src = str_replace(array('.', '(', ')', '-', ' ', '+'), '', $src);

        $src_ext = check_str($_REQUEST['src_ext']);
        
        $dest = urldecode(check_str($_REQUEST['dest']));
        $dest = str_replace(array('.', '(', ')', '-', ' ', '+'), '', $dest); //strip the periods for phone numbers.
        
        $src_cid_name = "CallAssistMobileCall";
                
        $context = $_SESSION['context'];
        
        $sql = "select outbound_caller_id_number from v_extensions ";
        $sql .= "where domain_uuid = '".$_SESSION['domain_uuid']."' ";
        $sql .= "and extension = '$src_ext' ";

        $database = new database;
        $result = $database->select($sql, $null, 'all');
        unset($parameters);  

        foreach ($result as &$row) {
                $src_cid_number = $row["outbound_caller_id_number"];
                $dest_cid_number = $row["outbound_caller_id_number"];
            break; //limit to 1 row
        }
        unset ($prep_statement);	


        $ringback_value = "\'%(2000,4000,440.0,480.0)\'";

        if (strlen($src) < 7 ) {
            $source = "{originate_timeout=45,click_to_call=true,origination_caller_id_name='$src_cid_name',origination_caller_id_number=$src_cid_number,instant_ringback=true,ringback=$ringback_value,presence_id=$src@".$_SESSION['domains'][$domain_uuid]['domain_name'].",call_direction=outbound,domain_uuid=".$domain_uuid.",domain_name=".$_SESSION['domains'][$domain_uuid]['domain_name']."}user/$src@".$_SESSION['domains'][$domain_uuid]['domain_name'];       
            $switch_cmd = "api originate $source &transfer('".$dest." XML ".$context."')";        
        }
        else {
            
            $bridge_array = outbound_route_to_bridge ($_SESSION['domain_uuid'], $dest);
            $destination = "{originate_timeout=45,origination_caller_id_number=$src_cid_number}" . $bridge_array[0];
        
            $bridge_array = outbound_route_to_bridge ($_SESSION['domain_uuid'], $src);
            $source = "{originate_timeout=45,ignore_early_media=true,effective_caller_id_name='$src_cid_number',origination_caller_id_number=$src_cid_number}" . $bridge_array[0];
            
            $switch_cmd = "api originate $source &bridge($destination)";
        }
        
        echo exec('php resources/c2c_socket.php -i "'.$_SESSION['event_socket_ip_address'].'" -p "'.$_SESSION['event_socket_port'].'" -w "'.$_SESSION['event_socket_password'].'" -c "'.$switch_cmd.'" > /dev/null &');
        echo "Request dispatched";

    } else if ($_GET['action'] == "registerdevice") {

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
} else if ($_GET['action'] == "unregisterdevice") {

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
        foreach ($usersettings as $setting)
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

        foreach ($extensions as $extension)
        {
            if(!empty($extension["outbound_caller_id_number"])  && !in_array($extension["outbound_caller_id_number"], $usersettingsnew["numbers"]))
                $usersettingsnew["numbers"][] = $extension["outbound_caller_id_number"];
        }
    
        $extensionsnew = array();
        foreach ($extensions as $extension)
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
