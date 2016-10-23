<?php

// this is a helper script which helps you to update usernames

$connFlarum = new mysqli("localhost","user","password","flarum");
$connPHPBB = new mysqli("localhost","user","password","phpbb");
$connFlarum->set_charset("utf8");
$connPHPBB->set_charset("utf8");
$resUsersFlarum = $connFlarum->query("select username,email from users where email not like '%nonexistingmail.ru'");
while ($userFlarum = $resUsersFlarum->fetch_assoc()) {
	$resUserPHPBB = $connPHPBB->query("select username from phpbb_users where user_email = '{$userFlarum["email"]}' order by user_regdate asc limit 1");
	$userPHPBB = $resUserPHPBB->fetch_assoc();
	$userPHPBB["username"] = str_replace("&gt;","",$userPHPBB["username"]);
	$userPHPBB["username"] = str_replace("&lt;","",$userPHPBB["username"]);
	$userPHPBB["username"] = str_replace("_","-",$userPHPBB["username"]);
	$userPHPBB["username"] = str_replace(" ","-",$userPHPBB["username"]);
	$userPHPBB["username"] = preg_replace('/[^\p{L}\p{N}\s-]/u','',$userPHPBB["username"]);
	if (strlen($userPHPBB["username"])) {
		echo "{$userFlarum["username"]} > {$userPHPBB["username"]}\n";
		$resUpdate = $connFlarum->query("update users set username = '{$userPHPBB["username"]}' where email = '{$userFlarum["email"]}'");
		if($resUpdate === false) {
			echo "Err: " . $connFlarum->error . "\n";
		}
	}
}

$connFlarum->close();
$connPHPBB->close();

?>
