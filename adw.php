<?php
//��� �������� ����� �������������� ������ ���� ����� ����� ������������� ����������� ������:
$secret_key='there is a secret key';
$access_log='/etc/nginx/access.log';
$sec_file='/etc/nginx/sec_file.txt';
//�������� �������� �� ��� ������� - ��� ����������� "���������� �����".
//�� ��� ���� ������� �������������� ����������, �� ��� ���� - ������� ����������.
###
while(1) {
	//������ ��� � ������ �������� � ����������� ������ ����� ������
	$handle = popen("tail -F $access_log 2>&1", 'r');
	while(!feof($handle))
		analyze(fgets($handle));
	pclose($handle);
}
function analyze($st) {
	//��������� ������� ������ �� ������������ ��� ���������
	$req_arr=parse_log_st($st);
	//���� �������� ng13-����������� URL 
	if($req_arr['url']==$GLOBALS['sec_url']) {
		echo date('h:i:s',$req_arr['tm'])." ctrl:";
		$req_arr['result']=control_hit($req_arr);
		echo $req_arr['result']."\n";
	}
	//���� ���� ���������� ��������� �����
	if (function_exists('last_hit_push')) last_hit_push($req_arr);
}

function parse_log_st($st){
/**
 * ��������� ������ ���-����� �� ������ ����������:
 *	[ans]		- ��� ������ ������� (200,404 � �.�)
 *	[tm]		- ����� ������� � �������� �������
 *	[ip]		- IP-����� �������
 *	[scheme]	- ������� http, https
 *	[reqm]		- ����� ������� GET, POST, ...
 *	[uri]		- ������ ����� ������� ��� ��� ���� � ����
 *	[url]		- ����� ������ ������� ��� ���������� (������� ����� ����� "?"(
 *	[par]		- ��������� ����� ����� "?", ���� ������ ������ ���� �� ���.
 *	[country3]	- ������������ ��� ������
 */
	$parr=explode(" ",$st);
	$rp=0;
	$ans=$parr[$rp++];//��� ������ ������� (200, 403 � �.�)
	$tm=$parr[$rp++];// ��������� � ������� �������� �������
	$ip=$parr[$rp++]; //IP �������
	$reqm=substr($parr[$rp],1); //"GET , "POST, "-", ...
	if (substr($parr[$rp++],-1)!='"') {
		$uri=chop($parr[$rp]);//����������� URL � ������� /abc.php
		if (substr($parr[$rp++],-1)!='"') {
			$rp++; //�� ���� ��� ������ ���� �������� ��������� HTTP/1.1"
		} else {
			$uri=substr($uri,0,-1);//������� ������� � �����
		}
	} else {
		$uri='';
		$reqm=substr($reqm,0,-1);//������� ������� � �����
	}
	$scheme=chop($parr[$rp++]); //�������� http ��� https
	$country3=chop($parr[$rp++]); //������ �� GeoIP
	$referer=chop($parr[$rp++]); //�� ����

	//��������� ������ URI �� $url ��� ���������� � $par ����������
	$i=strpos($uri,'?');
	if ($i===false) {
		$url=$uri;
		$par='';
	} else {
		$par=substr($uri,$i+1);
		$url=substr($uri,0,$i);
	}
	//���������� ������ ��������
	return compact('ans','tm','ip','scheme','reqm','uri','url','par','country3','referer');
}

function ng13_decode($ctrlst,$secret_key) {
/**
 * ������������� ������ ������� NG-13 � ��������� ����������� ����� �� ����� $secret_key
 * � ����� ������������ ����� ������� (��������� ������ �� ����� ���� "������" �����������)
 * ���� ��� �������� �������, �� ���������� ������, � ����� false.
 */
	static $prev_rt=0;//����� ������� ����������� �������
	static $prev_req_arr=array();//������ �������� �� ��������� ����� �������
	if (strlen($ctrlst)<16) return false;
	$req=base64_decode($ctrlst);
	if (strlen($req)<13) return false;
	$checksum=substr($req,0,8);//����������� ����� �� �������
	$rt=substr($req,8,4);//����� ������� �� �������
	$ctrlst=substr($req,12);//������������ ������ ������� �� ����� ������
	//���������� �������� ����������� �����
	$testsum=substr(md5($rt.$secret_key.$ctrlst),-16);
	$testsum=pack('H*',$testsum);
	//������� ������������ � ���������� ����������� �����
	if($testsum!=$checksum) return false;
	$rt=unpack("N",$rt);
	if ($rt<$prev_rt) return false;
	//���� ��� ������ ������ � ������� ������� �������, �� �� �� ����� ���� ������� ������
	if (!$prev_rt && $rt<time()-10000) return false;//�������� 3 ���� ������ ��������.
	if ($rt==$prev_rt) {
		//��������, �� ���� �� ��� ����� �������� �� ���� ����� �������, � ���� ���� �� �����.
		if (in_array($ctrlst,$prev_req_arr)) return false;
		//���� ����� �� �������� � ��� ������� ��� �� ����, �� �������� ������� ������
		$prev_req_arr[]=$ctrlst;//������� ������� ������ � ������ �������� ������� �������
	} else {
		$prev_req_arr=array($ctrlst);//������ �������� ������� ������� ����� ������� ��������
		$prev_rt=$rt;
	}
	return $ctrlst;
}

function control_hit($req_arr){
	$ctrlst=$req_arr['par'];//������ ������ ���������� � ��������� �
	$ctrlst=ng13_decode($ctrlst,$GLOBALS['secret_key']);
	//���� ���������� �� ������� ��� �� ������ ���� - �������
	if ($ctrlst===false) return 'Incorrect control';
	$cmd_out='Unknown error'; //��� ������ � ����� 
	$c=ord($ctrlst[0]);//��� ����������� �������
	if (!($c & 128)) { //���� ��� ban/unban-request
		$ip_b_content='';//���� ����� �������� ������ ���� route add / route del
		//�������� ��� ���������� IP-������, �� 4 �����
		for($i=1;$i<strlen($ctrlst)-1;$i+=4) {
			//������������� ��������� IP-����� �� ������ � ��������� ���
			$ip=inet_ntop(substr($ctrlst,$i,4));
			if ($ip=='0.0.0.0') continue;//�� ������ ������
			if ($c) {//���� ���� ������ �� ������������
				$c--;//��������� ������� ���������� �� ������������ �������
				$ip_b_content.="route del $ip/32\n";
			} else {
				//���� ����� �� ����������� - ��������� ��� ���:
				$ip_b_content.="route add $ip/32 dev lo\n";
			}
		}
		//������� ����������� ���������� �� ��������� ����:
		$ip_b_file='/var/tmp/banctrl.route';
		while(1) {
			if (!($f=fopen($ip_b_file,'w'))) { echo "Error opening $ip_b_file\n"; break; }
			if (!fwrite($f,$ip_b_content)) { echo "Error writing $ip_b_file\n"; break; }
			fclose($f);
			//���� ������ ������ �������:
			chmod($ip_b_file,0755);//�� ������ ������ �������� �����
			//�������� ���������� ����� ip -b ����-����
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
//������� ������ shell_exec, ����� � ����� �������� ��������� stdout/stderr
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
	static $max_req_cnt=100;//������� ��������� �������� ����� ����������
	static $last_wr_time=0;
	//���� ��� ������ ������ ��������� ��������
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
			//���� ������ ��� ����� ������ ����� - ������� ��� � ������������ nginx
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