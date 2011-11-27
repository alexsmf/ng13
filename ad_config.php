<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
$self_path=realpath('.');

$frontends_all=array();
//Добавляем список фронтендов и все необходимые данные про них:
//.....список фронтендов... на последнем в конце единичка
front_add('1.2.3.4' ,'front1','80','abracadabra2','this_is_secure_nginx_url2',0);
front_add('2.3.4.5' ,'front2','80','abracadabra3','this_is_secure_nginx_url3',0);
front_add('3.4.5.6' ,'front3','80','abracadabra4','this_is_secure_nginx_url4',1);

////////////ниже ничего конфигурировать не требуется//////////////
function front_add($ip,$front,$port='',$secret='',$secure_url='',$recalc=1){
	global $frontends_all;
	$frontends_all[$ip]=array($front,$port,$secret,$secure_url);
	if ($recalc) fronts_recalc();
}
function front_get_port($front) {
	global $front_ip,$frontends_all;
	if (isset($front_ip[$front])) {
		$row=$frontends_all[$front_ip[$front]];
		return $row[1]; //its a port
	}
}
function front_get_secret($front) {
	global $front_ip,$frontends_all;
	if (isset($front_ip[$front])) {
		$row=$frontends_all[$front_ip[$front]];
		return $row[2]; //its a secret
	}
}
function front_get_sec_url($front) {
	global $front_ip,$frontends_all;
	if (isset($front_ip[$front])) {
		$row=$frontends_all[$front_ip[$front]];
		return '/'.$row[3]; //its a secure_url
	}
}
function ng13_url($front) {
	global $front_ip,$frontends_all;
	if (isset($front_ip[$front])) {
		$fr_ip=$front_ip[$front];
		$row=$frontends_all[$fr_ip];
		$url='http://'.$fr_ip;
		if ($row[1]) $url.=':'.$row[1];
		$url.='/'.$row[3];
		return $url;
	}
}
function fronts_recalc() {
	global $frontends_all;
	global $frontend,$front_ip,$fronts,$fronts_arr;
	$frontend=array();//массив $frontend[$ip]='имя фронтенда'
	$front_ip=array();//массив $front_ip[имя фронтенда]=$ip
	$fronts='';//список фронтендов через запятую: firstvds,truevds,vdscom,inferno
	$fronts_arr=array();//список фронтендов в виде массива [0]=>firstvds,[1]=>...
	foreach($frontends_all as $ip=>$row) {
		$front=$row[0];

		$frontend[$ip]=$front;

		$front_ip[$front]=$ip;

		if ($fronts) $fronts.=',';
		$fronts.=$front;

		$fronts_arr[]=$front;
	}
}
function get_fronts_from_argv(){
/**
 * Функция берет из $argv список фронтендов и возвращает их массивом.
 * возможо указание all для добавления в список всех известных фронтендов
 * если имя указано с минусом, оно исключается из списка (например "all -firstvds")
 * если присутствует элемент "ok" то он добавляется нулевым элементом массива
 * если параметров в командной строке нет вообще - возвращает false
 * если упомянуты неизвестные фронтенды - возвращает строку с ошибкой.
 */
	global $argv,$frontend,$front_ip;
	if(!count($argv)) return false;
	//проверим, все ли указанные фронтенды нам известны, если нет - облом
	$front_do_arr=array();//напихаем сюда список имен фронтендов, с которыми будем работать
	$unknown_front=0;//есть ли неизвестные фронтенды(считаем их кол-во)
	$is_ok=false;//наличие в строке "ok" говорит о том, что надо её исполнить
	foreach($argv as $front) {
		if ($front=='ok') { $is_ok=true; continue; }
		if ($front=='all') {
			foreach($frontend as $ip=>$front) {
				if (!in_array($front,$front_do_arr)) $front_do_arr[]=$front;
			}
			continue;
		}
		$tmp_front=(substr($front,0,1)=='-')?substr($front,1):$front;
		if (isset($front_ip[$tmp_front])) {
			if (!in_array($front,$front_do_arr)) $front_do_arr[]=$front;
		} else {
			echo "Unknown frontend '$front' \n";
			$unknown_front++;
		}
	}
	if ($unknown_front) return "Error: $unknown_front unknown frontends";

	//вычеркнем все элементы, упомянутые со знаком минус
	foreach ($front_do_arr as $key=>$front) {
		if (substr($front,0,1)!='-') continue;
		$front=substr($front,1);
		$i=array_search($front,$front_do_arr);
		if ($i!==false) unset($front_do_arr[$i]);
		unset($front_do_arr[$key]);
	}
	//если есть элемент "ok" добавим его в начало массива.
	if ($is_ok) array_unshift($front_do_arr,'ok');

	return $front_do_arr;
}
