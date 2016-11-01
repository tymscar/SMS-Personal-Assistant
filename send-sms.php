<?php

$payload = array(
	  'api_key' =>  '',
	  'api_secret' => '',
      'to' => '',
      'from' => '',
      'text' => ''
);
	
$url = 'https://rest.nexmo.com/sms/json?' . http_build_query($payload);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);

echo $response;

?>
