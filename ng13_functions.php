<?php
/**
 * Набор функций для работы с форматом NG-13:
 * ng13_encode - запаковывает байты строки управления в формат NG-13
 * ng13_decode - достаёт байты строки управления из формата NG-13
 * call_ng13   - вызов произвольного запроса формата NG-13 на указанный URL
 * ng13_ban_push - асинхронный рассыльщик NG-13 запросов на бан/разбан списка адресов
 */
function ng13_encode($ctrlst,$secret_key) {
/**
 * Формат NG-13 для безопасной передачи строки управления:
 * первые 8 байт (256 бит) - контрольная сумма, она же и подпись
 * затем 4 байта (32 бит) - метка времени
 * затем обязательный 1 байт - код команды,
 * остальные байты до конца строки - произвольные данные в зависимости от кода команды.
 */
	$rt=pack('N',time());//4 байта метка времени
	$checksum=substr(md5($rt.$secret_key.$ctrlst),-16);
	$checksum=pack('H*',$checksum); //8 байт подпись ключем $secret_key
	//возвращаем в base64
	return base64_encode($checksum.$rt.$ctrlst);
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
function call_ng13($url, $req_or_ctrlst, $secret_key=false) {
/**
 * Выполняет передачу произвольного управляющего запроса в формате NG-13
 * в ответ получает файл ответов (вернее получает то, что ответит сервер)
 * На входе:
 * $url на котрый будет обращение через добавление ?запрос
 * $req_or_ctrlst - данные запроса
 * $secret_key - ключ шифрования, которым будет подписан запрос.
 * если запрос передан уже подписанным, то $secret_key должен отсутствовать.
 */
    static $ch = null;
    if (is_null($ch)) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; call_ng13)');
	}
	if ($secret_key!==false) $req_or_ctrlst=ng13_encode($req_or_ctrlst,$secret_key);
	$url.='?'.$req_or_ctrlst;
	echo "$url\n";
	curl_setopt($ch,CURLOPT_TIMEOUT, 4);
	curl_setopt($ch, CURLOPT_URL, $url);
	//curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/html;charset=UTF-8'));
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	return curl_exec($ch);
}

