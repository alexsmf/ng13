<?php
if (!isset($fronts_send_arr)) {
	if (!isset($argv[0])) die('Shell only');
/**
 * На все фротнтенды формирует и закачивает файлы:
 *-конфиг для nginx ( в папку /etc/nginx/sites-available/ файл default )
 *-скрипт adw.php (Anti-DDOS-Watchdog в папку /etc/nginx/ )
 *-sh-скрипт adw (в папку /etc/init.d/) чтобы работало service adv start/stop/restart
 *
 * Запускается из командной строки, в строке параметров передаётся список фронтендов.
 * либо может инклюдится - тогда должны быть заранее заданы все параметры.
 */
	include 'ad_config.php';
	$script_name=array_shift($argv);//скипуем имя скрипта

	//получаем из командной строки список фронтендов, над которыми будем работать
	$fronts_send_arr=get_fronts_from_argv();
	if (is_array($fronts_send_arr)) {
		if($fronts_send_arr[0]=='ok') {
			$is_ok=true;
			array_shift($fronts_send_arr);
		} else {
			$is_ok=false;
		}
	} else {
		if ($fronts_send_arr!==false) die($fronts_send_arr);
	}
}
if (!$fronts_send_arr) die("Need servers list.\n");
/**
 * Необходимы следующие параметры из ad_config.php :
 * $frontend = массив имён фронтендов array('frontname1','frontname2',..)
 * $front_ip = массив IP-адресов фронтендов $ip=$front_ip['frontname']
 * функция front_get_secret($front) должна возвращать ключ шифрования для фронтенда
 */

$sec_file='/etc/nginx/sec_file.txt';//так будет называться контрольный файл на фронтенде
$access_log='/etc/nginx/access.log';//путь для access_log лога для nginx на фронтенде

/**
 * Переменные для формирования секции upstream (бэкенд-сервера)
 */
$upserver_a='127.0.0.1:8080';
$upserver_b='1.2.3.4:80';

/**
 * какие вообще переменные будут подставлены в шаблон?
 * обращаем внимание, чтобы названия переменных не были частью названий других переменных,
 * и чтобы не пересекались с переменными самого nginx, которые использованы в шаблоне.
 */
$rep_vars= 'upserver_a, access_log, front, fr_port, fr_ip, fr_sec_url,
			upserver_b, sec_file';//разделитель - запятая, пробелы игнорируются.

//$fronts_send_arr=$frontend;
//$fronts_send_arr=array('fvds2');
$self_path=realpath('.').'/';

//шаблон-программа для формирования конфига nginx:
$ng_base=<<<'NGBASE'
?# знак вопроса в начале строки значит что строка особая. Разделитель особой строки - пробел.
?# если знака вопроса в начале нет - значит строка предназначена для вывода в итоговый файл.
?# ?# означает произвольный коментарий, который не будет выведен в итоговый файл.
?# ?? (или ?if) за которым следует список фронтендов через пробел - условие.
?#   следующие за условием строки будут выведены только для перечисленных фронтендов.
?# ?- (или ?else) - дальнейшие строки добавятся "иначе", т.е. инвертирует флаг условия
?# ?+ (или ?endif) - конец условия, дальнейшие строки будут добавляться безусловно
?# ?! return php-выражение - если результат выражения не пуст, флаг условия = true
?# напоминание:
?# вместо переменных, перечисленных в списке $rep_vars будут подставлены их значения.
?# в частности, значения подставятся и в ?! php-выражение, так что внимательно.
?# ==================== ПОЕХАЛИ ===================
?# Для начала выведем в файл сроку комментария, в которой будет указан фронтенд и его IP
# Config for $front [ $fr_ip ]
?# 
?# подключаем файл GeoIP - в последних версиях ставится автоматом вместе с nginx
geoip_country  /usr/share/GeoIP/GeoIP.dat;
?# а вот это ВАЖНО: именно этот формат лога хочет разбирать adw.php. Имя формата - watchdog
log_format watchdog '$status $msec $remote_addr "$request" $scheme $geoip_country_code3 "$http_referer"';
?# Описание upstream по имени backend, проще говоря, ссылка на бэкенд
upstream  backend  {
	server	$upserver_a;
?# добавим в режиме бэкапа сервер $upserver_b, на него пойдут запросы если откажет $upserver_a
	server	$upserver_b;
}
?# Описание конфигурации:
server {
	listen	80	default;
?# зададим ещё персональный порт (желательно уникальный) для каждого фронтенда. Опционально.
	listen	$fr_port	default;
	access_log $access_log	watchdog;
	proxy_connect_timeout 10;
	proxy_set_header Host $host;
	proxy_set_header X-Forwarded-For $remote_addr;
	proxy_set_header X-Srv-IP $server_addr;
	proxy_set_header X-Srv-X $scheme;
	proxy_set_header X-Geo-C $geoip_country_code3;

?# благодаря нижеследующему локейшину мы и получаем управление ng-13
	location = $fr_sec_url {
		alias $sec_file;
		add_header Content-Type "text/html; charset=UTF-8";
	}
?# главный локейшин 
	location ~ \.*$ {
		proxy_pass http://backend;
		proxy_buffers 8 16k;
		proxy_buffer_size 32k;
	}
	error_page   500 502 503 504  /50x.html;
	location = /50x.html {
		root   /var/www/nginx-default;
	}
}
NGBASE;
/**
 * Для каждого фронтенда, перечисленного в массиве $fronts_send_arr, делаем это
 */
