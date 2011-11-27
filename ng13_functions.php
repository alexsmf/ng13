<?php
/**
 * ����� ������� ��� ������ � �������� NG-13:
 * ng13_encode - ������������ ����� ������ ���������� � ������ NG-13
 * ng13_decode - ������ ����� ������ ���������� �� ������� NG-13
 * call_ng13   - ����� ������������� ������� ������� NG-13 �� ��������� URL
 * ng13_ban_push - ����������� ���������� NG-13 �������� �� ���/������ ������ �������
 */
function ng13_encode($ctrlst,$secret_key) {
/**
 * ������ NG-13 ��� ���������� �������� ������ ����������:
 * ������ 8 ���� (256 ���) - ����������� �����, ��� �� � �������
 * ����� 4 ����� (32 ���) - ����� �������
 * ����� ������������ 1 ���� - ��� �������,
 * ��������� ����� �� ����� ������ - ������������ ������ � ����������� �� ���� �������.
 */
	$rt=pack('N',time());//4 ����� ����� �������
	$checksum=substr(md5($rt.$secret_key.$ctrlst),-16);
	$checksum=pack('H*',$checksum); //8 ���� ������� ������ $secret_key
	//���������� � base64
	return base64_encode($checksum.$rt.$ctrlst);
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
function call_ng13($url, $req_or_ctrlst, $secret_key=false) {
/**
 * ��������� �������� ������������� ������������ ������� � ������� NG-13
 * � ����� �������� ���� ������� (������ �������� ��, ��� ������� ������)
 * �� �����:
 * $url �� ������ ����� ��������� ����� ���������� ?������
 * $req_or_ctrlst - ������ �������
 * $secret_key - ���� ����������, ������� ����� �������� ������.
 * ���� ������ ������� ��� �����������, �� $secret_key ������ �������������.
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
 * ����������� ���������� ���/������-�������� �� ��� ���������.
 * ���� �� ����� ���� $in_ip �� ��������� ��� � ������ �� ���/������
 * ���� � ������ $in_ip ����� ���� �����, ������ ����� ��� ������, ��������, -1.2.3.4
 * ��� ���� ���� �������� $dont_start=true �� �� �������� ���������� �����,
 * � ������ ��������� � �������.
 * ����� ������� ��� ���������� ���������� ���������� ���������� �������,
 * ���� ������� ��������, � ��� �� �������� ����� ����� ��������, ����
 * ����� ���������� ���������� ������� � ������� �������������� IP ��� ����.
 * � ����� ������ (����� ��������������) ���� �������� ������� ������ ����������
 * �������� ��� ���: ng13_ban_push(false,array(������ ����������))
 * � �������� ��������� $in_ip ����� ���� ������� ����� ������ IP-�������.
 */
	static $ch=array();//������ CURL - ������������
	static $mh=false;//���������������� CURL
	static $started=0;//���������� ����: ������� �����-��������� ��������

	static $ng13_front_arr=false; //�� ������ ��� ����� ���� ������ ����������
	static $ng13_seq_arr=false;//������� ����� �� ������� ���������
	if ($ng13_seq_arr===false) {//�������������� ������� ������� ���������
		if (is_array($dont_start)) {
			$ng13_front_arr=$dont_start;
			foreach($ng13_front_arr as $front) $ng13_seq_arr[$front]=array();
		} else die("Array \$ng13_front_arr not initialized.\n");
	}
	$max_threads=32; //������������ ���-�� ������������ �������, ���������� �� �����.

	$running = NULL;
	if ($mh!==false) {
		//���� ������-���������� ������, ��������� ������, ������� ������ ���-�� $running
		curl_multi_exec($mh,$running);
		//���� ���� �����-�� "�������" �� �������, ������� �� ��� ������ �����
		while ($mhinfo = curl_multi_info_read($mh)) {
			$chinfo = curl_getinfo($mhinfo['handle']);
			//������� ��� ������ ������� � ������ URL-�������
			echo $chinfo['http_code'].' '.$chinfo['url']."\n";
		}
		if (!$running){//���� ���������� ��������� �� ��������, ��������� ��� ��������.
			foreach($ng13_front_arr as $front) {
				if (!isset($ch[$front])) continue;
				//���� ����� ���������� ��������� - ��������� ���
				curl_multi_remove_handle($mh, $ch[$front]);
				curl_close($ch[$front]);
				unset($ch[$front]);
				$started--;
			}
			//��������� ����������������
			curl_multi_close($mh);
			$mh=false;
			echo "** Complete. Sockets closed ** \n";
		}
	}
	if ($in_ip) {
		//������� ��������� IP (��� ������) � ������� �� ������ ��������, ���� ��� ��� ���
		if (!is_array($in_ip)) $in_ip=array($in_ip);
		foreach($in_ip as $ip) {
			echo "Push for ban: $ip\n";
			foreach($ng13_front_arr as $front) {
				if (!in_array($in_ip,$ng13_seq_arr[$front])) $ng13_seq_arr[$front][]=$ip;
			}
		}
	} else {
		//���� $in_ip �� ������, � ���� ���������� �����-��������, �� �������
		if ($running) return $running;//� ���������� ���-�� ���������� �����-���������
		//���� ���������� �����-��������� ���, ��������, ��� �� ���� � ������� �� ���
		$dont_start=true;
		foreach($ng13_front_arr as $front) {
			if (count($ng13_seq_arr[$front])) {//���� ������� ��������� �� �����
				echo "Have IP in ban-sequence\n";
				$dont_start=false;
				break;
			}
		}
	}

	if ($dont_start) return $running;

	if ($mh===false) {
		echo "to:";
		// ������ ���������������� CURL
		$mh = curl_multi_init();
		// �� ������� ��������� ���� ������ ��������������� ��� ����������� ����������

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
				//��������� URL �������
				$ng13_url=	ng13_url($front).'?'.
							ng13_encode($ctrlst,front_get_secret($front));
				//����������� CURL ��� ����� �������
				$ch[$front] = curl_init();
				curl_setopt($ch[$front], CURLOPT_URL, $ng13_url);
				curl_setopt($ch[$front], CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch[$front], CURLOPT_TIMEOUT, 8);
				curl_setopt($ch[$front], CURLOPT_NOBODY,true);//������ ������ HEAD
				curl_setopt($ch[$front], CURLOPT_SSL_VERIFYPEER, false);
				//��������� CURL � ����������������
				curl_multi_add_handle($mh,$ch[$front]);
			}
			//�� ��������� ������������ ���������� ������������� �����-���������
			if (++$started>=$max_threads) break;
		}
		//��������� ��� �����������
		echo "GO!\n";
		$mrc = curl_multi_exec($mh, $running);
	}
	return $running;
}
