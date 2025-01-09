<?PHP

    //check permissions
    if (
        !permission_exists('callassist_manage') || !$callAssistIncluded ||
        empty($_REQUEST["id"]) || !is_uuid($_REQUEST["id"])
    ) 
        {
        echo "access denied";
        exit;
    }

    $user_uuid = $_REQUEST["id"];
    
//access to current user info
    $sql = "select * ";
    $sql .= "from view_users ";
    $sql .= "where user_uuid = :user_uuid ";
    $sql .= "and domain_uuid = :domain_uuid ";

    $sql .= "and ( ";
    $sql .= "	group_level <= :group_level ";
    $sql .= "	or group_level is null ";
    $sql .= ") ";

    $parameters['group_level'] = $_SESSION['user']['group_level'];
    $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
    $parameters['user_uuid'] = $user_uuid;

    $user_row = $database->select($sql, $parameters, 'row');

    if($user_row == null)
        header('Location: callassist.php');


//access to user settings info

    $user_setting_uuid = $_GET["id"];
    $settings_sql = "select user_setting_uuid, user_setting_category, user_setting_subcategory, user_setting_name, user_setting_value, user_setting_order, cast(user_setting_enabled as text), user_setting_description ";
    $settings_sql .= "from v_user_settings ";
    $settings_sql .= "where user_uuid = :user_uuid ";
    $settings_sql .= "and user_setting_category = 'callassist' ";
    //$settings_parameters['user_setting_uuid'] = $user_setting_uuid;
    $settings_parameters['user_uuid'] = $user_uuid;
    $database = new database;
    //$settings_row = $database->select($settings_sql, $settings_parameters, 'row');
    $settings_row = $database->select($settings_sql, $settings_parameters);

    $mobilePhoneNumber = "";
    $mobilePhoneNumberUuid = null;
    $extaOutboundNumbers = array();
    $extaOutboundNumbersUuid = null;

    foreach ($settings_row as $setting) {
        if($setting['user_setting_subcategory'] == 'mobilephonenumber')
        {
            $mobilePhoneNumber = $setting['user_setting_value'];
            $mobilePhoneNumberUuid = $setting['user_setting_uuid'];
        }
        if($setting['user_setting_subcategory'] == 'numbers')
        {
            $extaOutboundNumbers = array_merge($extaOutboundNumbers, explode(',',$setting['user_setting_value']));
            $extaOutboundNumbersUuid = $setting['user_setting_uuid'];
        }
    }

    //delete the outbound number from the user
	if (!empty($_GET["a"]) && $_GET["a"] == "delete" && !empty($_GET['extaoutboundnumber']) && is_uuid($user_uuid) ) 
    {
    
        if(is_uuid($extaOutboundNumbersUuid))
        {

            $extaOutboundNumbers = array_diff($extaOutboundNumbers, array($_GET['extaoutboundnumber']));

            $array['user_settings'][1]['user_setting_uuid'] = $extaOutboundNumbersUuid;
            
            $array['user_settings'][1]['user_uuid'] = $user_uuid;
            $array['user_settings'][1]['domain_uuid'] = $user_row['domain_uuid'];
            $array['user_settings'][1]['user_setting_category'] = "callassist";
            $array['user_settings'][1]['user_setting_subcategory'] = "numbers";
            $array['user_settings'][1]['user_setting_name'] = "array";
            $array['user_settings'][1]['user_setting_value'] = implode(',', $extaOutboundNumbers);
            $array['user_settings'][1]['user_setting_order'] = "000";
            $array['user_settings'][1]['user_setting_enabled'] = true;
            $array['user_settings'][1]['user_setting_description'] = "";

            $database = new database;
            $database->app_name = 'user_settings';
            $database->app_uuid = '3a3337f7-78d1-23e3-0cfd-f14499b8ed97';
            $database->save($array);


            //redirect the user
			message::add($text['message-update']);
			header("Location: callassist.php?id=".urlencode($user_uuid));
			exit;
        }
	}

    //delete the extension from the user
	if (!empty($_GET["a"]) && $_GET["a"] == "delete" && !empty($_GET['extensionuseruuid']) && is_uuid($user_uuid) ) 
    {
        if(is_uuid($_GET['extensionuseruuid']))
        {
        //delete the group from the users
            $array['extension_users'][0]['extension_user_uuid'] = $_GET['extensionuseruuid'];
            $array['extension_users'][0]['user_uuid'] = $user_uuid;

        //add temporary permission
            $p = new permissions;
            $p->add('extension_user_delete', 'temp');

        //save the array
            $database = new database;
            $database->app_name = 'extensions';
            $database->app_uuid = 'e68d9689-2769-e013-28fa-6214bf47fca3';
            $database->delete($array);
            unset($array);

        //remove temporary permission
            $p->delete('extension_user_delete', 'temp');

        //redirect the user
            message::add($text['message-update']);
            header("Location: callassist.php?id=".urlencode($user_uuid));
            exit;
        }
	}

