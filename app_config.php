<?php

	//application details
		$apps[$x]['name'] = "CallAssist Mobile";
		$apps[$x]['uuid'] = "c8092222-5dfb-4f8b-a6f1-ebc529617a91";
		$apps[$x]['category'] = "";;
		$apps[$x]['subcategory'] = "";
		$apps[$x]['version'] = "1.0";
		$apps[$x]['license'] = "";
		$apps[$x]['url'] = "https://www.callassist.io";
		$apps[$x]['description']['en-us'] = "CallAssist Mobile";
		$apps[$x]['description']['en-gb'] = "CallAssist Mobile";
		$apps[$x]['description']['ar-eg'] = "CallAssist Mobile";
		$apps[$x]['description']['de-at'] = "CallAssist Mobile";
		$apps[$x]['description']['de-ch'] = "CallAssist Mobile";
		$apps[$x]['description']['de-de'] = "CallAssist Mobile";
		$apps[$x]['description']['es-cl'] = "CallAssist Mobile";
		$apps[$x]['description']['es-mx'] = "CallAssist Mobile";
		$apps[$x]['description']['fr-ca'] = "CallAssist Mobile";
		$apps[$x]['description']['fr-fr'] = "CallAssist Mobile";
		$apps[$x]['description']['he-il'] = "CallAssist Mobile";
		$apps[$x]['description']['it-it'] = "CallAssist Mobile";
		$apps[$x]['description']['ka-ge'] = "CallAssist Mobile";
		$apps[$x]['description']['nl-nl'] = "CallAssist Mobile";
		$apps[$x]['description']['pl-pl'] = "CallAssist Mobile";
		$apps[$x]['description']['pt-br'] = "CallAssist Mobile";
		$apps[$x]['description']['pt-pt'] = "CallAssist Mobile";
		$apps[$x]['description']['ro-ro'] = "CallAssist Mobile";
		$apps[$x]['description']['ru-ru'] = "CallAssist Mobile";
		$apps[$x]['description']['sv-se'] = "CallAssist Mobile";
		$apps[$x]['description']['uk-ua'] = "CallAssist Mobile";

		
		$apps[$x]['groups'][0]['group_uuid'] = 'a9380266-748d-4afe-92a2-8e041237ab88';
		$apps[$x]['groups'][0]['group_name'] = 'CallAssist Mobile';
		$apps[$x]['groups'][0]['domain_uuid'] = '';
		$apps[$x]['groups'][0]['group_level'] = '10';
		$apps[$x]['groups'][0]['group_protected'] = '';
		$apps[$x]['groups'][0]['group_description'] = 'Group for CallAssist Mobile App users';


	//permission details
	
		$y=0;
		$apps[$x]['permissions'][$y]['name'] = "callassist_view";
		$apps[$x]['permissions'][$y]['menu']['uuid'] = "eb2fc267-b366-4d36-bd9f-19fad41d31e5";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$apps[$x]['permissions'][$y]['groups'][] = "user";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "callassist_manage";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "callassist_user";
		$apps[$x]['permissions'][$y]['groups'][] = "CallAssist Mobile";
		$y++;
	
	
?>