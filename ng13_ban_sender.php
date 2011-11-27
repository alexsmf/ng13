<?php
if (!isset($argv[0])) die('Shell or include only');
/**
 * посылает на все фронтенды бан/разбан списка IP-адресов, 
 * переданных в командной строке(через пробел), если перед IP стоит минус то разбан.
 */
$script_name=array_shift($argv);//скипуем имя скрипта

include "ad_config.php";
include "ng13_functions.php";
ng13_ban_push(false,$frontend); //инициализируем массив фронтендов, понимающих ng13

while (count($argv)) {
	$ip=array_shift($argv);
	if (!$ip) continue;
	if ($ip=='0.0.0.0') continue;//чисто на всякий случай
	ng13_ban_push($ip,true);
}

$prevret=0;
while($ret=ng13_ban_push()) {
	if($prevret!=$ret) {
		echo "\n";
		$prevret=$ret;
	}
}