//prepare the data
    if (!empty($_POST) && strpos($user_row['group_names'], $groupName) !== true) {

        
        $database = new database;
        $database->app_name = 'users';
        $database->app_uuid = '112124b3-95c2-5352-7e9d-d14c0b88f207';

        if($_POST['enable'] == '1') {
            $array['user_groups'][0]['user_group_uuid'] = uuid();
            $array['user_groups'][0]['domain_uuid'] = $user_row['domain_uuid'];
            $array['user_groups'][0]['group_name'] = $groupName;
            $array['user_groups'][0]['group_uuid'] = 'a9380266-748d-4afe-92a2-8e041237ab88';
            $array['user_groups'][0]['user_uuid'] = $user_uuid;


            if(!empty($_POST['api_key']) && $_POST['api_key'] == "new")
            {
                $array['users'][0]['user_uuid'] = $user_uuid;
                $array['users'][0]['domain_uuid'] = $user_row['domain_uuid'];
    
                $array['users'][0]['api_key'] = generate_password(32,3);
            }

            $database->save($array);

        } else if($_POST['disable'] == '1') {

            $array['user_groups'][0]['group_uuid'] = 'a9380266-748d-4afe-92a2-8e041237ab88';
			$array['user_groups'][0]['user_uuid'] = $user_uuid;
            $database->delete($array);
        }

        unset($array);


    //execute add or update
        if(!is_uuid($mobilePhoneNumberUuid))
            $array['user_settings'][0]['user_setting_uuid'] = uuid();
        else
            $array['user_settings'][0]['user_setting_uuid'] = $mobilePhoneNumberUuid;
        
        $array['user_settings'][0]['user_uuid'] = $user_uuid;
        $array['user_settings'][0]['domain_uuid'] = $user_row['domain_uuid'];
        $array['user_settings'][0]['user_setting_category'] = "callassist";
        $array['user_settings'][0]['user_setting_subcategory'] = "mobilephonenumber";
        $array['user_settings'][0]['user_setting_name'] = "text";
        $array['user_settings'][0]['user_setting_value'] = $_POST['mobile_phone'];
        
        $array['user_settings'][0]['user_setting_order'] = "000";
        $array['user_settings'][0]['user_setting_enabled'] = true;
        $array['user_settings'][0]['user_setting_description'] = "";


        if(!empty($_POST['outbound_number']))
        {
            $extaOutboundNumbers[] = $_POST['outbound_number'];

            if(!is_uuid($extaOutboundNumbersUuid))
                $array['user_settings'][1]['user_setting_uuid'] = uuid();
            else
                $array['user_settings'][1]['user_setting_uuid'] = $extaOutboundNumbersUuid;
            
            $array['user_settings'][1]['user_uuid'] = $user_uuid;
            $array['user_settings'][1]['domain_uuid'] = $user_row['domain_uuid'];
            $array['user_settings'][1]['user_setting_category'] = "callassist";
            $array['user_settings'][1]['user_setting_subcategory'] = "numbers";
            $array['user_settings'][1]['user_setting_name'] = "array";
            $array['user_settings'][1]['user_setting_value'] = implode(',', $extaOutboundNumbers);
            $array['user_settings'][1]['user_setting_order'] = "000";
            $array['user_settings'][1]['user_setting_enabled'] = true;
            $array['user_settings'][1]['user_setting_description'] = "";
        }
    

        $database = new database;
        $database->app_name = 'user_settings';
        $database->app_uuid = '3a3337f7-78d1-23e3-0cfd-f14499b8ed97';
        $database->save($array);
        unset($array);


        //assign the extension to the user
        if (!empty($_POST['extension_uuid']) && is_uuid($_POST['extension_uuid'])) {

            $array["extension_users"][1]["extension_user_uuid"] = uuid();
            $array["extension_users"][1]["domain_uuid"] = $user_row['domain_uuid'];
            $array["extension_users"][1]["user_uuid"] = $user_uuid;
            $array["extension_users"][1]["extension_uuid"] = $_POST['extension_uuid'];

            //save to the data
            $database = new database;
            $database->app_name = 'extensions';
            $database->app_uuid = 'e68d9689-2769-e013-28fa-6214bf47fca3';
            $database->save($array);
            $message = $database->message;
            unset($array);

        }

        //redirect the user
        message::add($text['message-update']);
        header("Location: callassist.php?id=".urlencode($user_uuid));
        exit;
    }

    unset($parameters);

