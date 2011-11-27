<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
$self_path=realpath('.');

$frontends_all=array();
//��������� ������ ���������� � ��� ����������� ������ ��� ���:
//.....������ ����������... �� ��������� � ����� ��������
front_add('1.2.3.4' ,'front1','80','abracadabra2','this_is_secure_nginx_url2',0);
front_add('2.3.4.5' ,'front2','80','abracadabra3','this_is_secure_nginx_url3',0);
front_add('3.4.5.6' ,'front3','80','abracadabra4','this_is_secure_nginx_url4',1);

////////////���� ������ ��������������� �� ���������//////////////
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
	$frontend=array();//������ $frontend[$ip]='��� ���������'
	$front_ip=array();//������ $front_ip[��� ���������]=$ip
	$fronts='';//������ ���������� ����� �������: firstvds,truevds,vdscom,inferno
	$fronts_arr=array();//������ ���������� � ���� ������� [0]=>firstvds,[1]=>...
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
 * ������� ����� �� $argv ������ ���������� � ���������� �� ��������.
 * ������� �������� all ��� ���������� � ������ ���� ��������� ����������
 * ���� ��� ������� � �������, ��� ����������� �� ������ (�������� "all -firstvds")
 * ���� ������������ ������� "ok" �� �� ����������� ������� ��������� �������
 * ���� ���������� � ��������� ������ ��� ������ - ���������� false
 * ���� ��������� ����������� ��������� - ���������� ������ � �������.
 */
	global $argv,$frontend,$front_ip;
	if(!count($argv)) return false;
	//��������, ��� �� ��������� ��������� ��� ��������, ���� ��� - �����
	$front_do_arr=array();//�������� ���� ������ ���� ����������, � �������� ����� ��������
	$unknown_front=0;//���� �� ����������� ���������(������� �� ���-��)
	$is_ok=false;//������� � ������ "ok" ������� � ���, ��� ���� � ���������
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

	//��������� ��� ��������, ���������� �� ������ �����
	foreach ($front_do_arr as $key=>$front) {
		if (substr($front,0,1)!='-') continue;
		$front=substr($front,1);
		$i=array_search($front,$front_do_arr);
		if ($i!==false) unset($front_do_arr[$i]);
		unset($front_do_arr[$key]);
	}
	//���� ���� ������� "ok" ������� ��� � ������ �������.
	if ($is_ok) array_unshift($front_do_arr,'ok');

	return $front_do_arr;
}