function ng13_ban_push($in_ip=false,$dont_start=false) {
/**
 * Асинхронный рассыльщик бан/разбан-запросов на все фронтенды.
 * Если на входе есть $in_ip то добавляет его в список на бан/разбан
 * Если в начале $in_ip стоит знак минус, значит будет его разбан, например, -1.2.3.4
 * при этом если параметр $dont_start=true то не стартует выполнение сразу,
 * а только добавляет в очередь.
 * вызов функции без параметров возвращает количество работающих сокетов,
 * если таковые работают, а так же стартует новую пачку запросов, если
 * после завершения предыдущих сокетов в очереди обнаруживаются IP для бана.
 * в самом начале (перед использованием) надо передать функции список фронтендов
 * делается это так: ng13_ban_push(false,array(список фронтендов))
 * в качестве параметра $in_ip может быть передан также массив IP-адресов.
 */
	static $ch=array();//массив CURL - дескрипторов
	static $mh=false;//мультидескриптор CURL
	static $started=0;//внутренний учёт: сколько сокет-процессов запущено

	static $ng13_front_arr=false; //из внешки нам нужен этот список фронтендов
	static $ng13_seq_arr=false;//очередь банов по каждому фронтенду
	if ($ng13_seq_arr===false) {//инициализируем очередь пустыми массивами
		if (is_array($dont_start)) {
			$ng13_front_arr=$dont_start;
			foreach($ng13_front_arr as $front) $ng13_seq_arr[$front]=array();
		} else die("Array \$ng13_front_arr not initialized.\n");
	}
	$max_threads=32; //максимальное кол-во параллельных сокетов, установить по вкусу.

	$running = NULL;
	if ($mh!==false) {
		//если мульти-дескриптор открыт, выполняем запрос, который вернет кол-во $running
		curl_multi_exec($mh,$running);
		//если есть какие-то "новости" от сокетов, выведем их для забавы юзера
		while ($mhinfo = curl_multi_info_read($mh)) {
			$chinfo = curl_getinfo($mhinfo['handle']);
			//выведем код ответа сервера и строку URL-запроса
			echo $chinfo['http_code'].' '.$chinfo['url']."\n";
		}
		if (!$running){//если работающих процессов не осталось, закрываем все открытые.
			foreach($ng13_front_arr as $front) {
				if (!isset($ch[$front])) continue;
				//если какой дескриптор определен - закрываем его
				curl_multi_remove_handle($mh, $ch[$front]);
				curl_close($ch[$front]);
				unset($ch[$front]);
				$started--;
			}
			//закрываем мультидескриптор
			curl_multi_close($mh);
			$mh=false;
			echo "** Complete. Sockets closed ** \n";
		}
	}
	if ($in_ip) {
		//добавим указанный IP (или список) в очередь на каждый фронтенд, если там ещё нет
		if (!is_array($in_ip)) $in_ip=array($in_ip);
		foreach($in_ip as $ip) {
			echo "Push for ban: $ip\n";
			foreach($ng13_front_arr as $front) {
				if (!in_array($in_ip,$ng13_seq_arr[$front])) $ng13_seq_arr[$front][]=$ip;
			}
		}
	} else {
		//если $in_ip не указан, и есть работающие сокет-процессы, то выходим
		if ($running) return $running;//и возвращаем кол-во работающих сокет-процессов
		//если работающих сокет-процессов нет, проверим, нет ли чего в очереди на бан
		$dont_start=true;
		foreach($ng13_front_arr as $front) {
			if (count($ng13_seq_arr[$front])) {//если очередь оказалась не пуста
				echo "Have IP in ban-sequence\n";
				$dont_start=false;
				break;
			}
		}
	}

	if ($dont_start) return $running;

	if ($mh===false) {
		echo "to:";
		// создаём мультидескриптор CURL
		$mh = curl_multi_init();
		// по каждому фронтенду берём список предназначенных для забанивания айпишников

		foreach($ng13_front_arr as $front) {
			if (!count($ng13_seq_arr[$front])) continue;
			echo $front.' ';
			$unban_cnt=0; $unban_st=''; $ban_st='';
			foreach($ng13_seq_arr[$front] as $key=>$ip_ctrl) {
				$unban_flag=(substr($ip_ctrl,0,1)=='-')?true:false;
				if ($unban_flag) $ip_ctrl=substr($ip_ctrl,1);
				$pip=@inet_pton($ip_ctrl);
				if ($pip===false) echo "*** Error , Bad IP=$ip_ctrl\n";
				else {
					if ($unban_flag) {
						if ($unban_cnt < 127) {
							$unban_st.=$pip;
							$unban_cnt++;
							unset($ng13_seq_arr[$front][$key]);
						}
					} else {
						$ban_st.=$pip;
						unset($ng13_seq_arr[$front][$key]);
					}
				}
			}
			$ctrlst=chr($unban_cnt).$unban_st.$ban_st;
			if (strlen($ctrlst)>1) {
				//формируем URL запроса
				$ng13_url=	ng13_url($front).'?'.
							ng13_encode($ctrlst,front_get_secret($front));
				//настраиваем CURL для этого запроса
				$ch[$front] = curl_init();
				curl_setopt($ch[$front], CURLOPT_URL, $ng13_url);
				curl_setopt($ch[$front], CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch[$front], CURLOPT_TIMEOUT, 8);
				curl_setopt($ch[$front], CURLOPT_NOBODY,true);//пошлем только HEAD
				curl_setopt($ch[$front], CURLOPT_SSL_VERIFYPEER, false);
				//добавляем CURL в мультидескриптор
				curl_multi_add_handle($mh,$ch[$front]);
			}
			//не превышаем максимальное количество одновременных сокет-процессов
			if (++$started>=$max_threads) break;
		}
		//запускаем все дескрипторы
		echo "GO!\n";
		$mrc = curl_multi_exec($mh, $running);
	}
	return $running;
}
