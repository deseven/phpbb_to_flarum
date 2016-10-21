<?php
// Original script by robrotheram from discuss.flarum.org
// Modified by VIRUXE
// And Reflic

set_time_limit(0);
ini_set('memory_limit', -1);

ini_set("log_errors", 1);
ini_set("error_log", "php-error.log");

$servername = "localhost";
$username = "user";
$password = "password";
$exportDBName = "phpbb";
$importDBName = "flarum";
$attachmentsBase = "http://example.com/files/"


// Establish a connection to the server where the PHPBB database exists
$exportDbConnection = new mysqli($servername, $username, $password, $exportDBName);
$importDbConnection = new mysqli($servername, $username, $password, $importDBName);

// Check connection
if ($exportDbConnection->connect_error)
	die("Export - Connection failed: " . $exportDbConnection->connect_error);
else
{
	echo "Export - Connected successfully<br>";

	if(!$exportDbConnection->set_charset("utf8"))
	{
	    printf("Error loading character set utf8: %s\n", $exportDbConnection->error);
	    exit();
	}
	else
	    printf("Current character set: %s\n", $exportDbConnection->character_set_name());
}

if ($importDbConnection->connect_error)
	die("Import - Connection failed: " . $importDbConnection->connect_error);
else
{
	echo "Import - Connected successfully<br>";

	if(!$importDbConnection->set_charset("utf8"))
	{
	    printf("Error loading character set utf8: %s\n", $importDbConnection->error);
	    exit();
	}
	else
	    printf("Current character set: %s\n", $importDbConnection->character_set_name());
}

//Convert Users

echo "<hr>Step 1 - Users<hr>";
$result = $exportDbConnection->query("SELECT user_id, from_unixtime(user_regdate) as user_regdate, username_clean, user_email FROM phpbb_users where user_posts > 0 order by user_regdate desc"); // import only active users
$totalUsers = $result->num_rows;
if ($totalUsers)
{
	$i = 0;
	$usersIgnored = 0;
	while($row = $result->fetch_assoc())
	{
		$i++;

		if($row["user_email"] != NULL)
		{
			$username = $row["username_clean"];
			$usernameHasSpace = strpos($username, " ");

			if($usernameHasSpace > 0)
			{
				$formatedUsername = str_replace(" ", NULL, $username);
			}
			else{
				$formatedUsername = $username;
			}
			$id = $row['user_id'];
			$email = $row['user_email'];
			$password = sha1(md5(time()));
			$altmail = md5($password.rand(0,999999999))."@example.com"; // i'm really sorry for that :)
			$jointime = $row['user_regdate'];
			$query = "INSERT INTO users (id, username, email, password, join_time, is_activated) VALUES ( '$id', '$formatedUsername', '$email', '$password', '$jointime', 1)";
			$res = $importDbConnection->query($query);
			if($res === false) {
			  // turned out that sometimes the same email can be used in many accounts (if phpbb policy allows it)
			  // flarum doesn't allow that, so i ended up replacing duplicate mail with wrong one
			  // todo: it's better to use plus-notaion, since many mail providers support it
			  // for example if user have mail test@example.com, we can replace it with test+anotheraccountname@example.com
			  $query = "INSERT INTO users (id, username, email, password, join_time, is_activated) VALUES ( '$id', '$formatedUsername', '$altmail', '$password', '$jointime', 1)";
			  $res = $importDbConnection->query($query);
			  if ($res === false) {
			    echo "Wrong SQL: " . $query . " Error: " . $importDbConnection->error . " <br/>";
			  }
			}
		}
		else {
			$usersIgnored++;
		}
	}
	echo $i-$usersIgnored . ' out of '. $totalUsers .' Total Users converted';
}
else
	echo "Something went wrong.";

//Convert Categories to Tags

