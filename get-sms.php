<?php
// work with get or post
$request = array_merge($_GET, $_POST);

// check that request is inbound message
if (!isset($request['to']) OR !isset($request['msisdn']) OR !isset($request['text'])) {
    error_log('not inbound message');
    return;
}

//Deal with concatenated messages
$message = false;
if (isset($request['concat']) AND $request['concat'] == true ) {
    //message can be reassembled using the part and total
    error_log("this message is part {$request['concat-part']} of {$request['concat-total']} for {$request['concat-ref']}");

    //generally this would be a database
    session_start();
    session_id($request['concat-ref']);

    if (!isset($_SESSION['messages'])) {
        $_SESSION['messages'] = array();
    }

    $_SESSION['messages'][] = $request;

    if (count($_SESSION['messages']) == $request['concat-total']) {
        error_log('received all parts of concatenated message');

        //order messages
        usort(
            $_SESSION['messages'], function ($a , $b) {
                return $a['concat-part'] > $b['concat-part'];
            }
        );

        $message = array_reduce(
            $_SESSION['messages'], function ($carry, $item) {
                return $carry . $item['text'];
            }
        );
    } else {
        error_log('have ' . count($_SESSION['messages']) . " of {$request['concat-total']} message");
    }
}

function set($msid, $key, $value){
	if(!file_exists($msid))
	{
		mkdir($msid);
		}
	$f = fopen($msid."/". $key, "w") ;
	fwrite($f, $value);
	fclose($f);
}
function clear($msid, $key){
	if(file_exists($msid."/".$key)){
		unlink($msid."/". $key);
	}
}
function get($msid, $key){
	if(file_exists($msid . "/" .$key)){
		$val = readfile($msid . "/" .$key);
		$f = fopen($msid."/". $key, "r") ;
		$value = fread($f,64);
		fclose($f);
		return($value);
	}else{return null;}
}

function sms($to,$text){
		$payload = array(
			  'api_key' =>  '',
			  'api_secret' => '',
			  'to' => $to,
			  'from' => "",
			  'text' => $text
		);
			
		print_r($payload);
		
		$url = 'https://rest.nexmo.com/sms/json?' . http_build_query($payload);

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		if($_GET['debug']!=1) return (curl_exec($ch));
}

