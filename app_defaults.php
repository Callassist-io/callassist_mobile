<?php

	if ($domains_processed == 1) {

		//create the Users group
		$array['groups'][0]['group_uuid'] = 'a9380266-748d-4afe-92a2-8e041237ab88';
		$array['groups'][0]['group_name'] = 'CallAssist Mobile User';
		$array['groups'][0]['domain_uuid'] = '';
		$array['groups'][0]['group_level'] = '10';
		$array['groups'][0]['group_protected'] = '';
		$array['groups'][0]['group_description'] = 'Group for CallAssist Mobile App users';
		
		//save the data
		$database = new database;
		$database->app_name = 'Group Manager';
		$database->app_uuid = '2caf27b0-540a-43d5-bb9b-c9871a1e4f84';
		$database->save($array);

	}
	
?>