echo "<hr>Step 2 - Categories<hr>";
$result = $exportDbConnection->query("SELECT forum_id, forum_name, forum_desc  FROM phpbb_forums");
$totalCategories = $result->num_rows;
if ($totalCategories)
{
	$i = 1;
	while($row = $result->fetch_assoc())
	{
		$id = $row["forum_id"];
		$name = mysql_escape_mimic($row["forum_name"]);
		$description = mysql_escape_mimic(strip_tags(stripBBCode($row["forum_desc"])));
		$color = rand_color();
		$position = $i;
		$slug = mysql_escape_mimic(slugify($row["forum_name"]));

		$query = "INSERT INTO tags (id, name, description, slug, color, position) VALUES ( '$id', '$name', '$description', '$slug', '$color', '$position')";
		$res = $importDbConnection->query($query);
		if($res === false) {
			echo "Wrong SQL Assumption id Confict now trying a update  <br/>";
			$queryupdate = "UPDATE tags SET name = '$name', description = '$description', slug = '$slug' WHERE id = '$id' ;";
			$res = $importDbConnection->query($queryupdate);
			if($res === false) { echo "Wrong SQL: " . $query . " Error: " . $importDbConnection->error . " <br/>"; }
		}
		$i++;
	}
	echo $totalCategories . ' Categories converted.';
}
else
	echo "Something went wrong.";


echo "<hr>Step 3 - Topics<hr>";
$topicsQuery = $exportDbConnection->query("SELECT topic_id, topic_poster, forum_id, topic_title, topic_time FROM phpbb_topics ORDER BY topic_id DESC;");
$topicCount = $topicsQuery->num_rows;

if($topicCount)
{
	$curTopicCount = 0;
	$insertString = "INSERT INTO posts (id, user_id, discussion_id, time, type, content) VALUES \n";
	//	Loop trough all PHPBB topics
	$topictotal = $topicsQuery->num_rows;
	$i = 1;
	while($topic = $topicsQuery->fetch_assoc())
	{
		//	Convert posts per topic
		$participantsArr = [];
		$lastPosterID = 0;

		$sqlQuery = sprintf("SELECT * FROM phpbb_posts WHERE topic_id = %d;", $topic["topic_id"]);
		$postsQuery = $exportDbConnection->query($sqlQuery);
		$postCount = $postsQuery->num_rows;

		if($postCount)
		{
			$curPost = 0;

			//fwrite($sqlScript_posts, $insertString);
			while($post = $postsQuery->fetch_assoc())
			{
				$curPost++;

				$posterID = 0;
				$date = new DateTime();
				$date->setTimestamp($post["post_time"]);
				$postDate =  $date->format('Y-m-d H:i:s');
				$postText = formatText($exportDbConnection, $post['post_text'], $post["post_id"], $attachmentsBase);
				
				

				//if($post['post_id'] == 913){echo $postText;}

				if(empty($post['post_username']))// If the post_username field has text it means it's a "ghost" post. Therefore we should set the poster id to 0 so Flarum knows it's an invalid user
				{
					$posterID = $post['poster_id'];

					// Add to the array only if unique
					if(!in_array($posterID, $participantsArr))
						$participantsArr[] = $posterID;
				}

				if($curPost == $postCount)// Check if it's the last post in the discussion and save the poster id
					$lastPosterID = $posterID;

				// Write post values to SQL Script
				//fwrite($sqlScript_posts, sprintf("\t(%d, %d, %d, '%s', 'comment', '%s')%s\n", $post['post_id'], $posterID, $topic['topic_id'], $postDate, $postText, $curPost != $postCount ? "," : ";"));

				// Execute the insert query in the desired database.
				$formattedValuesStr = sprintf("(%d, %d, %d, '%s', 'comment', '%s');", $post['post_id'], $posterID, $topic['topic_id'], $postDate, $postText);
				$importDbConnection->query($insertString . $formattedValuesStr);
			}
		}
		//else
		//	echo "<br>Topic ". $topic['topic_id'] ." has zero posts.<br>";

		//	Convert topic to Flarum format
		//
		//	This needs to be done at the end because we need to get the post count first
		//
		$date = new DateTime();
		$date->setTimestamp($topic["topic_time"]);
		$discussionDate = $date->format('Y-m-d H:i:s');
		$topicTitle = $exportDbConnection->real_escape_string($topic["topic_title"]);

		// Link Discussion/Topic to a Tag/Category
		$topicid = $topic["topic_id"];
		$forumid = $topic["forum_id"];

		$query = "INSERT INTO discussions_tags (discussion_id, tag_id) VALUES( '$topicid', '$forumid')";
		$res = $importDbConnection->query($query);
		if($res === false) {
			echo "Wrong SQL: " . $query . " Error: " . $importDbConnection->error . " <br/>";
		}


		// Check for parent forums
		$parentForum = $exportDbConnection->query("SELECT parent_id FROM phpbb_forums WHERE forum_id = " . $topic["forum_id"]);
		$result = $parentForum->fetch_assoc();
		if($result['parent_id'] > 0){
			$topicid = $topic["topic_id"];
			$parentid = $result['parent_id'];
			$query = "INSERT INTO discussions_tags (discussion_id, tag_id) VALUES( '$topicid', '$parentid')";
			$res = $importDbConnection->query($query);
			if($res === false) {
				echo "Wrong SQL: " . $query . " Error: " . $importDbConnection->error . " <br/>";
			}
	 	}
		if($lastPosterID == 0)// Just to make sure it displays an actual username if the topic doesn't have posts? Not sure about this.
			$lastPosterID = $topic["topic_poster"];


		$slug = mysql_escape_mimic(slugify($topicTitle));
		$count =  count($participantsArr);
		$poster = $topic["topic_poster"];
		$query = "INSERT INTO discussions (id, title, slug, start_time, comments_count, participants_count, start_post_id, last_post_id, start_user_id, last_user_id, last_time) VALUES( '$topicid', '$topicTitle', '$slug', '$discussionDate', '$postCount', '$count', 1, 1, '$poster', '$lastPosterID', '$discussionDate')";
		$res = $importDbConnection->query($query);
		if($res === false) {
			echo "Wrong SQL: " . $query . " Error: " . $importDbConnection->error . " <br/>";
		}

		$i++;
	}
}
// Convert user posted topics to user discussions?
echo "<hr> User Discussions<hr/>";
$result = $exportDbConnection->query("SELECT user_id, topic_id FROM phpbb_topics_posted");
if ($result->num_rows > 0)
{
	$total = $result->num_rows;
	$i = 1;
	while($row = $result->fetch_assoc()) {
		$comma =  $i == $total ? ";" : ",";
		$userID = $row["user_id"];
		$topicID = $row["topic_id"];
		$query = "INSERT INTO users_discussions (user_id, discussion_id) VALUES ( '$userID', '$topicID')";
		$res = $importDbConnection->query($query);
		if($res === false) {
			echo "Wrong SQL: " . $query . " Error: " . $importDbConnection->error . " <br/>";
		}
		$i++;
	}
	echo "Success";
}
else
	echo "Table is empty";