//get assigned extensions for user
    $sql = "select u.extension_user_uuid, e.extension_uuid, e.extension, e.effective_caller_id_name, e.outbound_caller_id_number, e.description ";
    $sql .= "from v_extension_users as u, v_extensions as e ";
    $sql .= "where u.extension_uuid = e.extension_uuid  ";
    $sql .= "and u.domain_uuid = :domain_uuid ";
    $sql .= "and u.user_uuid = :user_uuid ";
    $sql .= "order by e.extension asc ";

    $parameters['domain_uuid'] = $domain_uuid;
    $parameters['user_uuid'] = $user_uuid;
    $database = new database;
    $assigned_extensions = $database->select($sql, $parameters, 'all');
    unset($parameters);

//required for QR code
    require_once 'resources/qr_code/QRErrorCorrectLevel.php';
    require_once 'resources/qr_code/QRCode.php';
    require_once 'resources/qr_code/QRCodeImage.php';



//get API Key of current user
    $sql = "select api_key ";
    $sql .= "from v_users ";
    $sql .= "where user_uuid = :user_uuid ";
    $sql .= "and domain_uuid = :domain_uuid ";

    $parameters['domain_uuid'] = $user_row['domain_uuid'];
    $parameters['user_uuid'] = $user_uuid;

    $user_row['api_key'] = $database->select($sql, $parameters, 'column');
    unset($parameters);

	echo $text['description-user_edit']."\n";

    echo "<form name='frm' id='frm' method='post'>\n";
	echo "<div class='card'>\n";
	echo "<table cellpadding='0' cellspacing='0' border='0' width='100%'>";
	echo "<tbody>\n";
	
	echo "	<tr>\n";
	echo "		<td width='30%' class='vncellreq' valign='top'>" . $text['label-username'] . "</td>\n";
	echo "		<td width='70%' class='vtable'>" . $user_row["username"]. "</td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top'>" . $text['label-server'] . "</td>\n";
	echo "		<td class='vtable'>" . $user_row["domain_name"] . "</td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top'>" . $text['label-user_enabled'] . "</td>\n";
	echo "		<td class='vtable'>";
    echo (strpos($user_row['group_names'], $groupName) !== false) ? $text['label-true'] : $text['label-false'];
    echo "		</td>\n";
	echo "	</tr>\n";

		if ( !empty($user_row['api_key']) && strpos($user_row['group_names'], $groupName) !== false)
		{
            // User enabled
			echo "	<tr>\n";
			echo "		<td class='vncell' valign='top'>" . $text['label-qrcode'] . "</td>\n";
			echo "		<td class='vtable'>";
			
			//build QR Code data JSON		
			$json = array(
				"number" => (!empty($mobilePhoneNumber) ? escape($mobilePhoneNumber) : ""),
				"server" => $user_row["domain_name"],
				"username" => $user_row["username"],
				"password" => "" ,
                "apikey" => $user_row["api_key"],
			);
			$json = json_encode($json);

            if(!is_file(dirname(__FILE__) . "/../public_key.pem"))
                echo "Error finding public key... " . dirname(__FILE__) . "/../public_key.pem";
			//encrypt the QR Code data
			$publicKey = file_get_contents(dirname(__FILE__) . "/../public_key.pem");
			if(!openssl_public_encrypt($json, $encryptedData, $publicKey))
				echo "Encryption error...";
            else {
		
                try {
                    $code = new QRCode (- 1, QRErrorCorrectLevel::M);
                    $code->addData(base64_encode($encryptedData));
                    $code->make();

                    $img = new QRCodeImage ($code, $width=200, $height=200, $quality=70);
                    $img->draw();
                    $image = $img->getImage();
                    $img->finish();
                    echo "		<img src=\"data:image/jpeg;base64,".base64_encode($image)."\" style='margin-top: 0px; padding: 5px; background: white; max-width: 100%;'>\n";
                }
                catch (Exception $error) {
                    echo $error;
                }
            }

			echo "	</td>\n";
			echo "</tr>\n";
                

            echo "	<tr>\n";
            echo "		<td class='vncell' valign='top'></td>\n";
            echo "		<td class='vtable'>";
            
            echo button::create(['type'=>'submit',
				'label' => $text['button-disable'],
				'icon' => $_SESSION['theme']['button_icon_save'],
                'name' => 'disable',
                'value'=> '1',
 				'onclick' => ""]);

            echo "      </td>\n";
            echo "	</tr>\n";

		} else {
            //User disabled, do enable here
            

            echo "	<tr>\n";
            echo "		<td class='vncell' valign='top'></td>\n";
            echo "		<td class='vtable'>";

            //no API key, generate
            if(empty($user_row['api_key']))
                echo "			<input type='hidden' class='formfld' name='api_key' id='api_key' value=\"new\" >";

            echo button::create(['type'=>'submit',
				'label' => $text['button-enable'],
				'icon' => $_SESSION['theme']['button_icon_save'],
                'name' => 'enable',
                'value'=> '1',
 				'onclick' => ""]);

            echo "      </td>\n";
            echo "	</tr>\n";


        }

        
	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top'>" . $text['label-mobile_phone'] . "</td>\n";
	echo "		<td class='vtable'>";
    echo "          <input class='formfld' type='text' id='mobile_phone' name='mobile_phone' maxlength='255' value=\"".escape($mobilePhoneNumber)."\">\n";
    echo button::create(['type'=>'submit',
        'label' => $text['button-save'],
        'icon' => $_SESSION['theme']['button_icon_save'],
        'name' => 'save',
        'value'=> '1',
        'onclick' => ""]);
    echo "      </td>\n";
	echo "	</tr>\n";
        

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top'>" . $text['label-extensions'] . "</td>\n";
	echo "		<td class='vtable'>";

    if (is_array($assigned_extensions)) {
        echo "<table cellpadding='0' cellspacing='0' border='0'>\n";
        foreach($assigned_extensions as $field) {
            if (!empty($field['extension_user_uuid'])) {
                echo "<tr>\n";
                echo "	<td class='vtable' style='white-space: nowrap; padding-right: 30px;' nowrap='nowrap'>";
                echo escape($field['extension']);
                if($field['extension'] != $field['effective_caller_id_name'] && !empty($field['effective_caller_id_name']))
                    echo " - " . escape($field['effective_caller_id_name']);
                
                if(!empty($field['description'] && $field['description'] != $field['effective_caller_id_name']))
                    echo " - " . escape($field['description']);

                echo "	</td>\n";
                
                    echo "	<td class='list_control_icons' style='width: 25px;'>\n";
                    echo "		<a href='callassist.php?id=".urlencode($user_uuid)."&extensionuseruuid=".urlencode($field['extension_user_uuid'])."&a=delete' alt='".$text['button-delete']."' onclick=\"return confirm('".$text['confirm-delete']."')\">".$v_link_label_delete."</a>\n";
                    echo "	</td>\n";
                
                echo "</tr>\n";
                
            }
        }
        echo "</table>\n";
    }

