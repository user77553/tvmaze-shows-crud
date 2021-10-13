<?php 
// MAKE SURE TO SET YOUR CREDENTIALS
define("DBURL", "");
define("USER", "root");
define("PASSWORD", "");
define("DB", "tvmazedb");
define("IMGURID", "");
define("TVDBID", "");

function sqltime($timestamp = false) {
    if ($timestamp === false) $timestamp = time();
    return date('Y-m-d H:i:s', $timestamp);
}

function db_string($str) {

	global $link;

   return mysqli_real_escape_string($link, $str);

}

function uploadtoimgur($filename) {

   $client_id = IMGURID;
   $image = file_get_contents($filename);
   $pvars = array('image' => base64_encode($image));
   $timeout = 30;
   $curl = curl_init();

   curl_setopt($curl, CURLOPT_URL, 'https://api.imgur.com/3/image.json');
   curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
   curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Client-ID ' . $client_id));
   curl_setopt($curl, CURLOPT_POST, 1);
   curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
   curl_setopt($curl, CURLOPT_POSTFIELDS, $pvars);
   $out = curl_exec($curl);
   curl_close ($curl);
   $pms = json_decode($out,true);
   $url=$pms['data']['link'];
 
   if( $url != '' ) return $url;
   else return false;
 }

 function remoteFileExists($url) {
   $curl = curl_init($url);
   curl_setopt($curl, CURLOPT_NOBODY, true);
   $result = curl_exec($curl);
   $ret = false;
   if ($result !== false) {
       $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
       if ($statusCode == 200) {
          $ret = true;
       }
   }
   curl_close($curl);
   return $ret;
}

function refreshRating($ShowID) {

   if($ShowID && is_numeric($ShowID)) {

      global $link;

      $RawTVMazeInfo = json_decode(file_get_contents("http://api.tvmaze.com/shows/$ShowID"));

      if($RawTVMazeInfo->rating->average) {
         $rating = db_string($RawTVMazeInfo->rating->average);
         $query = "UPDATE shows SET rating = $rating WHERE id = $ShowID";
         $result = mysqli_query($link, $query);
         if($result) echo "Show $ShowID rating updated<br/ >";
      }
   }
}


function get_premiers() {
        $Premiers = array();
        $maze = get_shows();

        foreach($maze as $Show) {
            $TVMazeID = $Show["ID"];
            $Premiers[] = ['TVMazeID' => $TVMazeID,
                           'Name'     => $Show["name"],
                           'URL'   => $Show['url'],
                           'Banner'   => $Show['image'],
                           'rating'   => $Show['rating'],
                           'premiered'   => $Show['premiered'],
                           'Synopsis' => $Show['summary'],
                           'tvdb' => $Show['tvdb']];
        }
        return $Premiers;
}

$link = mysqli_connect(DBURL, USER, PASSWORD, DB);
if (!$link) {
   echo "Error: Unable to connect to MySQL." . PHP_EOL;
   echo "Debugging error: " . mysqli_connect_errno() . PHP_EOL;
   echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
   exit;
}

$ShowIDs = $_POST['id'] ?? [];
$ShowDelIDs = $_POST['iddel'] ?? [];
$Banners = $_POST['banner'] ?? null;

for( $i = 0; $i < count($ShowIDs); $i++ ) {
   if($Banners[$i]) {
   	$b = uploadtoimgur($Banners[$i]);
      $query = "UPDATE shows SET image='". db_string($b)."' WHERE id='".$ShowIDs[$i]."'";
      $result = mysqli_query($link, $query);
   }   
}

for( $i = 0; $i < count($ShowDelIDs); $i++ ) {
   $query = "DELETE FROM shows WHERE id='".$ShowDelIDs[$i]."'";
   $result = mysqli_query($link, $query);
}