// Convert user posted topics to user discussions?
echo "<hr>Step Update User table<hr/>";
$result = $importDbConnection->query("SELECT id FROM users");
if ($result->num_rows > 0)
{
	$total = $result->num_rows;
	$i = 1;
	while($row = $result->fetch_assoc()) {
		$comma =  $i == $total ? ";" : ",";
		$userID = $row["id"];
		$res = $importDbConnection->query("select * from users_discussions where user_id = '$userID' ");
		$numTopics =  $res->num_rows;

		$res1 = $importDbConnection->query("select * from posts where user_id = '$userID' ");
		$numPosts =  $res1->num_rows;

		$query = "UPDATE users SET discussions_count = '$numTopics',  comments_count = '$numPosts' WHERE id = '$userID' ";
		$res = $importDbConnection->query($query);
		if($res === false) {
			echo "Wrong SQL: " . $query . " Error: " . $importDbConnection->error . " <br/>";
		}
	}
	echo "Success";
}
else
	echo "Table is empty";

// updating discussions times
echo "<hr>Step Update discussions times<hr/>";
$res = $importDbConnection->query("select id from discussions");
while ($discussion = $res->fetch_assoc()) {
	$res2 = $importDbConnection->query("select time from posts where discussion_id = '".$discussion["id"]."' order by time desc limit 1");
	if ($res2->num_rows) {
		$post = $res2->fetch_assoc();
		$importDbConnection->query("update discussions set last_time = '".$post["time"]."' where id = '".$discussion["id"]."'");
	}
}
echo "Success";

// Close connection to the database
$exportDbConnection->close();
$importDbConnection->close();

// Functions
function print_r2($val)
{
	echo '<pre>';
	print_r($val);
	echo  '</pre>';
}

