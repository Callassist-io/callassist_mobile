<?php

//error reporting
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";


//check permissions
	if (!permission_exists('callassist_view')) {
		echo "access denied";
		exit;
	}

//defaults
	$callAssistIncluded = true;
	$groupName = 'CallAssist Mobile User';

//add multi-lingual support
	$language = new text;
	$text = $language->get($_SESSION['domain']['language']['code'], 'app/callassist_mobile');

//set document title
	$document['title'] = $text['title-callassist'];
	require_once "resources/header.php";

//show the action bar
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-callassist'] . ' ' . $text['title-users']."</b></div>\n";
	echo "	<div class='actions'>\n";

//check permissions for action bar
/*
	if (permission_exists('callassist_manage')) {

	}
*/

//show app download icons
	echo "		<a href='https://play.google.com/store/apps/details?id=com.esselink.callassist' target='_blank'><img src='/app/callassist_mobile/resources/images/GetItOnGooglePlayStore.png' style='width: 100px; height: auto;' /></a>&nbsp;\n";
	echo "		<a href='https://apps.apple.com/app/callassist-mobile/id1445396946' target='_blank'><img src='/app/callassist_mobile/resources/images/DownloadontheAppStore.png' style='width: 100px; height: auto;' /></a>\n";

	if (!empty($_REQUEST["id"]) && is_uuid($_REQUEST["id"])) 
		echo "<br />" . button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$_SESSION['theme']['button_icon_back'],'id'=>'btn_back','link'=>'callassist.php']);

//close action bar
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-users']."\n";
	

//check permissions and show content
	if (permission_exists('callassist_manage') ) {
		//access to manage CallAssist


		//action add or update
		if (!empty($_REQUEST["id"]) && is_uuid($_REQUEST["id"])) {
			
			//edit user for CallAssist
			require_once "./resources/pages/edit_user.php";
			
		} else {

			//show the users list
			require_once "./resources/pages/list_users.php";

		}

	}
	else {

		//show the current user details
		require_once "./resources/pages/view_user.php";

	}

//the end
	require_once "resources/footer.php";
