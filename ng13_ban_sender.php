<?php
if (!isset($argv[0])) die('Shell or include only');
/**
 * �������� �� ��� ��������� ���/������ ������ IP-�������, 
 * ���������� � ��������� ������(����� ������), ���� ����� IP ����� ����� �� ������.
 */
$script_name=array_shift($argv);//������� ��� �������

include "ad_config.php";
include "ng13_functions.php";
ng13_ban_push(false,$frontend); //�������������� ������ ����������, ���������� ng13

while (count($argv)) {
	$ip=array_shift($argv);
	if (!$ip) continue;
	if ($ip=='0.0.0.0') continue;//����� �� ������ ������
	ng13_ban_push($ip,true);
}

$prevret=0;
while($ret=ng13_ban_push()) {
	if($prevret!=$ret) {
		echo "\n";
		$prevret=$ret;
	}
}

