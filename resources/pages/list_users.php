<?PHP

    //check permissions
    if (!permission_exists('callassist_manage') || !$callAssistIncluded) {
        echo "access denied";
        exit;
    }


    //get the list
    $sql = "select user_uuid, username, group_names ";
        
    $sql .= "from view_users ";
    
    $sql .= "where (domain_uuid = :domain_uuid) ";
    $parameters['domain_uuid'] = $domain_uuid;
    
    $sql .= "and ( ";
    $sql .= "	group_level <= :group_level ";
    $sql .= "	or group_level is null ";
    $sql .= ") ";
    
    $parameters['group_level'] = $_SESSION['user']['group_level'];
    
    $sql .= order_by($order_by, $order, 'username', 'asc');
    //$sql .= limit_offset($rows_per_page, $offset);

    $database = new database;
    $users = $database->select($sql, $parameters, 'all');
    unset($sql, $parameters);


    //list users
    echo "<table class='list'>\n";
    echo "<tr class='list-header'>\n";
	echo th_order_by('username', $text['label-username'], $order_by, $order, null, null, $param);
    echo th_order_by('user_enabled', $text['label-user_enabled'], $order_by, $order, null, "class='center'", $param);
    echo "</tr>\n";

	if (is_array($users) && @sizeof($users) != 0) {
		
		foreach ($users as $row) {
            $list_row_url = "?id=".urlencode($row['user_uuid']);
            
            echo "<tr class='list-row' href='" . $list_row_url . "'>\n";
            
            echo "	<td>\n";
            echo "		<a href='" . $list_row_url . "' title=\"" . $text['button-edit'] . "\">" . escape($row['username']) . "</a>\n";
            echo "	</td>\n";
            echo "	<td class='center'>\n";
            echo (strpos($row['group_names'], $groupName) !== false) ? $text['label-true'] : $text['label-false'] ;
            echo "	</td>\n";

            echo "</tr>\n";
        }
    }
    
    echo "</table>\n";
