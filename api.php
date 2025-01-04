<?php


//error reporting
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//	header('HTTP/1.1 200 OK', true, 200);	
    $action = $_REQUEST['action'];

    if($action == "cdr")
    {
			
        //set 24hr or 12hr clock
        define('TIME_24HR', 1);


        $limit = 50;

        $sql_where_ors = array();
        
        foreach ($_SESSION['user']['extension'] as $row) { 
            if(!empty($row['extension_uuid']))
                $sql_where_ors[] = "extension_uuid = '" . $row['extension_uuid'] . "'"; 
        }
        $sql = "SELECT
                    xml_cdr_uuid as uuid,
                    caller_id_number,
                    destination_number,
                    json
                FROM 
                    v_xml_cdr 
                WHERE

                    domain_uuid = '".$domain_uuid."' AND
                    hangup_cause <> 'LOSE_RACE' AND
                    (cc_side is null or cc_side != 'agent') AND

                    " . "( ".implode(" OR ", $sql_where_ors)." )" . "

                ORDER BY 
                    start_stamp DESC 
                LIMIT 
                    " . $limit . "
                OFFSET 
                    0";
 		
		$prep_statement = $db->prepare(check_sql($sql));
		$prep_statement->execute();
		$result = $prep_statement->fetchAll(PDO::FETCH_ASSOC);
		$result_count = count($result);
		unset ($prep_statement, $sql);
		
		$resultnew = array();
		
		foreach ($result as $call) {
			$callJson = json_decode($call["json"]);
			
			$callJson = $callJson->variables;
			
			$newline = array();
			
			$newline["uuid"] = $call["uuid"];
			$newline["start_stamp"] = urldecode($callJson->start_stamp);
			if(!empty($callJson->call_direction))
				$newline["direction"] = $callJson->call_direction;
			else
				$newline["direction"] = $callJson->direction;
			
			if(!empty($callJson->caller_id_name))
				$newline["caller_id_name"] = iconv("UTF-8","UTF-8//IGNORE",urldecode($callJson->caller_id_name));
			else
				$newline["caller_id_name"] = iconv("UTF-8","UTF-8//IGNORE",urldecode($callJson->origination_caller_id_name));
			
			if(!empty($callJson->caller_id_number))
				$newline["caller_id_number"] = $callJson->caller_id_number;
			else
				$newline["caller_id_number"] = $call["caller_id_number"];

			if(!empty($callJson->caller_destination))
				$newline["destination_number"] = $callJson->caller_destination;
			else
				$newline["destination_number"] = $call["destination_number"];			
				
			$newline["hangup_cause"] = $callJson->hangup_cause;
			$newline["duration"] = $callJson->duration;
			
			$resultnew[] = $newline;
		}
		
		$result = $resultnew;
		$result_count = count($result);


			
		echo json_encode($result, JSON_FORCE_OBJECT);

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
			
			$prep_statement = $db->prepare(check_sql($sql));
			$prep_statement->execute();
			$result = $prep_statement->fetchAll(PDO::FETCH_NAMED);

			//echo json_encode($result[0], JSON_FORCE_OBJECT);

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
        $p = new permissions;
        $p->add("extension_edit", "temp");
        
        $database = new database;
        $database->app_name = 'extensions';
        $database->app_uuid = null;
        $database->save($array);
        
    //remove the temporary permission
        $p->delete("extension_edit", "temp");

        //clear the cache
        $cache = new cache;
        $cache->delete("directory:".$extension."@".$_SESSION['domain_name']);
    
        echo "Outbound CallerID:" . $outbound_caller_id_number;
    } else if(
        $_GET['action'] == "setdnd" && 
        isset($_GET['extension_uuid']) &&
        isset($_GET['extension']) &&
        isset($_GET['status']) 
    ) {


        $extension_uuid = check_str($_GET['extension_uuid']);
        $extension = check_str($_GET['extension']);
        $dnd_enabled = check_str($_GET['status']);
	 if(!($dnd_enabled == "true"))
		$dnd_enabled = "false";



        $dnd = new do_not_disturb;
        $dnd->domain_uuid = $_SESSION['domain_uuid'];
        $dnd->domain_name = $_SESSION['domain_name'];
        $dnd->extension_uuid = $extension_uuid;
        $dnd->extension = $extension;
        $dnd->enabled = $dnd_enabled;
        $dnd->set();
        $dnd->user_status();
        unset($dnd);

        //clear the cache
        $cache = new cache;
        $cache->delete("directory:".$extension."@".$_SESSION['domain_name']);
        if(strlen($number_alias) > 0){
            $cache->delete("directory:".$number_alias."@".$_SESSION['domain_name']);
        }


        echo "DND:" . $dnd_enabled;


    } else if(
        $_GET['action'] == "setforwardall" && 
        isset($_GET['extension_uuid']) &&
        isset($_GET['dest']) &&
        isset($_GET['status']) 
    ) {

        $extension_uuid = check_str($_GET['extension_uuid']);
        $forward_all_enabled = check_str($_GET['status']);
	 if(!($forward_all_enabled == "true"))
		$forward_all_enabled = "false";
        $forward_all_destination = check_str($_GET['dest']);


        //$forward_all_destination = preg_replace('#[^\*0-9]#', '', $forward_all_destination);
        $forward_all_destination = str_replace("+", "", $forward_all_destination);
        if (strpos($forward_all_destination, '0') === 0)
            $forward_all_destination = "31" . ltrim($forward_all_destination, "0");


        $extensions['domain_uuid'] = $_SESSION['domain_uuid'];
        $extensions['extension_uuid'] = $extension_uuid;
        $extensions['forward_all_enabled'] = $forward_all_enabled;
        $extensions['forward_all_destination'] = $forward_all_destination;


        $array['extensions'][] = $extensions;


        //add the dialplan permission
        $p = new permissions;
        $p->add("extension_edit", "temp");

        $database = new database;
        $database->app_name = 'call_routing';
        $database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
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
        $cache->delete("directory:".$extension["extension"]."@".$_SESSION['domain_name']);
        $cache->delete("directory:".$extension["number_alias"]."@".$_SESSION['domain_name']);



        echo "Forward ALL:" . $forward_all_enabled;


        } else if($_GET['action'] == "setbusy" && 
            isset($_GET['extension_uuid']) &&
            isset($_GET['dest']) &&
            isset($_GET['status']) ) {


        $extension_uuid = check_str($_GET['extension_uuid']);
        $forward_busy_enabled = check_str($_GET['status']);
	 if(!($forward_busy_enabled == "true"))
		$forward_busy_enabled = "false";
        $forward_busy_destination = check_str($_GET['dest']);


        $forward_busy_destination = str_replace("+", "", $forward_busy_destination);
        if (strpos($forward_busy_destination, '0') === 0)
            $forward_busy_destination = "31" . ltrim($forward_busy_destination, "0");


        $extensions['domain_uuid'] = $_SESSION['domain_uuid'];
        $extensions['extension_uuid'] = $extension_uuid;
        $extensions['forward_busy_enabled'] = $forward_busy_enabled;
        $extensions['forward_busy_destination'] = $forward_busy_destination;

        $array['extensions'][] = $extensions;


        //add the dialplan permission
        $p = new permissions;
        $p->add("extension_edit", "temp");

        $database = new database;
        $database->app_name = 'call_routing';
        $database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
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
        $cache->delete("directory:".$extension["extension"]."@".$_SESSION['domain_name']);
        $cache->delete("directory:".$extension["number_alias"]."@".$_SESSION['domain_name']);

        echo "Forward BUSY:" . $forward_busy_enabled;

    } else if(
        $_GET['action'] == "setnoanswer" && 
        isset($_GET['extension_uuid']) &&
        isset($_GET['dest']) &&
        isset($_GET['status']) 
    ) {


        $extension_uuid = check_str($_GET['extension_uuid']);
        $forward_no_answer_enabled = check_str($_GET['status']);
	 if(!($forward_no_answer_enabled == "true"))
		$forward_no_answer_enabled = "false";
        $forward_no_answer_destination = check_str($_GET['dest']);

        $forward_no_answer_destination = str_replace("+", "", $forward_no_answer_destination);
        if (strpos($forward_no_answer_destination, '0') === 0)
            $forward_no_answer_destination = "31" . ltrim($forward_no_answer_destination, "0");


        $extensions['domain_uuid'] = $_SESSION['domain_uuid'];
        $extensions['extension_uuid'] = $extension_uuid;
        $extensions['forward_no_answer_enabled'] = $forward_no_answer_enabled;
        $extensions['forward_no_answer_destination'] = $forward_no_answer_destination;

        $array['extensions'][] = $extensions;


        //add the dialplan permission
        $p = new permissions;
        $p->add("extension_edit", "temp");

        $database = new database;
        $database->app_name = 'call_routing';
        $database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
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
        $cache->delete("directory:".$extension["extension"]."@".$_SESSION['domain_name']);
        $cache->delete("directory:".$extension["number_alias"]."@".$_SESSION['domain_name']);



        echo "Forward NOANSWER:" . $forward_no_answer_enabled;

    }
    else if($_GET['action'] == "contacts")
    {

	$sql = "SELECT v_contacts.contact_uuid, contact_name_given,contact_name_middle,contact_name_family,contact_organization,
	
	(SELECT contact_phone_uuid FROM v_contact_phones WHERE v_contact_phones.contact_uuid = v_contacts.contact_uuid AND phone_label <> 'Mobile' ORDER BY phone_primary ASC LIMIT 1 ) AS contact_work_uuid,
	(SELECT phone_number FROM v_contact_phones WHERE v_contact_phones.contact_uuid = v_contacts.contact_uuid AND phone_label <> 'Mobile' ORDER BY phone_primary ASC LIMIT 1 ) AS contact_work_number,
	
	(SELECT contact_phone_uuid FROM v_contact_phones WHERE v_contact_phones.contact_uuid = v_contacts.contact_uuid AND phone_label = 'Mobile' LIMIT 1 ) AS contact_mobile_uuid,
	(SELECT phone_number FROM v_contact_phones WHERE v_contact_phones.contact_uuid = v_contacts.contact_uuid AND phone_label = 'Mobile' LIMIT 1 ) AS contact_mobile_number,
	
	
	v_contact_users.user_uuid 
	
	FROM v_contacts 
	
LEFT OUTER JOIN v_contact_users on v_contacts.contact_uuid = v_contact_users.contact_uuid AND v_contact_users.domain_uuid = '" . $_SESSION['domain_uuid'] . "'	
	
	WHERE v_contacts.domain_uuid = '".$_SESSION['domain_uuid']."' AND (v_contact_users.user_uuid = '" . $_SESSION['user_uuid'] . "' OR v_contact_users.user_uuid IS NULL)
ORDER BY contact_name_given ASC";



	$prep_statement = $db->prepare($sql);
	$prep_statement->execute();
	$contacts = $prep_statement->fetchAll(PDO::FETCH_NAMED);
	
	unset ($prep_statement, $sql);
	
	

		//build the response
		$x = 0;
		foreach($contacts as &$row) {

			//add the extension details
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
    } else if($_GET['action'] == "c2c")
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
        $prep_statement = $db->prepare(check_sql($sql));
        $prep_statement->execute();
        $result = $prep_statement->fetchAll(PDO::FETCH_NAMED);
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
						v_extensions.description
					FROM
						v_extensions, v_extension_users, v_users
					WHERE 
						v_extensions.extension_uuid = v_extension_users.extension_uuid AND
						v_extension_users.user_uuid = v_users.user_uuid AND
						v_users.user_uuid = '" . $_SESSION['user_uuid'] . "' AND
						v_extensions.domain_uuid = '" . $_SESSION['domain_uuid'] . "'
					;";		
					

			$prep_statement = $db->prepare(check_sql($sql));
			$prep_statement->execute();
			$extensions = $prep_statement->fetchAll(PDO::FETCH_NAMED);




			// Get User settings
			$sql = "SELECT user_setting_subcategory, user_setting_name, user_setting_value
					FROM
						v_user_settings
					WHERE 
						v_user_settings.user_uuid = '" . $_SESSION['user_uuid'] . "' AND
						v_user_settings.domain_uuid = '" . $_SESSION['domain_uuid'] . "'";/* AND
						v_user_settings.user_setting_category = 'callassist';";		*/

		
			$prep_statement = $db->prepare(check_sql($sql));
			$prep_statement->execute();

			$usersettings = $prep_statement->fetchAll(PDO::FETCH_NAMED);

			$usersettingsnew = array();	
			//$usersettingsnew["numbers"][] = "";
			foreach ($usersettings as $setting)
			{
				if($setting["user_setting_subcategory"] == "numbers" && !empty($setting["user_setting_value"]))
					$usersettingsnew[$setting["user_setting_subcategory"]][] = $setting["user_setting_value"];
			}		

			//get the registrations
			$obj = new registrations;
			$registrations = $obj->get($profile);
		
			$extensionsnew = array();
			foreach ($extensions as $extension)
			{
				$usersettingsnew["numbers"][] = $extension["outbound_caller_id_number"];
				$extension["settings"] = $usersettingsnew;
				$extension["enabled"] = filter_var($extension["enabled"], FILTER_VALIDATE_BOOLEAN);
				if($extension["outbound_caller_id_number"] == null)
					$extension["outbound_caller_id_number"] = "";
				$extensionsnew[] = $extension;
			}

			echo json_encode($extensionsnew);
    
    }
