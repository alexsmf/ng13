<?php
//При отправке файла конфигуратором вместо этих строк будут автоматически подставлены другие:
$secret_key='there is a secret key';
$access_log='/etc/nginx/access.log';
$sec_file='/etc/nginx/sec_file.txt';
//обращаем внимание на три решетки - это разделитель "отрезаемой части".
//всё что выше решеток конфигуратором заменяется, всё что ниже - остаётся неизменным.
###
while(1) {
	//читаем лог в режиме реалтайм и анализируем каждую новую строку
	$handle = popen("tail -F $access_log 2>&1", 'r');
	while(!feof($handle))
		analyze(fgets($handle));
	pclose($handle);
}
function analyze($st) {
	//разбиваем входную строку на интересующие нас параметры
	$req_arr=parse_log_st($st);
	//если запрошен ng13-управляющий URL 
	if($req_arr['url']==$GLOBALS['sec_url']) {
		echo date('h:i:s',$req_arr['tm'])." ctrl:";
		$req_arr['result']=control_hit($req_arr);
		echo $req_arr['result']."\n";
	}
	//если ведём мониторинг последних хитов
	if (function_exists('last_hit_push')) last_hit_push($req_arr);
}

function parse_log_st($st){
/**
 * разбивает строку лог-файла на массив параметров:
 *	[ans]		- код ответа сервера (200,404 и т.д)
 *	[tm]		- время события в числовом формате
 *	[ip]		- IP-адрес клиента
 *	[scheme]	- проокол http, https
 *	[reqm]		- метод запроса GET, POST, ...
 *	[uri]		- полная срока запроса как она есть в логе
 *	[url]		- часть строка запроса без параметров (которые после знака "?"(
 *	[par]		- параметры после знака "?", либо пустая строка если их нет.
 *	[country3]	- трёхбуквенный код страны
 */
	$parr=explode(" ",$st);
	$rp=0;
	$ans=$parr[$rp++];//код ответа сервера (200, 403 и т.д)
	$tm=$parr[$rp++];// датавремя в готовом числовом формате
	$ip=$parr[$rp++]; //IP клиента
	$reqm=substr($parr[$rp],1); //"GET , "POST, "-", ...
	if (substr($parr[$rp++],-1)!='"') {
		$uri=chop($parr[$rp]);//запрошенный URL в формате /abc.php
		if (substr($parr[$rp++],-1)!='"') {
			$rp++; //по идее тут должен быть протокол наподобие HTTP/1.1"
		} else {
			$uri=substr($uri,0,-1);//обрежем кавычку в конце
		}
	} else {
		$uri='';
		$reqm=substr($reqm,0,-1);//обрежем кавычку в конце
	}
	$scheme=chop($parr[$rp++]); //протокол http или https
	$country3=chop($parr[$rp++]); //страна по GeoIP
	$referer=chop($parr[$rp++]); //он есть

	//разбиваем строку URI на $url без параметров и $par параметров
	$i=strpos($uri,'?');
	if ($i===false) {
		$url=$uri;
		$par='';
	} else {
		$par=substr($uri,$i+1);
		$url=substr($uri,0,$i);
	}
	//возвращаем массив значений
	return compact('ans','tm','ip','scheme','reqm','uri','url','par','country3','referer');
}

function ng13_decode($ctrlst,$secret_key) {
/**
 * Распаковывает строку формата NG-13 и проверяет контрольную сумму по ключу $secret_key
 * а также правильность метки времени (следующий запрос не может быть "раньше" предыдущего)
 * если все проверки успешны, то возвращает данные, а иначе false.
 */
	static $prev_rt=0;//метка времени предыдущего запроса
	static $prev_req_arr=array();//список запросов на последней метке времени
	if (strlen($ctrlst)<16) return false;
	$req=base64_decode($ctrlst);
	if (strlen($req)<13) return false;
	$checksum=substr($req,0,8);//контрольная сумма из запроса
	$rt=substr($req,8,4);//метка времени из запроса
	$ctrlst=substr($req,12);//произвольные данные запроса до конца строки
	//подсчитаем тестовую контрольную сумму
	$testsum=substr(md5($rt.$secret_key.$ctrlst),-16);
	$testsum=pack('H*',$testsum);
	//сравним подсчитанную и переданную контрольные суммы
	if($testsum!=$checksum) return false;
	$rt=unpack("N",$rt);
	if ($rt<$prev_rt) return false;
	//если это первый запрос с момента запуска скрипта, то он не может быть слишком старым
	if (!$prev_rt && $rt<time()-10000) return false;//примерно 3 часа предел старости.
	if ($rt==$prev_rt) {
		//проверим, не было ли уже таких запросов на этой метке времени, и если были то облом.
		if (in_array($ctrlst,$prev_req_arr)) return false;
		//если таких же запросов в эту секунду ещё не было, то запомним текущий запрос
		$prev_req_arr[]=$ctrlst;//добавим текущий запрос в список запросов текущей секунды
	} else {
		$prev_req_arr=array($ctrlst);//массив запросов текущей секунды будет текущим запросом
		$prev_rt=$rt;
	}
	return $ctrlst;
}

