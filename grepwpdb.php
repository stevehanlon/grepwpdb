<?php
/*
The MIT License (MIT)

Copyright (c) 2016, Steve Hanlon. 

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 */

/* 
 * Find strings in wordpress mysql database and optionally replace them
 * If PHP serialized data stored in the database, then unpack find strings
 * and reserialize for saving back to the database. 
 * 
 * Written because many scripts for wordpress migration weren't doing everything 
 * that I needed and I was left with many old or broken URLs still in the database. 
 * 
 */

/* 
 * Usage: 
 *   php grepwpdb.php [-h host] -u username -p password -d database -s searchstr [--sql] [--update] [--replace replacestr] [--help]
 * 
 *   -s: search string to find in database.
 *   --sql: output a sql script to stdout if --replace is given. Default is to output the matching field. (optional)
 *   --update: update the source database if --replace is given.  (optional)
 *   --replace: string to replace the pattern. If set, default is to output sql. (optional)
 *   --help: output this help.
 */


$shortopts = "h:u:d:s:p::";  
$longopts  = array(
    "sql",   	    // output sql, optional, no val
    "update",       // update db, optional, no val
    "replace:",     // replacement string, no val
    "help"	    // help, optional, noval
);
$options = getopt($shortopts, $longopts);

if (array_key_exists("help", $options) || 
    !array_key_exists("u",$options) || 
    !array_key_exists("d",$options) || 
    !array_key_exists("s",$options)) {
  diehelp();
}

/* script uses getopt. any additional parameters are silently ignored for now */

  $hostname = "localhost";
  $username = "";
  $password = null;
  $database = "";
  $olddomain = "";
  $newdomain = null;
  $sql = 0;
  $doupdate = 0;


  if (array_key_exists("h", $options)) { $hostname = $options["h"]; unset($options["h"]); }
  if (array_key_exists("sql", $options)) { $sql = 1; unset($options["sql"]); }
  if (array_key_exists("update", $options)) { $doupdate = 1; unset($options["update"]); }
  if (array_key_exists("replace", $options)) { $newdomain = $options["replace"]; unset($options["replace"]); }
  if (array_key_exists("p", $options)) { 
    if ($options["p"] != "") {
      $password = $options["p"]; 
    } else {
      $password = readline("Enter password: ");
    }
    unset($options["p"]); 
  }

  $username = $options["u"]; unset($options["u"]);
  $database = $options["d"]; unset($options["d"]);
  $olddomain = $options["s"]; unset($options["s"]);

  if ($sql && $newdomain === null) diehelp("Error: replacement string must be set for --sql option\n");
  if ($doupdate && $newdomain === null) diehelp("Error: replacement string must be set for --update option\n");

  if (is_array($hostname) || is_array($newdomain) || is_array($password) || is_array($username) || is_array($database) || is_array($olddomain)) {
    diehelp("Error: cannot understand options. Duplicate flags.\n");
  }

  // connect to database: 

  $conn = new mysqli($hostname, $username, $password, $database);
  if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
  }

  // run through all tables in db
  $tables = array();
 
  $res = $conn->query("SHOW TABLES");
  while($cRow = $res->fetch_array(MYSQLI_BOTH))
  {
    $tables[] = $cRow[0];
  }

  // Loop through each table:
  foreach ($tables as $table) {
    $res = $conn->query("show keys from $table where key_name = 'PRIMARY'");
    if (!$fields = $res->fetch_array(MYSQLI_BOTH)) {
        // echo ("No primary key : $table");
    } else {
	$primary = $fields["Column_name"];

        $res = $conn->query("select * from $table");
	// get column info:
        $info = $res->fetch_fields();

  	// for each row in the table:
        while ($cRow = $res->fetch_array(MYSQLI_ASSOC)) {

	    // for each column in the row, try to match the string
            foreach ($cRow as $field => $val) {
		// replace the string. If replace is done, then store in $update
	        $update = null;
	        if (strpos($val, $olddomain) !== false) {
		    if (($unserialized = @unserialize($val)) === false) {
		        $new = str_replace($olddomain, $newdomain, $val);
		        $update = $new;
		    } else {
		        $obj = unserialize($val);
		        $out = objreplace($obj, $olddomain, $newdomain);
		        $update = serialize($out);
		    }
	        }

		// if there was an update, then decide what to do: 
	        if ($update != null) {
		    if ($sql) {
	                printf("update `%s` set `%s` = '%s' where `%s` = '%s';\n",
			    $table, $field, $conn->real_escape_string($update), 
			    $primary, $conn->real_escape_string($cRow[$primary]));
		    }

		    if ($doupdate) {
	                $sqlstmt = sprintf("update `%s` set `%s` = ? where `%s` = ?",$table, $field, $primary);
		        $stmt = $conn->prepare($sqlstmt);
		        $stmt->bind_param('ss', $update, $cRow[$primary]);
		        $stmt->execute();
		        $stmt->close();
		    } 

		    if (!$sql && !$doupdate) {
			echo $table."[" . $cRow[$primary] . "]\n";
			if ($newdomain === null) {
			    echo $val."\n";
			} else {
	 	            echo "< ".$val."\n";
			    echo "> ".$update."\n";
			}
		    }
	        }
            }
        }
    } 
  }
  

$conn->close();
exit(0)


// Function to iterate through object or array and find strings. 
// If the object is php serialized then eval to turn into an object and 
// then replace and re-serialize correctly

function objreplace($obj, $olddom, $newdom) {
    foreach ($obj as $key => $val) {
	if (is_array($val) || is_object($val) || gettype($val) == 'object') {
	    if (gettype($val) == 'object' && !is_object($val)) {
	        // this is a partial object. 
		// Handle by creating class and then unserializing.
	        $array = new ArrayObject($val);
		$classname = $array["__PHP_Incomplete_Class_Name"];
    		if (!class_exists($classname)) { eval("class $classname { }"); }
	        $val = unserialize(serialize($val));
	        $obj->$key = objreplace($val, $olddom, $newdom);
	    } else if (is_object($obj) || gettype($obj) == 'object') {
	        $obj->$key = objreplace($val, $olddom, $newdom);
	    } else {
	        $obj[$key] = objreplace($val, $olddom, $newdom);
	    }
	} else {
	    if (strpos($val,$olddom) !== false) {
		$new = str_replace($olddom, $newdom, $val);
		if (is_object($obj) || gettype($obj) == 'object') {
		    $obj->$key = $new;
		}
		if (is_array($obj)) {
		    $obj[$key] = $new;
		}
	    }
	}
    }
    return $obj;
}

function diehelp($message = "") {
  global $argv;
  $script = $argv[0];
  if ($message != "") echo $message . "\n";
  $help = <<<EOF
Usage: 
  php $script [-h host] -u username [-p password] -d database -s searchstr [--sql] [--update] [--replace replacestr] [--help]

  -s: search string to find in database.
  --sql: output a sql script to stdout if --replace is given. Default is to output the matching field. (optional)
  --update: update the source database if --replace is given.  (optional)
  --replace: string to replace the pattern. If set, default is to output sql. (optional)
  --help: output this help.


EOF;
  echo $help;
  die(1);
}


?>