function transliterate($st) {    $st = strtr($st, array( 'ж'=>'zh','ё'=>'yo','ч'=>'ch','ш'=>'sh','щ'=>'shch','ю'=>'yu','я'=>'ya',      'Ж'=>'Zh','Ё'=>'Yo','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Shch','Ю'=>'Yu','Я'=>'Ya','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue',      'æ'=>'ae','Æ'=>'Ae','Á'=>'A','À'=>'A','Â'=>'A','Ą'=>'A','Å'=>'A','Ç'=>'C','Ć'=>'C','Č'=>'C','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Ę'=>'E','Ė'=>'E','Ě'=>'E',      'Ğ'=>'G','Î'=>'I','Ï'=>'I','İ'=>'I','Í'=>'I','I'=>'I','Į'=>'I','Ł'=>'L','Ĺ'=>'L','Ñ'=>'N','Ń'=>'N','Ó'=>'O','Ô'=>'O','Ø'=>'O','Ŕ'=>'R','Ř'=>'R','Ś'=>'S','Š'=>'S','Ş'=>'S',      'Ú'=>'U','Ù'=>'U','Û'=>'U','Ų'=>'U','Ū'=>'U','Ů'=>'U','Ý'=>'Y','Ź'=>'Z','Ż'=>'Z','Ž'=>'Z','á'=>'a','à'=>'a','â'=>'a','ą'=>'a','å'=>'a','ç'=>'c','ć'=>'c','č'=>'c','é'=>'e',      'è'=>'e','ê'=>'e','ë'=>'e','ę'=>'e','ė'=>'e','ě'=>'e','ğ'=>'g','î'=>'i','ï'=>'i','i'=>'i','í'=>'i','ı'=>'i','į'=>'i','ł'=>'l','ĺ'=>'l','ñ'=>'n','ń'=>'n','ó'=>'o','ô'=>'o',      'ø'=>'o','ŕ'=>'r','ř'=>'r','ś'=>'s','š'=>'s','ş'=>'s','ú'=>'u','ù'=>'u','û'=>'u','ų'=>'u','ū'=>'u','ů'=>'u','ý'=>'y','ź'=>'z','ż'=>'z','ž'=>'z','А'=>'A','Б'=>'B','В'=>'V',      'Г'=>'G','Ґ'=>'G','Д'=>'D','Е'=>'E','Є'=>'E','З'=>'Z',      'И'=>'I','Й'=>'Y','І'=>'I','Ї'=>'I','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'C','Ы'=>'Y','Э'=>'E',      'а'=>'a','б'=>'b','в'=>'v','г'=>'g','ґ'=>'g','д'=>'d','е'=>'e','є'=>'e','з'=>'z','и'=>'i','й'=>'y','і'=>'i','ї'=>'i','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p',      'р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ы'=>'y','э'=>'e','Ъ'=>'','Ь'=>'','ъ'=>'','ь'=>'',      ));   return $st; }

function slugify($text)
{
	$text = preg_replace('~[^\\pL\d]+~u', '-', $text);
	$text = trim($text, '-');
	// iconv is bugged on many installations
	//$text = iconv('utf-8', 'ISO-8859-1//TRANSLIT', $text);
	$text = strtolower($text);
	$text = transliterate($text);
	$text = preg_replace('~[^-\w]+~', '', $text);

	if (empty($text))
		return 'n-a';

	return $text;
}

function rand_color()
{
	return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
}

function mysql_escape_mimic($inp)
{
	if(is_array($inp))
			return array_map(__METHOD__, $inp);

	if(!empty($inp) && is_string($inp)) {
			return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);
	}
	return $inp;
}

// Formats PHPBBs text to Flarums text format'"
function formatText($connection, $text, $post_id, $attachmentsBase)
{

	// checking for post attachments
	$attachmentsQuery = $connection->query("select physical_filename,real_filename from phpbb_attachments where post_msg_id = '".$post_id."'");
	$attachmentsCount = $attachmentsQuery->num_rows;
	if($attachmentsCount) {
		while($attachment = $attachmentsQuery->fetch_assoc()) {
			$ext = pathinfo($attachment["real_filename"], PATHINFO_EXTENSION);
			if ((strtolower($ext) == "jpg") || (strtolower($ext) == "png") || (strtolower($ext) == "jpeg")) {
				$text = $text."\n[img]$attachmentsBase".$attachment["physical_filename"].".".$ext."[/img]";
			} else {
				$text = $text."\n[url=$attachmentsBase".$attachment["physical_filename"].".".$ext.']'.$attachment["real_filename"]."[/url]";
			}
		}
	}
	$attachmentsQuery->free();
	$text = preg_replace('#\:\w+#', '', $text);
	$text = convertBBCodeToHTML($text);
	$text = str_replace("&quot;","\"",$text);
	$text = preg_replace('|[[\/\!]*?[^\[\]]*?]|si', '', $text);
	$text = trimSmilies($text);

	// Wrap text lines with paragraph tags
	$explodedText = explode("\n", $text);
	foreach ($explodedText as $key => $value)
	{
		if(strlen($value) > 1)// Only wrap in a paragraph tag if the line has actual text
			$explodedText[$key] = '<p>' . $value . '</p>';
	}
	$text = implode("\n", $explodedText);

	$wrapTag = strpos($text, '&gt;') > 0 ? "r" : "t";// Posts with quotes need to be 'richtext'
	$text = sprintf('<%s>%s</%s>', $wrapTag, $text, $wrapTag);
	$text = preg_replace('/<!--(.*)-->/Uis','',$text);

	return $connection->real_escape_string($text);
	//return $text;
}

// Used to convert Categories to Tags
function stripBBCode($text_to_search) {
    $pattern = '|[[\/\!]*?[^\[\]]*?]|si';
    $replace = '';
    return preg_replace($pattern, $replace, $text_to_search);
}

function convertBBCodeToHTML($bbcode)
{
	$bbcode = preg_replace('#\[b](.+)\[\/b]#', "<b>$1</b>", $bbcode);
	$bbcode = preg_replace('#\[i](.+)\[\/i]#', "<i>$1</i>", $bbcode);
	$bbcode = preg_replace('#\[u](.+)\[\/u]#', "<u>$1</u>", $bbcode);
	$bbcode = preg_replace('#\[img](.+?)\[\/img]#is', "<img src='$1'\>", $bbcode);
	$bbcode = preg_replace('#\[quote=(.+?)](.+?)\[\/quote]#is', "<QUOTE><i>&gt;</i>$2</QUOTE>", $bbcode);
	$bbcode = preg_replace('#\[code:\w+](.+?)\[\/code:\w+]#is', "<CODE class='hljs'>$1<CODE>", $bbcode);
	$bbcode = preg_replace('#\[pre](.+?)\[\/pre]#is', "<code>$1<code>", $bbcode);
	$bbcode = preg_replace('#\[u](.+)\[\/u]#', "<u>$1</u>", $bbcode);
	$bbcode = preg_replace('#\[\*](.+?)\[\/\*]#is', "<li>$1</li>", $bbcode);
	$bbcode = preg_replace('#\[color=\#\w+](.+?)\[\/color]#is', "$1", $bbcode);
	$bbcode = preg_replace('#\[url=(.+?)](.+?)\[\/url]#is', "<a href='$1'>$2</a>", $bbcode);
	$bbcode = preg_replace('#\[url](.+?)\[\/url]#is', "<a href='$1'>$1</a>", $bbcode);
	$bbcode = preg_replace('#\[list](.+?)\[\/list]#is', "<ul>$1</ul>", $bbcode);
	$bbcode = preg_replace('#\[size=200](.+?)\[\/size]#is', "<h1>$1</h1>", $bbcode);
	$bbcode = preg_replace('#\[size=170](.+?)\[\/size]#is', "<h2>$1</h2>", $bbcode);
	$bbcode = preg_replace('#\[size=150](.+?)\[\/size]#is', "<h3>$1</h3>", $bbcode);
	$bbcode = preg_replace('#\[size=120](.+?)\[\/size]#is', "<h4>$1</h4>", $bbcode);
	$bbcode = preg_replace('#\[size=85](.+?)\[\/size]#is', "<h5>$1</h5>", $bbcode);

	return $bbcode;
}

function trimSmilies($postText)
{
	$startStr = "<!--";
	$endStr = 'alt="';

	$startStr1 = '" title';
	$endStr1 = " -->";

	$emoticonsCount = substr_count($postText, '<img src="{SMILIES_PATH}');

	for ($i=0; $i < $emoticonsCount; $i++)
	{
		$startPos = strpos($postText, $startStr);
		$endPos = strpos($postText, $endStr);

		$postText = substr_replace($postText, NULL, $startPos, $endPos-$startPos+strlen($endStr));

		$startPos1 = strpos($postText, $startStr1);
		$endPos1 = strpos($postText, $endStr1);

		$postText = substr_replace($postText, NULL, $startPos1, $endPos1-$startPos1+strlen($endStr1));
	}

	return $postText;
}
?>