function control_hit($req_arr){
	$ctrlst=$req_arr['par'];//возьмём строку управления и распакуем её
	$ctrlst=ng13_decode($ctrlst,$GLOBALS['secret_key']);
	//если распаковка не удалась или не совпал ключ - выходим
	if ($ctrlst===false) return 'Incorrect control';
	$cmd_out='Unknown error'; //что вернем в ответ 
	$c=ord($ctrlst[0]);//код управляющей команды
	if (!($c & 128)) { //если это ban/unban-request
		$ip_b_content='';//сюда будем набивать строки типа route add / route del
		//пробежим все переданные IP-адреса, по 4 байта
		for($i=1;$i<strlen($ctrlst)-1;$i+=4) {
			//распаковываем очередной IP-адрес из байтов в текстовый вид
			$ip=inet_ntop(substr($ctrlst,$i,4));
			if ($ip=='0.0.0.0') continue;//на всякий случай
			if ($c) {//если есть адреса на разбанивание
				$c--;//уменьшаем счётчик оставшихся на разбанивание адресов
				$ip_b_content.="route del $ip/32\n";
			} else {
				//если адрес на забанивание - добавляем его так:
				$ip_b_content.="route add $ip/32 dev lo\n";
			}
		}
		//запишем управляющее содержание во временный файл:
		$ip_b_file='/var/tmp/banctrl.route';
		while(1) {
			if (!($f=fopen($ip_b_file,'w'))) { echo "Error opening $ip_b_file\n"; break; }
			if (!fwrite($f,$ip_b_content)) { echo "Error writing $ip_b_file\n"; break; }
			fclose($f);
			//если запись прошла успешно:
			chmod($ip_b_file,0755);//на всякий случай выставим права
			//исполним записанное через ip -b батч-файл
			$cmd="ip -b $ip_b_file";
			echo "exec:$cmd\n";
			$out=my_exec($cmd);
			$cmd_out="BAN_CTRL: $ip_b_content";
			if ($out['sum']) $cmd_out.="Results:".$out['sum'];
			break;
		}
	}
	return $cmd_out;
}
function my_exec($cmd, $input='') {
//Функция вместо shell_exec, чтобы в ответ получить вменяемые stdout/stderr
	$proc=proc_open($cmd,array(
		0=>array('pipe', 'r'),
		1=>array('pipe', 'w'),
		2=>array('pipe', 'w'),
	),$pipes);
	fwrite($pipes[0], $input);fclose($pipes[0]);
	$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
	$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
	$rtn=proc_close($proc);
	$sum=$stdout;
	if ($stderr) $sum.="\nErr: $stderr";
	return array(
		'sum'=>$sum,
		'stdout'=>$stdout,
		'stderr'=>$stderr,
		'return'=>$rtn 
	);
}

function last_hit_push($in_arr) {
	extract($in_arr);//$ans,$tm,$ip,$scheme,$reqm,$uri,$country3
	static $last_hits_arr=array();
	static $max_req_cnt=100;//сколько последних запросов будем мониторить
	static $last_wr_time=0;
	//файл для вывода списка последних запросов
	$html_lasthits_report=$GLOBALS['sec_file'];
	$html_lasthits_header=<<<HTMLLHEAD
<html><head><title>Last hits log</title>
</head>
<body><h2>Last hits log:</h2>
HTMLLHEAD;
	$html_lasthits_footer=<<<HTMLFFOOTER
</body></html>
HTMLFFOOTER;
	if (count($last_hits_arr)>$max_req_cnt) array_shift($last_hits_arr);
	$st=date("H:i:s",$tm).' '.$ans.' '.
	substr('     '.$ip,-15).' '.
	str_pad($country3, 3, " ", STR_PAD_BOTH).' ';
	switch($scheme) {
		case 'http': $st.='h'; break;
		case 'https': $st.='s'; break;
		default: $st.='?'.$scheme; break;
	}
	if (isset($result)) {
		$st.='<font color="blue">'.$result.'</font>';
	} else {
		$st.=$reqm.' '.$uri;
	}
	$last_hits_arr[]=array('st'=>$st,'ip'=>$ip);
	if (!$last_wr_time) $last_wr_time=time();
	if(isset($result) || (time()-$last_wr_time>5)){
		$last_wr_time=time();
		$f=fopen($html_lasthits_report,'w');
		if ($f) {
			fwrite($f,$html_lasthits_header);
			fwrite($f,'<pre>');
			//если размер лог файла сильно вырос - грохнем его и перезапустим nginx
			$fsize=filesize($GLOBALS['access_log']);
			if ($fsize>9999999) {
				shell_exec('rm '.$GLOBALS['access_log']);
				shell_exec('service nginx restart');
				clearstatcache();
			}
			for($i=count($last_hits_arr)-1;$i>0;$i--) {
				$par=$last_hits_arr[$i];
				fwrite($f,$par['st']."\n");
			}
			fwrite($f,'</pre>');
			fwrite($f,$html_lasthits_footer);
			fclose($f);
		} else echo "\nCan't open $html_lasthits_report\n";
	}
}