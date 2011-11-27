<?php
if (!function_exists('my_exec')) {
	function my_exec($cmd, $input='') {
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
}
if (!isset($cmd)) {
	if (!isset($argv[0])) die('Shell or include only');

	$script_name=array_shift($argv);//имя скрипта

	$cmd=array_shift($argv); //берём имя команды (copy, ssh, batch, ...)

	$tmp_local_path='/var/tmp/';

	//возьмем аргументы соответственно каждой команде
	switch ($cmd) {
	case 'batch':
		if (!isset($argv[1])) die("batch batch_file to_srv1 .. [ok]\n");
	$batch_file=array_shift($argv);//берем файл, в котором должен быть список команд
		break;
	case 'copy':
	case 'copyto':
		if (!isset($argv[2])) die("copy from_local_path to_remote_path to_srv1 to_srv2 ...[ok]\n");
	$from_local_path=array_shift($argv);//берём локальный путь, который будем копировать
	$to_remote_path=array_shift($argv); //берём ремотный путь, куда будем копировать
		break;
	case 'from':
	case 'copyfrom':
		if (!isset($argv[2])) die("from from_remote_path to_local_path from_srv1 ...[ok]\n");
	$from_remote_path=array_shift($argv);//берем удаленный путь, с которого будем копировать
	$to_local_path=array_shift($argv);//берем локальный путь, куда будем копирвать
		if ($to_local_path=='=') $to_local_path=$tmp_local_path;
		break;
	case 'ssh':
		if (!isset($argv[1])) die('ssh "command line" to_srv1 to_srv2 ...[ok]'."\n");
	$ssh_cmd=array_shift($argv);//берём ssh-команду, которую следует удаленн исполнить
		break;
	default:
		echo "Unknowing command: $cmd\n\nPossible command: copy, ssh, batch\n";
		print_r($argv);
		die;
	}
	//теперь в $argv должен остаться список имен хостов, куда будем копировать.

	include 'ad_config.php';//отсюда нужен только массив $frontend[$ip]=$front
	
	$front_do_arr=get_fronts_from_argv();
	if (is_array($front_do_arr)) {
		if($front_do_arr[0]=='ok') {
			$is_ok=true;
			array_shift($front_do_arr);
		} else {
			echo "\n*** Test mode. For work mode add 'ok' to parameters string.***\n\n";
			$is_ok=false;
		}
	} else {
		if ($front_do_arr===false) die("Where servers list?\n");
		die($front_do_arr);
	}
	if (!count($front_do_arr)) die("Where servers list?\n");

}

switch($cmd) {
case 'batch':
	$self_file=realpath('.').'/'.$script_name;
	if(!is_file($batch_file) && substr($batch_file,-7)!='.remote') $batch_file.='.remote';
	if(!is_file($batch_file)) die("Not found source batch_file = '$batch_file'\n");
	$batch_contents=file_get_contents($batch_file);
	$batch_arr=explode("\n",$batch_contents);
	foreach($batch_arr as $st) {
		$st=ltrim($st);
		if (!$st || substr($st,0,1)=='#') continue;
		$parr=explode(' ',$st);
		if (!count($parr)) continue;
		$cmd=array_shift($parr);
		switch($cmd) {
		case 'ssh':
			$ssh_cmd=implode(' ',$parr);
			include $self_file;
			break;
		case 'copy':
		case 'copyto':
			$from_local_path=array_shift($parr);
			$to_remote_path=array_shift($parr);
			include $self_file;
			break;
		case 'from':
		case 'copyfrom':
			$from_remote_path=array_shift($parr);
			if (count($parr)) $to_local_path=array_shift($parr);
				else $to_local_path=$tmp_local_path;
			include $self_file;
			break;
		default:
			$st='php -q '.$self_file.' '.$st.' '.implode(' ',$argv);
			echo shell_exec($st);
		}
	}
	break;
case 'copy':
case 'copyto':
	//проверим, существует ли локальный путь, с которого надо копировать
	$its_file=is_file($from_local_path);
	$its_path=is_dir($from_local_path);
	if ($its_file) echo "Copy file $from_local_path to remote servers:\n";
	if ($its_path) echo "Copy path $from_local_path to remote servers:\n";
	if (!$its_file && !$its_path) die("Not found source path $from_local_path\n");
	//если локальный путь есть, пробуем запустить цикл копирования
	foreach($front_do_arr as $front) {
		$fr_ip=$front_ip[$front];
		if ($its_path) $ins=" -r "; else $ins='';
		$c="scp $ins -B -i ~/.ssh/$front $from_local_path root@$fr_ip:$to_remote_path";
		echo "To $front: $c\n";
		if (isset($is_ok) && $is_ok) {
			$out=shell_exec($c);
			echo $out;
		}
	}
	break;
case 'copyfrom':
case 'from':
	//проверим, существует ли локальный путь, в который надо копировать
	if (substr($to_local_path,-1)!='/') $to_local_path.='/';
	if (!is_dir($to_local_path)) die ("Not found local_path='$to_local_path'\n");
	foreach($front_do_arr as $front) {
		$fr_ip=$front_ip[$front];
		$path_for_front=$to_local_path.$front.'/';
		if (!is_dir($path_for_front)) mkdir($path_for_front);
		$c="scp -r -B -i ~/.ssh/$front root@$fr_ip:$from_remote_path $path_for_front";
		echo "From $front: $c\n";
		if (isset($is_ok) && $is_ok) {
			$out=shell_exec($c);
			echo $out;
		}
	}
	break;
case 'ssh':
	foreach($front_do_arr as $front) {
		$fr_ip=$front_ip[$front];
		$c="ssh -i ~/.ssh/$front root@$fr_ip $ssh_cmd";
		echo "$front: $c\n";
		if (isset($is_ok) && $is_ok) {
			//$out=shell_exec($c);
			$out=my_exec($c,'');
			echo $out['sum']."\n";
		}
	}
}	
