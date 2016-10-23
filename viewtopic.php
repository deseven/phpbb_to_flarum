<?php

// this script catches old links to phpbb and redirects users to Flarum discussions

$base = "http://forum.example.com";
$loc = "$base";

if (isset($_GET["t"])) {
	$loc = "$base/d/{$_GET["t"]}";
} elseif (isset($_GET["p"])) {
	$conn = new mysqli("localhost","user","password","phpbb");
	$conn->set_charset("utf8");
	$post_id = preg_replace('/\D/','',$_GET["p"]);
	$res = $conn->query("select topic_id from phpbb_posts where post_id = '$post_id'");
	if ($res->num_rows) {
		$post = $res->fetch_assoc();
		if (isset($post["topic_id"])) {
			$loc = "$base/d/{$post["topic_id"]}";
		}
	}
	$conn->close();
}

header("Location: $loc");

?>