//get the list
    $sql = "select extension_uuid, extension, effective_caller_id_name, description from v_extensions ";
    $sql .= "where domain_uuid = :domain_uuid ";
    $parameters['domain_uuid'] = $domain_uuid;

    $sql .= order_by($order_by, $order, 'extension ', 'asc');

    $database = new database;
    $extensions = $database->select($sql, $parameters, 'all');
    unset($sql, $parameters);

    if (is_array($extensions)) {
        //if (count($assigned_extensions) > 0) { echo "<br />\n"; }
        echo "<select name='extension_uuid' class='formfld' style='width: auto; margin-right: 3px;' >\n";
        echo "	<option value=''></option>\n";
        foreach($extensions as $field) {
            if (!(array_search($field['extension_uuid'], array_column( $assigned_extensions, 'extension_uuid')) !== false)) {
                echo "	<option value='".$field['extension_uuid']."'>". 
                    $field['extension'] . 
                    ($field['extension'] != $field['effective_caller_id_name'] && !empty($field['effective_caller_id_name']) ? " - " . $field['effective_caller_id_name'] : "") . 
                    (!empty($field['description'] && $field['description'] != $field['effective_caller_id_name']) ? " - " . $field['description'] : "") . 
                "</option>\n";
            }
            
        }
        echo "</select>";
        
        echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$_SESSION['theme']['button_icon_add'],'onclick'=>"document.getElementById('frm').submit();"]);
        
    }

    echo "      </td>\n";
	echo "	</tr>\n";
        

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top'>" . $text['label-outbound_numbers'] . "</td>\n";
	echo "		<td class='vtable'>";

    echo "<table cellpadding='0' cellspacing='0' border='0'>\n";

    if (is_array($assigned_extensions)) {
        $numbers = array();
        foreach($assigned_extensions as $field) {
            if(!in_array($field['outbound_caller_id_number'], $numbers) && !empty($field['outbound_caller_id_number']))
                $numbers[] = $field['outbound_caller_id_number'];
        }
        
        foreach($numbers as $number) {
            echo "<tr>\n";
            echo "	<td colspan='2' class='vtable' style='white-space: nowrap; padding-right: 30px;' nowrap='nowrap'>";
            echo escape($number);
            echo "	</td>\n";            
            echo "</tr>\n";            
        }
    }

    
    if (is_array($extaOutboundNumbers)) {
        foreach($extaOutboundNumbers as $field) {
            if (!empty($field)) {
                echo "<tr>\n";
                echo "	<td class='vtable' style='white-space: nowrap; padding-right: 30px;' nowrap='nowrap'>";
                echo escape($field);
                echo "	</td>\n";
                
                echo "	<td class='list_control_icons' style='width: 25px;'>\n";
                echo "		<a href='callassist.php?id=".urlencode($user_uuid)."&extaoutboundnumber=".urlencode($field)."&a=delete' alt='".$text['button-delete']."' onclick=\"return confirm('".$text['confirm-delete']."')\">".$v_link_label_delete."</a>\n";
                echo "	</td>\n";
                
                echo "</tr>\n";
                
            }
        }
    }
    echo "</table>\n";

//get the list
    $sql = "select destination_number from v_destinations ";
    $sql .= "where domain_uuid = :domain_uuid ";
    $parameters['domain_uuid'] = $domain_uuid;

    $sql .= order_by($order_by, $order, 'destination_number, destination_order ', 'asc');

    $database = new database;
    $destinations = $database->select($sql, $parameters, 'all');
    unset($sql, $parameters);

    if (is_array($destinations)) {
        if (count($extaOutboundNumbers) > 0) { echo "<br />\n"; }
        echo "<select name='outbound_number' class='formfld' style='width: auto; margin-right: 3px;' >\n";
        echo "	<option value=''></option>\n";
        foreach($destinations as $field) {
            if (!in_array($field['destination_number'], $extaOutboundNumbers) && !in_array($field['destination_number'], $numbers)) {
                echo "	<option value='".$field['destination_number']."' $selected>".$field['destination_number']."</option>\n";
            }
            
        }
        echo "</select>";
        
        echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$_SESSION['theme']['button_icon_add'],'onclick'=>"document.getElementById('frm').submit();"]);
        
    }

    echo "      </td>\n";
	echo "	</tr>\n";

	echo "</tbody>\n";
	echo "</table>\n";
	echo "</div>\n";
	echo "</form>";