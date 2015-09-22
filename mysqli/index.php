<?php
	$BZ2_SERVER_VERSION = "v3.0b1";
	# ------------:
	# REQUIREMENTS:
	# MySQL database.
	# PHP with MySQLi http://php.net/manual/en/mysqli.requirements.php
	# PHP Version 5.2.0 (For JSON.)
	# ------------:
	# INSTALLATION:
	# 1. Put testSever.php into your site's root directory.
	# Mind the case sensitivity on linux servers.
	# 2. Set up your MySQL database for BZ2 usage. See "mysql.txt"
	$sqlPort = 3306; $sqlHost = "127.0.0.1";
	$sqlUsername = "raknet"; $sqlPassword = "pass";
	$sqlDatabase = "raknet";
	# 3. Set the string variable below to your hostname.
	//$siteHostName = "localhost";
	$siteHostName = "raktest.nielk1.com";
	# -------------:
	# CONFIGURATION:
	$cfgUseGsoff = TRUE;                         # Allow users to hide their games via ?gsoff=1.
	$cfgMultTimeout = 1.3;                       # Multiply timeout time, 1.0 = 100% (no change).
	$cfgFakeLobbyName = "http://www.nielk1.com"; # Set to NULL to disable this feature.
	# -------------------------------------------------------------------
	
	# Important Global Variables
	$serverOutput = "";
	$IP = $_SERVER["REMOTE_ADDR"];
	$reqMethod = $_SERVER["REQUEST_METHOD"];
	
	# Initialize MySQL
	$SQLiDatabase = new mysqli($sqlHost, $sqlUsername, $sqlPassword, $sqlDatabase, $sqlPort) or die("Failed to connect to MySQL server.");
	$SQLiDatabase->set_charset("utf8");
	
	# Security/Strictness
	$maxHostsPerIP = 10; # Max unique games from same IP. Set to NULL for no limit.
	$JSON_ForceType = Array(
		"__addr" => is_string,
		"__timeoutSec" => is_int,
		"__rowPW" => is_string,
		"__clientReqId" => is_int,
		"gsoff" => is_int, "g" => is_string,
		"n" => is_string, "m" => is_string,
		"k" => is_int, "d" => is_string,
		"t" => is_int, "r" => is_string,
		"v" => is_string, "p" => is_string
	);
	
	$proxy_list = Array(
		iondriver => "http://raknetsrv2.iondriver.com/testServer?__gameId=BZ2&__excludeCols=__rowId,__city,__cityLon,__cityLat,__timeoutSec,__geoIP,__gameId&__pluginProxy=false&__pluginIP={IP}&__geoIP={GeoIP}",
		bz2maps => "http://gamelist.kebbz.com/testServer?__gameId=BZ2&__excludeCols=__rowId,__city,__cityLon,__cityLat,__timeoutSec,__geoIP,__gameId&noproxy&__geoIP={GeoIP}",
		//fake => "http://0.0.0.0",
	);
	$proxycacheagelimit = 30;
	$proxy_timeout = 10;
	
	# What we send (if not NULL) to clients (BZ2).
	$JSON_Send = Array("n", "m", "d", "t", "g", "r", "v", "k", "p", "__addr");
	
	# Strings we send to BZ2 which are JSON parameters.
	function bz2_string($input) {
		# Known bad characters: Backslash \, Double-Quote ".
		return preg_replace("/[^\`\~\/\w\d\.\,\?\>\<\|\:\;\-\+\_\=\!\@\#\$\%\^\&\*\(\){}\[\]\']/" , "_", $input);
	}
	
	function parse_proxy_record($raw) {
		global $JSON_Send;
		
		if(!isset($raw))
			return null;
		
		$JSON = json_decode($raw);
		
		if (($JSON !== NULL) and ($JSON->{"GET"} !== NULL)) {
			$gamearray = $JSON->{"GET"};
			$retVal = array();
			
			foreach($gamearray as $gameitem) {
				$ToJSON = Array();
				
				foreach($gameitem as $column => $value) {
					if (($value !== NULL) and (in_array($column, $JSON_Send))) {
						$data = $value;
						if ($JSON_ForceType[$column] === is_string) $data = "\"".bz2_string($data)."\"";
						$ToJSON[] = "\"".$column."\":".$data;
					}
				}
				$retVal[] = "{".implode(",", $ToJSON)."}";
			}
			
			return $retVal;
		}
		
		return null;
	}
	
	# -------------------------------------------------------------------
	
	if ($reqMethod == "GET") {
		# Remove timed out games, and give clients the game list.
		if (isset($_GET["__gameId"])) {
			$gameArray = Array();
			$SQL = $SQLiDatabase->query("SELECT * FROM gamelist;") or die(mysqli_error($SQLiDatabase));
			//$SQL_Data = $SQL->fetch_all(MYSQLI_ASSOC); $SQL->free();
			for ($SQL_Data = array(); $tmp = $SQL->fetch_array(MYSQLI_ASSOC);) $SQL_Data[] = $tmp; $SQL->free();
			
			$now = time();
			
			if ($SQL_Data) {
				foreach($SQL_Data as $SQL_Row) {
					$clientLife = $now-$SQL_Row["__lastUpdate"];
					$clienetLifeSpan = $SQL_Row["__timeoutSec"]*$cfgMultTimeout;
					
					if ($clientLife > $clienetLifeSpan) {
						# This game has timed out.
						$SQLiDatabase->query("DELETE FROM gamelist WHERE __rowId=".$SQL_Row["__rowId"].";")
						or die(mysqli_error($SQLiDatabase));
					} elseif ($SQL_Row["gsoff"] == 0) {
						// $excludeCols = explode(",", $_GET["__excludeCols"]); // Wait, we don't send that junk anyways.
						
						$ToJSON = Array();
						foreach($SQL_Row as $column => $value) {
							if (($SQL_Row[$column] !== NULL) and (in_array($column, $JSON_Send))) {
								$data = $SQL_Row[$column];
								if ($JSON_ForceType[$column] === is_string) $data = "\"".bz2_string($data)."\"";
								$ToJSON[] = "\"".$column."\":".$data;
							}
						}
						
						$gameArray[] = "{".implode(",", $ToJSON)."}";
					}
				}
			}
			
			if (!is_null($cfgFakeLobbyName))
				$gameArray[] = "{\"n\":\"".$cfgFakeLobbyName."\",\"__addr\":\"0.0.0.0:0\",\"m\":\"\"}";
			
			if (!isset($_GET["noproxy"])) {
				$SQLiDatabase->query("DELETE FROM proxycache WHERE gettime < DATE_SUB(NOW(),INTERVAL ".$proxycacheagelimit." SECOND);");
				
				$SQLproxy = $SQLiDatabase->query("SELECT * FROM proxycache;") or die(mysqli_error($SQLiDatabase));
				//$SQLproxy_Data = $SQLproxy->fetch_all(MYSQLI_ASSOC); $SQLproxy->free();
				for ($SQLproxy_Data = array(); $tmp = $SQLproxy->fetch_array(MYSQLI_ASSOC);) $SQLproxy_Data[] = $tmp; $SQLproxy->free();
				
				foreach($SQLproxy_Data as $SQLproxy_Row) {
					// remove the proxy from the list as we have it in cache
					unset($proxy_list[$SQLproxy_Row["origin"]]);
					// add cached data to responce
					$tmpGameItem = parse_proxy_record($SQLproxy_Row["cache"]);
					if(isset($tmpGameItem))
					{
						foreach ($tmpGameItem as $tmpGameSubItem)
						{
							$gameArray[] = $tmpGameSubItem;
						}
					}
				}
				
				$GeoIP = $IP;
				if (isset($_GET["__geoIP"]))
					$GeoIP = $_GET["__geoIP"];
				
				foreach ($proxy_list as $key => $url)
				{
					try {
						$url = str_replace("{IP}",$IP,$url);
						$url = str_replace("{GeoIP}",$GeoIP,$url);
						
						$ch = curl_init();
						curl_setopt($ch, CURLOPT_URL, $url);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
						curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $proxy_timeout);
						$proxydata = curl_exec($ch);
						curl_close($ch);

						$tmpGameItem = parse_proxy_record($proxydata);
						
						if(isset($tmpGameItem))
						{
							foreach ($tmpGameItem as $tmpGameSubItem)
							{
								$gameArray[] = $tmpGameSubItem;
							}
							
							$SQL_Command = "INSERT INTO proxycache (origin,gettime,cache) VALUES ('".$key."',NOW(),'".$SQLiDatabase->real_escape_string($proxydata)."');";
							$SQLiDatabase->query($SQL_Command) or die(mysqli_error($SQLiDatabase));
						}
					} catch (Exception $e) {
						//$gameArray[] = '{"exception":"' + $e->getMessage() + '"}';
					}
				}
				
				//$gameArray[] = "{"."\"test\""."}";
			}
			
			$serverOutput .= "{\"GET\": [".implode(",", $gameArray)."], \"__request\":\"http://".$siteHostName."/testServer\"}";
		
		# Print version.
		} elseif (isset($_GET["showVersion"])) {
			$serverOutput .= "Version: <b>".$BZ2_SERVER_VERSION."</b>";
		
		# Use GSOff Feature, if enabled.
		} elseif (isset($_GET["gsoff"])) {
			if (!$cfgUseGsoff) {
				$serverOutput .= "Operation not supported on this server.";
			} else {
				# Hide games listed by user's IP address in game list.
				$SQL = $SQLiDatabase->query("SELECT n, m FROM gamelist WHERE __addr=\"".$IP."\";")
				or die(mysqli_error($SQLiDatabase));
				$SQL_Data = $SQL->fetch_all(MYSQLI_ASSOC); $SQL->free();
				$count = count($SQL_Data);
				
				if (!$count) die("No games found hosted by your IP.");
				
				if ($_GET["gsoff"] == "1") {
					$gsoff = 1;
					$gsoff_str = "hidden";
				} else {
					$gsoff = 0;
					$gsoff_str = "visible";
				}
				
				$SQLiDatabase->query("UPDATE gamelist SET gsoff=".$gsoff." WHERE __addr=\"".$IP."\";")
				or die(mysqli_error($SQLiDatabase));
				foreach($SQL_Data as $SQL_Row) {
					$serverOutput .= "Server <b>\"".$SQL_Row["n"]."\"</b> on <b>".
					$SQL_Row["m"].".bzn</b> now ".$gsoff_str.".<br>";
				}
			}
		}
	
	# Delete game from game list.
	} elseif ($reqMethod == "DELETE") {
		$rowID = (int)$_GET["__rowId"];
		$rowPW = $SQLiDatabase->escape_string($_GET["__rowPW"]);
		if ($rowID <= 0) die("Invalid Row ID.");
		if (!$JSON_ForceType["__rowPW"]($rowPW)) die("Invalid Row Password.");
		
		$SQL = $SQLiDatabase->query("SELECT __rowPW FROM gamelist WHERE __rowId=".$rowID.";")
		or die(mysqli_error($SQLiDatabase));
		$SQL_Data = $SQL->fetch_array(MYSQLI_ASSOC); $SQL->free();
		$ourRowPW = $SQL_Data["__rowPW"];
		
		if (is_null($ourRowPW)) die("Row does not exist.");
		if ($rowPW !== $ourRowPW) die("Incorrect Row Password.");
		
		$SQLiDatabase->query("DELETE FROM gamelist WHERE __rowId=".$rowID.";") or die(mysqli_error($SQLiDatabase));
	
	# Add game to game list, or update existing game.
	} elseif ($reqMethod == "POST") {
		$POST_RawData = file_get_contents("php://input");
		$JSON = json_decode($POST_RawData);
		if (($JSON !== NULL) and ($JSON->{"__gameId"} !== NULL)) {
				if (is_int($maxHostsPerIP)) {
					$SQL = $SQLiDatabase->query("SELECT COUNT(*) FROM gamelist WHERE __addr=\"".$IP."\";")
					or die(mysqli_error($SQLiDatabase));
					$SQL_Data = $SQL->fetch_all(MYSQLI_NUM); $SQL->free();
					if ($SQL_Data[0][0] >= $maxHostsPerIP) die("Maximum hosts per IP reached.");
				}
				
				$SQL = $SQLiDatabase->query("SELECT __rowId, __rowPW FROM gamelist WHERE __clientReqId=".$JSON->{"__clientReqId"}.";")
				or die(mysqli_error($SQLiDatabase));
				$SQL_Data = $SQL->fetch_array(MYSQLI_ASSOC); $SQL->free();
				$rowID = (!is_null($SQL_Data)) ? $SQL_Data["__rowId"] : NULL;
				
				$NewData = Array();
				foreach ($JSON as $key => $data) {
					# Only add values we expect from BZ2, and
					# validate their type. Also escape all strings.
					if (array_key_exists($key, $JSON_ForceType)) {
						if (!$JSON_ForceType[$key]($data)) die($key." is invalid type.");
						
						if (is_string($data)) {
							$JSON->{$key} = $SQLiDatabase->escape_string($data);
							$data = "\"".$JSON->{$key}."\"";
						}
						
						$NewData[$key] = $data;
					}
				}
				$NewData["__addr"] = "\"".$IP."\"";
				$NewData["__lastUpdate"] = time(); 
				
				if(is_null($rowID)) {
					# List new game.
					$SQL_Key = Array(); $SQL_Val = Array();
					foreach($NewData as $key => $val) {
						$SQL_Key[] = $key; $SQL_Val[] = $val;
					}
					$SQL_Command = "INSERT INTO gamelist (".implode(", ", $SQL_Key).") VALUES (".implode(", ", $SQL_Val).");";
					$rowID = 0;
				} elseif ($SQL_Data["__rowPW"] === $JSON->{"__rowPW"}) {
					# Update existing game.
					$SQL_Set = Array();
					foreach($NewData as $key => $val) {
						$SQL_Set[] = $key."=".$val;
					}
					$SQL_Command = "UPDATE gamelist SET ".implode(", ", $SQL_Set)." WHERE __rowId=".$rowID.";";
				} else die("Bad Request.");
				
				$SQLiDatabase->query($SQL_Command) or die(mysqli_error($SQLiDatabase));
				if ($rowID === 0) $rowID = $SQLiDatabase->insert_id;
				
				# Response for BZ2 so it knows to send DELETE.
				$serverOutput .= "{\"POST\":\r\n".
				"{\"__clientReqId\":\"".$JSON->{"__clientReqId"}.
				"\",\"__rowId\":".$rowID.
				",\"__gameId\":\"BZ2\"}\r\n".
				"}\r\n";
		} else die("Invalid JSON.");
	}
	
	$SQLiDatabase->close();
	
	# Specify content length to ensure server does
	# not use chunked encoding. (Crashes winXP clients.)
	header("Content-Length: ".strlen($serverOutput));
	echo $serverOutput;
?>