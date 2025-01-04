<?PHP

//check permissions
	if (!permission_exists('callassist_view') || !$callAssistIncluded) {
		echo "access denied";
		exit;
	}

//required for QR code
	require_once 'resources/qr_code/QRErrorCorrectLevel.php';
	require_once 'resources/qr_code/QRCode.php';
	require_once 'resources/qr_code/QRCodeImage.php';

	$user_uuid = $_SESSION['user']['user_uuid'];

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
	unset($parameters);

	
//access to user settings info

	$user_setting_uuid = $_GET["id"];
	$settings_sql = "select user_setting_uuid, user_setting_category, user_setting_subcategory, user_setting_name, user_setting_value, user_setting_order, cast(user_setting_enabled as text), user_setting_description ";
	$settings_sql .= "from v_user_settings ";
	$settings_sql .= "where user_uuid = :user_uuid ";
	$settings_sql .= "and user_setting_category = 'callassist' ";

	$settings_parameters['user_uuid'] = $user_uuid;
	$database = new database;
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

//get assigned extensions for user
	$sql = "select u.extension_user_uuid, e.extension_uuid, e.extension, e.effective_caller_id_name, e.outbound_caller_id_number ";
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

//get API Key of current user
    $sql = "select api_key ";
    $sql .= "from v_users ";
    $sql .= "where user_uuid = :user_uuid ";
    $sql .= "and domain_uuid = :domain_uuid ";

    $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
    $parameters['user_uuid'] = $user_uuid;

    $user_row['api_key'] = $database->select($sql, $parameters, 'column');
    unset($parameters);

	echo $text['description-user_edit']."\n";

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
		}
		echo "	<tr>\n";
		echo "		<td class='vncell' valign='top'>" . $text['label-mobile_phone'] . "</td>\n";
		echo "		<td class='vtable'>";
		echo escape($mobilePhoneNumber);
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
					echo "	</td>\n";					
					echo "</tr>\n";
					
				}
			}
			echo "</table>\n";
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
					
					echo "</tr>\n";
					
				}
			}
		}
		echo "</table>\n";
	

	
		echo "      </td>\n";
		echo "	</tr>\n";

	echo "</tbody>\n";
	echo "</table>\n";
	echo "</div>\n";