//Handle message types
switch ($request['type']) {
    case 'binary':
        error_log("got a binary message with a UDH: {$request['udh']}");
        break;

    case 'unicode':
        //Do some unicode stuff
        error_log("got a unicode message");
    default:
        error_log('message from: ' . $request['msisdn']);
        error_log("the message body is: {$request['text']}");
        if ($message) {
            error_log("the concatenated message is: {$message}");
        }
        
		// Whole message recieved. 
		// Have we asked a question?
		
		$questionkey = get($request['msisdn'],"q");
		
		if(!empty($questionkey)){
			set($request['msisdn'],$questionkey,$request['text']);
			clear($request['msisdn'],"q");
			sms($request['msisdn'],"Thanks! I've remembered now that your $questionkey is ". $request['text']);
			
			if($questionkey=="home address"){
				$usualdest = get($request['msisdn'],"usual commute destination");
				if(empty($usualdest))
					{
					$question="And where do you commute to? A company or university name, or town or postcode, is fine.";
					set($request['msisdn'],"q","usual commute destination");
					sms($request['msisdn'],$question);
					}
			}else{
				sms($request['msisdn'],"You can ask me something else now, if you want to.");
			}

								
		}else{

			// Get the name from the number.
			$name = get($request['msisdn'],"name");
			
			if(empty($name)){
				$question="By the way, what's your name?";
				$questionkey="name";
			}
			
			$luis = "https://api.projectoxford.ai/luis/v1/application?id=0bc7efaa-12a3-4a13-8288-68c075d6feb2&subscription-key=ef31f93e32ec472093da6b0ee5f832d1&q=" . urlencode($request['text']) . "&timezoneOffset=0.0";

			$ch = curl_init($luis);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$luis_response = curl_exec($ch);

			error_log("luis response: " . $luis_response);
			
			$luis_response = json_decode($luis_response);
			
			$intents = $luis_response->intents;
			$intent = $intents[0]->intent;
			$entities = $luis_response->entities;
			
			echo("That looks like a " . $intent."<br/>");
			
			switch($intent){
				case 'HackathonEnquiry':
					$textarray = array(
						"Yes; it will, without a doubt, definitely more than likely probably be last hackference.",
						"This will definitely be the last hackference, unless told otherwise.",
						"I think this will definitely be the last hackference, probably.",
						"This will more than likely be definitely the last hackference."
					);
					$text = $textarray[array_rand($textarray)];
				break;
				case 'WifiPasswordEnquiry':
					$text = "The wifi password for the Hackference is 'epicbrum'";
				break;
				case 'WeatherEnquiry':
				
				// using Microsoft
				
				$weather_url = "http://weather.service.msn.com/data.aspx?culture=en-US&weadegreetype=F&src=outlook&weasearchstr=";
				
				// is there a location in this query?
				
				$homeloc = get($request['msisd'],"home location");

				$dayname="today";

				foreach($entities as $entity){
					if($entity->type=="TravelPoints"||$entity->type=="TravelPoints::TravelDestination"){
						ucfirst($location = $entity->entity);
					}
					if($entity->type=="builtin.datetime.date"){
						$dayname = ucfirst($entity->entity);
						$actualdate = $entity->resolution->{'date'};
					}
				}

					// get the home location from their settings
					$homeloc = get($request['msisdn'],"home location");
					
					$cf = ucfirst(get($request['msisdn'],"preferred temperature unit"));
					
					if(empty($homeloc)){
						$question = "What's your home location? I can check the weather more easily for you if you tell me.";
						$questionkey="home location";
					}
					if(empty($cf)){
						$cf = "C";
						$question = "What's your preferred unit of measurement (celsius/farenheit)? Reply back C or F and I'll remember it.";
						$questionkey="preferred temperature unit";
					}
					
					// if no location in query, use home location
					if(!isset($location)) $location = $homeloc;

					$location = ucfirst($location);
					
					// location is valid, do a look-up
					if(!empty($location)){
						
						$url = $weather_url . $location;
						
						$ch = curl_init($url);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						$response = trim(curl_exec($ch));

						$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
						if(curl_error($ch) || $http_status > 200){
							$text = "Weather information is not working for this location at the moment. Sorry $name.";
						}else{
							// weather information in XML in $response...
							
							$weather = simplexml_load_string($response);
							$weather = $weather[0]->weather;

							if($dayname=="Today"){
								$today = $weather->current;
										$skytext=lcfirst($today->attributes()->{'skytextday'});
										$low=$today->attributes()->{'low'};
										$high=$today->attributes()->{'high'};
								}
							else{
								// go through each forecast to find the relevant date
								$weather = $weather->forecast;
								foreach($weather as $forecast){
									echo($actualdate);
									if($forecast->attributes()->{'date'}==$actualdate){
										echo("date matched");
										$skytext=lcfirst($forecast->attributes()->skytextday);
										$low=$forecast->attributes()->low;
										$high=$forecast->attributes()->high;
										$precip=$forecast->attributes()->precip;
									}
								}
							}
							
							// if C or F&src
							
							if($cf=="C"){
								$low = intval(($low-32) * 5/9);
								$high = intval(($high-32) * 5/9);
								}
							
							if(isset($skytext)){
								$text = "$dayname's weather in $location will be $skytext with highs of $high$cf and lows of $low$cf";
								if(!empty($precip)){$text .= ", with a $precip% chance of rain.";}
							}else{
								$text = "I wasn't able to get you weather information for $dayname in $location - sorry $name.";
							}
							
							

							}
					}else{
						echo("No location provided in query, and no home location set.");
					
						
						
						
					
					
					

				}
				
				// is the location a postcode?
				
				
				
				break;
				case 'CommuteEnquiry':
				
					$from = get($request['msisdn'],"home address");
					$to = get($request['msisdn'],"usual commute destination");
					
					if(empty($to)){$question="Where do you usually commute to? An address, company name or postcode is fine.";$questionkey="usual commute destination";}
					if(empty($from)){$question="So I can learn your commute, what's your home postcode?";$questionkey="home address";}
					
					if(!empty($to)&&!empty($from)){
						$text = "I know you want me to tell you your commute time, but unfortunately there's only so much you can do in 24 hours. Try again at the next hackference (ask me if there will be one)!";
					}
				
				break;
				case 'TravelEnquiry':
					
					$text = "I'm not yet clever enough to do that, sorry $name.";
				
				break;
				
				case 'TranslateEnquiry':
				
				// Oscar's endpoint Change this to the translate endpoint
				
				$oscar_url = "Change this to the translate endpoint";
				
				
				// find the 'word' in the entities
				foreach($entities as $entity){
					if($entity->type=="word"){
						$word = $entity->entity;
					}
					if($entity->type=="Language::DestinationLanguage"){
						$destlang = $entity->entity;
						$destlang = ucfirst($destlang);
					}
					if($entity->type=="Language::SourceLanguage"){
						$sourcelang = $entity->entity;
						$sourcelang = ucfirst($sourcelang);
					}

					$homelang = get($request['msisdn'],"home language");
					if(!isset($sourcelang)){$sourcelang = "";}
					if(!isset($destlang)){$destlang = get($request['msisdn'],"home language");}
					
					if(empty($homelang)){
						$question = "What's your home/native language? What should I translate things from or to, if you don't specify?";
						$questionkey="home language";
					}
					

				}
				
				if(empty($word)||($sourcelang==$destlang)){

					$text = "Sorry $name, I couldn't understand that translation request.";

				}else{
				
					$payload = array(
								  'text' =>  $word,
								  'destlang' => $destlang,
								  'sourcelang' => $sourcelang
							);				
					$url = $oscar_url . http_build_query($payload);

					
					print_r($url);
					echo("<br/>");
					$ch = curl_init($url);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					$response = strip_tags(trim(curl_exec($ch)));

					$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					if(curl_error($ch) || $http_status > 200){
						$text = "I can't translate your text for you; Oscar's API is on its knees again. Sorry $name.";
					}else{
						$textarray = array(
						"To say $word in $destlang say:\n$response",
						"$word in $destlang is translated as '$response'.",
						"The word $word is '$response' in $destlang."
						);
						$text = $textarray[array_rand($textarray)];
					}
					echo("<hr/>$text<hr/>");

				}
				
				break;

				default:
				
				break;
				
			}
			
			// send a message back...
			
			$response = sms($request['msisdn'],$text);
			print_r($response);
			
			// is there a question to ask?
			
			if(!empty($question)){
				sms($request['msisdn'],$question);
				set($request['msisdn'],"q",$questionkey);
			}
			
			echo $response;
		
		}
		
		break;
		
}