<?php

	require_once dirname(__DIR__, 3) . "/resources/require.php";

    $options = getopt("i:p:w:c:");
    
    //create the even socket connection and send the event socket command
    $fp = event_socket_create($options['i'], $options['p'], $options['w']);
    $switch_cmd = $options['c'];

    //show the command result
    $result = trim(event_socket_request($fp, $switch_cmd));
    if (substr($result, 0,3) == "+OK") {
        $uuid = substr($result, 4);    
    }
    echo $result;