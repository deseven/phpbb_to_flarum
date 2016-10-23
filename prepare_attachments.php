<?php

// this script creates symlinks with extensions for phpbb attachments, so you can later use them in Flarum

$base = "/var/www/forum.example.com/files/";

$conn = new mysqli("localhost","user","password","phpbb");
$query = "select physical_filename,real_filename from phpbb_attachments";
$res = $conn->query($query);
while ($attachment = $res->fetch_assoc()) {
	$ext = pathinfo($attachment["real_filename"],PATHINFO_EXTENSION);
	echo $attachment["physical_filename"]." > ".$attachment["physical_filename"].".".$ext."\n";
	symlink($base.$attachment["physical_filename"],$base.$attachment["physical_filename"].".".$ext);
}

$conn->close();

?>
