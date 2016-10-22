<?php

$phpbbBase="http://example.com/download/file.php?avatar=";
$outDir="avatars/";

$connFlarum = new mysqli("localhost","user","password","flarum");
$connPHPBB = new mysqli("localhost","user","password","phpbb");
$connFlarum->set_charset("utf8");
$connPHPBB->set_charset("utf8");
$resUsersFlarum = $connFlarum->query("select username,email,avatar_path from users where email not like '%nonexistingmail.ru'");
while ($userFlarum = $resUsersFlarum->fetch_assoc()) {
	if (!strlen($userFlarum["avatar_path"])) {
		$resUserPHPBB = $connPHPBB->query("select user_avatar from phpbb_users where user_email = '{$userFlarum["email"]}' order by user_regdate asc limit 1");
		$userPHPBB = $resUserPHPBB->fetch_assoc();
		if (strlen($userPHPBB["user_avatar"])) {
			$ext = pathinfo($userPHPBB["user_avatar"],PATHINFO_EXTENSION);
			if ((strtolower($ext) == "jpg") || (strtolower($ext) == "jpeg") || (strtolower($ext) == "png")) {
				echo $userFlarum["username"]." > ".$userPHPBB["user_avatar"]."\n";
				if (file_put_contents($outDir.$userPHPBB["user_avatar"],fopen($phpbbBase.$userPHPBB["user_avatar"],'r'))) {
					$connFlarum->query("update users set avatar_path = '{$userPHPBB["user_avatar"]}' where email = '{$userFlarum["email"]}'");
				}
			}
		}
	}
}

$connFlarum->close();
$connPHPBB->close();

?>