foreach($fronts_send_arr as $front) {
	$fr_ip=$front_ip[$front];
	//для каждого фронтенда будет своя персональная папка, а имя файла - одинаковое
	$tmp_ng_conf_file='/var/tmp/'.$front;
	if (!is_dir($tmp_ng_conf_file)) mkdir($tmp_ng_conf_file);
	//имя файла adw.php, он потом будет скопирован на фронтенд в /etc/nginx/adw.php
	$tmp_adw_php_file=$tmp_ng_conf_file.'/adw.php';
	//имя файла конфига nginx , будет скопирован на фронтенд в /etc/nginx/sites-available/
	$tmp_ng_conf_file.='/default';
	//переменная для подстановки в шаблон: уникальный порт для каждого фронтенда
	$fr_port=front_get_port($front);
	//переменная для подстановки в шаблон: уникальный ключ шифрования для каждого фронтенда
	$fr_secret=front_get_secret($front);
	//уникальный секретный URL для управления по ng-13
	$fr_sec_url=front_get_sec_url($front);
	//рассчитаем массивы подстановок значения перменных. Переменные - в списке $rep_vars
	$rep_from=$rep_to=array();//два массива: "что ищем" и "на что заменяем"
	$rep_from[]="\t";$rep_to[]=" ";//также заменим все табуляции на пробелы
	foreach(explode(',',$rep_vars) as $v) {//для каждой переменной сделаем это...
		$v=trim($v); $rep_from[]='$'.$v; $rep_to[]=$$v;
	}
	//а теперь одной командой заменим в шаблоне всё что _from на всё что _to
	$conf_arr=str_replace($rep_from,$rep_to,$ng_base);
	//теперь разобъём шаблон на массив строк
	$conf_arr=explode("\n",$conf_arr);
	//и пойдём по всему этому набору строк от начала до конца...
	$is_true=true;//флаг условного вывода: если true - выводим, если false - не выводим
	$ng_conf_contents='';//сюда будем набивать результат для вывода в nginx-config
	foreach($conf_arr as $st) {//пробегаем все строки от начала до конца
		if (substr($st,0,1)=='?') {//вопрос в начале - признак особой конструкции
			$sta=explode(' ',$st);//разделитель особой конструкции - пробел
			$ifc=array_shift($sta);//берём элемент до первого пробела
			switch($ifc) {//в зависимости от того, что это за элемент, сделаем нечто...
			case '??'://проверка, попадает ли текущее имя фронтенда в указанный список
			case '?if'://флаг условного вывода будет по результатам либо true, либо false
				if ($is_true) {//а эта проверка позволяет делать "уточняющие условия"
					$is_true=false;//в массиве $fr_tst будет как раз список указанных фронтендов
					foreach($sta as $fr_tst) {
						if ($fr_tst==$front) $is_true=true;//если хоть один совпадёт будет true
					}
				}
				break;
			case '?-'://конструкция "иначе"
			case '?else'://просто инверируем флаг условного вывода
				$is_true=!$is_true; break;
			case '?+'://конструкция "конец условия"
			case '?end'://просто включаем флаг условного вывода в true
			case '?endif'://иначе говоря, если условие завершено - включаем безусловный вывод
				$is_true=true;
			case '?#'://а это просто для комментариев 
				break;//вообще-то комментарии можно писать и к двум предыдущим конструкциям.
			case '?!'://проверка php-выражения, которое должно зделать return значения
				if ($is_true) {//эта проверка позволяет делать уточняющие условия
					$ev=implode(' ',$sta);//склеиваем массив обратно в строку через пробелы
					$evret=eval($ev);//выполняем php-выражение (оно наподобие "return $a==1;")
					if ($evret===false) echo "Eval($ev)\n";//если выражение вернуло ОШИБКУ
					$is_true=($evret)?true:false;//если результат не пустой - флаг будет true
				}
				break;
			default: //если встречена непонятная комбинация то пока не поздно остановим всё.
				die("Unknown condition $st\n");
			}
		} else {
			//если флаг условного вывода true, то добавляем очередную строку на вывод
			if ($is_true) $ng_conf_contents.=$st."\n";
		}
	}
	//итак, контент для фронтенда $front сформирован в перменной $ng_conf_contents
	echo "$front $tmp_ng_conf_file ";
	//запишем этот контент в файл $tmp_ng_conf_file
	if (!file_put_contents($tmp_ng_conf_file,$ng_conf_contents)) echo "ERROR\n";
	else {
		echo "OK\n";
		//если запись файла прошла успешно, создадим для фронтенда ещё файл adv.php
		$adw_po_contents=file_get_contents($self_path.'adw.php');//должен лежать в той же папке
		$adw_pre_contents=substr($adw_po_contents,0,5)."\n";
		$i=strpos($adw_po_contents,'###'); //всё до этого разделителя - заменяем на новое.
		if ($i===false) die("No divider ### in file $self_path$adw.php");
		$adw_po_contents=substr($adw_po_contents,$i+2);
		//добавим в заголовок всё что хотим для adw.php для этого фронтенда:
		$adw_pre_contents.='$sec_url='."'$fr_sec_url';\n";
		$adw_pre_contents.='$secret_key='."'$fr_secret';\n";
		$adw_pre_contents.='$access_log='."'$access_log';\n";
		$adw_pre_contents.='$sec_file='."'$sec_file';\n";
		//выведем всё это во временный файл, который потом скопируем на фронтенд
		if (!file_put_contents($tmp_adw_php_file,$adw_pre_contents.'/* div */'.$adw_po_contents)) {
			echo "Error write $tmp_adw_php_file\n";
		}
		//будем записывать последовательно, по очереди на каждый фронтенд.
		$front_do_arr=array($front);//сделаем массив из одного фронтенда
		//выполняем копию nginx-config на фронтенд "copy $outfile $to_remote_path"
		$cmd='copy';
		$from_local_path=$tmp_ng_conf_file;
		$to_remote_path='/etc/nginx/sites-available/';
		$is_ok=true;
		require 'remote.php';
		echo "\n";
		
		//копируем созданный adw.php на фронтенд "copy adw.php /etc/nginx/"
		$cmd='copy';
		$from_local_path=$tmp_adw_php_file;
		$to_remote_path='/etc/nginx/';
		$is_ok=true;
		require 'remote.php';
		echo "\n";
		
		//копируем файл adw на фронтенд "copy adw /etc/init.d/"
		$cmd='copy';
		$from_local_path=$self_path.'adw';
		$to_remote_path='/etc/init.d/';
		$is_ok=true;
		require 'remote.php';
		echo "\n";

		//установим права на файл adw командой ssh "chmod 0755 /etc/init.d/adw"
		$cmd='ssh';
		$ssh_cmd='chmod 0755 /etc/init.d/adw';
		require 'remote.php';
		//установим права на файл adw.php командой "chmod 0755 /etc/nginx/adw.php"
		$ssh_cmd='chmod 0755 /etc/nginx/adw.php';
		require 'remote.php';
		//выполняем на фронтенде ssh "service nginx restart"
		$ssh_cmd='service nginx restart';
		require 'remote.php';
		//выполняем на фронтенде ssh "service adw restart"
		$ssh_cmd='service adw restart';
		require 'remote.php';
	}
}