function get_shows() {

   global $link;

   $query = "SELECT updated FROM service";
   $result = mysqli_query($link, $query);

   if ( strtotime($result->fetch_object()->updated) < strtotime('today')) : // once a day


      if (!function_exists('curl_init')) {
         die('Sorry cURL is not installed!');
      }

      $ch = array();
      $mh = curl_multi_init();
      $total = 100;

      $t1 = microtime(true);

      for ( $i=0; $i < 100; $i++ ) {
         $ch[$i] = curl_init();
         curl_setopt($ch[$i], CURLOPT_URL, "http://api.tvmaze.com/shows?page=".$i);
         curl_setopt($ch[$i], CURLOPT_HEADER, 0);
         curl_setopt($ch[$i], CURLOPT_RETURNTRANSFER, true);

         curl_multi_add_handle($mh, $ch[$i]);
      }

      $active = null;
      do {
         $mrc = curl_multi_exec($mh, $active);
         //usleep(100);
      } while ($active);

      $content = array();

      $i = 0;
      foreach ($ch AS $i => $c) {
         $Pages[] = curl_multi_getcontent($c);
         curl_multi_remove_handle($mh, $c);
      }

      curl_multi_close($mh);

      $query = "DELETE FROM shows WHERE premiered < NOW() - INTERVAL 1 MONTH";
      $result = mysqli_query($link, $query);

      $query = "DELETE FROM service";
      mysqli_query($link, $query);

      $sqltime = db_string(sqltime());
      $query = "INSERT INTO service (updated) VALUES ( '$sqltime' )";
      mysqli_query($link, $query);

      // Decode pages
      foreach($Pages as $Page) {
         $Shows = json_decode($Page, TRUE);
  
         foreach($Shows as $Show) {

            if(intval($Show['weight']) < 90) unset($Show);

            $TVMAZE = $Show['id'];

            $query = "SELECT ID FROM shows WHERE ID='".$TVMAZE."'";
            $result = mysqli_query($link, $query);

            if (mysqli_num_rows($result)) continue;

            $Banner = '';
                
            if(is_numeric($TVMAZE) && strtotime($Show["premiered"]) >= strtotime('today- 30 days') && strtotime($Show["premiered"]) <= strtotime('today')) {

               $Summary = preg_replace('#<[^>]+>#', '', $Show['summary']);
               $Summary = str_replace('"', '', $Summary); // Striping " to correct Synopsis
               $Summary = strip_tags($Summary); // Striping tags

               // thetvdb banner
	            $thetvdb = $Show["externals"]["thetvdb"];
                
               if($thetvdb) {
                  $tvdb_banners = simplexml_load_file('https://www.thetvdb.com/api/'.TVDBID.'/series/'.$thetvdb.'/artworks.xml');
               }

               foreach($tvdb_banners as $tvdb_banner) :
                	
                  if(remoteFileExists('https://www.thetvdb.com/banners/'.$tvdb_banner->banner)) {
                     $Banner=uploadtoimgur('https://www.thetvdb.com/banners/'.$tvdb_banner->banner);
                     break;
                  }
               endforeach;

               $Name = db_string($Show['name']);
               $Url = db_string($Show['url']);
               $Summary = db_string($Summary);
               $Banner = db_string($Banner);
               $Rating = db_string($Show['rating']['average']);
               $Weight = db_string($Show['weight']);
               $Premiered = db_string($Show['premiered']);
               $TVDB = db_string($thetvdb);

               unset($thetvdb);
               unset($tvdb_banners);
                                   
               $query = "SELECT ID FROM shows WHERE ID='".$TVMAZE."'";
               $result = mysqli_query($link, $query);
   
               if (mysqli_num_rows($result) == 0) {

               $sqltime = db_string(sqltime());
                
               $query = "INSERT INTO shows (id, name, url, summary, image, rating, weight, premiered, updated, tvdb) VALUES
                        ( '$TVMAZE' , '$Name', '$Url', '$Summary', '$Banner', '$Rating', '$Weight', '$Premiered ', '$sqltime', '$TVDB')";
               mysqli_query($link, $query);

               }
            }
            unset($TVMAZE);
            unset($Show);
         }
         unset($Page);   
      }

   endif; // once a day
   
   $query = "SELECT * FROM shows ORDER BY premiered DESC";
   $result = mysqli_query($link, $query);
        
   $query2 = "SELECT updated FROM service";
   $result2 = mysqli_query($link, $query2);
              
   if ( strtotime($result2->fetch_object()->updated) < strtotime('today') ) : // once a day
      foreach ($result as $show) { refreshRating($show["ID"]); }
   endif;
        
   if (mysqli_num_rows($result)) return $result;
}

function print_premiers() {

        $Premiers = get_premiers();

        $i = 0; ?>

        <div class="head" title="<?php echo date('d.m.y',strtotime('today - 30 days')).' - '.date('d.m.y') ?>">
           <h1>New Shows Premiered in the Past 30 Days <input type="button" value="Home" id="home"></h1>
        </div>

       <form id="form1" action="index.php" method="post">
        <table>
<?php   foreach ($Premiers as $Key=>$Value) {
        	   if($i%4==0) echo '</tr><tr>'; ?>
        	   <td class="main">
        	    <div class="date"><?=date('d.m.y',strtotime($Value['premiered']))?></div>
             <div class='name'><?=$Value['Name']?></div>
             <div class="rating"><?=$Value['rating']?> <input type="checkbox" class="checkbox1" id="iddel<?=$i?>" name="iddel[]" value="<?=$Value['TVMazeID']?>" title="Remove" /></div>
             
            <br/>             
<?php       if($Value['Banner']) { ?>
              <a href="<?=$Value['URL']?>" target="_new">
                <img class="banner" src="<?=$Value['Banner']?>" title="<?=$Value['Synopsis']?>" />
              </a>
<?php       } else { ?>
              <input type="text" id="banner<?=$i?>" name="banner[]" value="" />   
              <input type="hidden" id="id<?=$i?>" name="id[]" value="<?=$Value['TVMazeID']?>" />
<?php       if($Value['tvdb']) { ?>                
              <a href="https://www.thetvdb.com/dereferrer/series/<?=$Value['tvdb']?>" target="_new">tvdb</a>
<?php       } ?>
              <input type="checkbox" id="iddel<?=$i?>" name="iddel[]" value="<?=$Value['TVMazeID']?>" title="Remove" />              
<?php       } ?>
            </td>
<?php        $i++;
        } ?>
        </table>
        <span style="float:right;">Total: <?=$i?> <input type="submit" value="Submit" /></span>
      </form>
      <br>
       
<?php } ?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta http-equiv="X-UA-Compatible" content="IE=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <link rel="shortcut icon" href="../favicon.ico" />
   <title>New Shows Premiered in the Past 30 Days</title>
   <link href="shows.css" rel="stylesheet" type="text/css" />
   <link href="../index.css" rel="stylesheet" type="text/css" />
   <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.0/jquery.min.js"></script>   
   <script src="../index.js" type="text/javascript"></script>
</head>

<body>

<?php
   print_premiers();
   mysqli_close($link);
?>

</body>
</html>
