<?php

ini_set("max_execution_time", "0");
ini_set("memory_limit", "-1");


$GLOBALS['account_user']='googleuser@gmail.com';
$GLOBALS['account_password']='qwerty';

$GLOBALS['is_atom']=true;
$GLOBALS['try_consolidate']=true;
$GLOBALS['fetch_count']=1000;
$GLOBALS['fetch_special_feeds']=true;
$GLOBALS['fetch_regular_feeds']=true;

$GLOBALS['atom_ext']="atom.xml.txt";
$GLOBALS['json_ext']="json.txt";
$GLOBALS['save_dir']="./feeds/";
$GLOBALS['log_file']=$GLOBALS['save_dir']."log.txt";
$GLOBALS['use_json_decode']=false;//function_exists('json_decode');

/* !!!!!!!!!! */
$GLOBALS['need_readinglist']=false;
/* !!!!!!!!!!
 important!
 this will fetch a very full feed list, mixed from all subscribtions and ordered by post date.
 in most cases this data is unusefull and this option will double the script worktime and the hdd space requirement.
 so probably you don't need set this to true.
!!!!!!!!!! */









/***************** lets start the script *************************/
if (@file_exists('./auth/_myauth.inc')) include('./auth/_myauth.inc');
$GLOBALS['feed_str']="";
func_clear_log($GLOBALS['log_file'], true);
print_str("\n");
                    
$r=new GRGrabber($GLOBALS['account_user'], $GLOBALS['account_password']);


if (!@file_exists($GLOBALS['save_dir'])) @mkdir($GLOBALS['save_dir']);

print_str("fetching OPML\n");
$opml=$r->subscriptionsOPML();
file_put_contents($GLOBALS['save_dir'].'feed_list.opml.xml', $opml);


print_str("fetching feedlist\n");
$feeds=$r->listAll($GLOBALS['is_atom']);
if ($GLOBALS['is_atom']) {
  file_put_contents($GLOBALS['save_dir'].'feed_list.'.$GLOBALS['atom_ext'], $feeds);
} else {
  file_put_contents($GLOBALS['save_dir'].'feed_list.'.$GLOBALS['json_ext'], $feeds);
}
print_str("\n");

if ($GLOBALS['is_atom']) $feeds=$r->listAll();
$feeds=json_decode_feedlist($feeds);


$tc1=0;
if ($GLOBALS['fetch_special_feeds']) {
    print_str("Starting special feeds processing\n\n", false, true);
    $chlst=array('starred', 'notes', 'shared', 'shared-followers');
    //$chlst=array('starred');
    if ($GLOBALS['need_readinglist']) array_push($chlst, 'reading-list');
    //$chlst=array();
    $l=0;
    $l=count($chlst);
    for ($i=0;$i<$l;$i++) {
      print_str("   feed [".($i+1)." of ".$l."] \n   [".$chlst[$i]."]\n", false, true);
      $subdir=$GLOBALS['save_dir'].$chlst[$i]."/";
      $tc1+=grab_feed($r, $chlst[$i], "   ", $subdir);
      print_str("\n\n", false, true);
    }
    print_str("Fetched [".$tc1."] posts from special feeds.\n\n", false, true);
}

$tc2=0;
if ($GLOBALS['fetch_regular_feeds']) { 
    print_str("Starting regular feeds processing\n\n", false, true);
    $l=0;
    $l=count($feeds);
    for ($i=0;$i<$l;$i++) {
      print_str("   feed [".($i+1)." of ".$l."] \n   [".preg_replace('/^feed\//', '', $feeds[$i]['id'])."]\n", false, true);
      $subdir=$GLOBALS['save_dir'].sprintf("%04ld",$i+1).'_'.$feeds[$i]['sortid']."/";
      $feeds[$i]['post_count']=grab_feed($r, $feeds[$i], "   ", $subdir);
      $tc2+=$feeds[$i]['post_count'];
      print_str("\n\n", false, true);
    }
    print_str("Fetched [".$tc2."] posts from regular feeds.\n\n", false, true);
}

file_put_contents($GLOBALS['save_dir'].'feeds_dump.txt', var_export($feeds, true));



print_str("All Ok. Total fetched [".($tc1+$tc2)."] posts.\n\n", false, true);










function grab_feed($r, $feed, $prefix="", $subdir="./") {
  $feed_name=(is_array($feed))?$feed['sortid']:$feed;
  if (!@file_exists($subdir)) @mkdir($subdir);
  $continuation=true;
  $head=false;
  $first=true;
  $header='';
  $footer='';
  $items=array();
  $tc=0;
  $part_no=0;
  $ext='';
  print_str($prefix."fetched [0]...", true);
  while ($continuation) {
     if ($feed_name=='starred') {
        $content=$r->getStarred($GLOBALS['is_atom'], $GLOBALS['fetch_count'], $continuation);
     } else if ($feed_name=='notes') {
        $content=$r->getNotes($GLOBALS['is_atom'], $GLOBALS['fetch_count'], $continuation);
     } else if ($feed_name=='shared') {
        $content=$r->getShared($GLOBALS['is_atom'], $GLOBALS['fetch_count'], $continuation);
     } else if ($feed_name=='shared-followers') {
        $content=$r->getSharedFollowers($GLOBALS['is_atom'], $GLOBALS['fetch_count'], $continuation);
     } else if ($feed_name=='reading-list') {
        $content=$r->getReadingList($GLOBALS['is_atom'], $GLOBALS['fetch_count'], $continuation);
     } else {
        $content=$r->getFeed($feed['id'], $GLOBALS['is_atom'], $GLOBALS['fetch_count'], $continuation);
     }

     if ($GLOBALS['is_atom']) {
       $ext=$GLOBALS['atom_ext'];
       $tc+=(int)preg_match_all('/\<entry\s/', $content, $m, PREG_SET_ORDER);
       $continuation=false;
       if (preg_match('/\<gr\:continuation\>([^\<\>]+)\<\/gr\:continuation\>/', $content, $m)) {
          $continuation=$m[1];
       }
       if ($GLOBALS['try_consolidate']) {
          if (preg_match('/^([\w\W]*?)(\<entry\s[\w\W]*\<\/entry\>)([\w\W]*?)$/is', $content, $m)) {
            $header=$m[1];
            $central=$m[2];
            $footer=$m[3];
            $fname=$subdir.$feed_name.'.'.$ext;
            if ($first) {
              file_put_contents($fname, $header);
              $first=false;
            }
            func_print_log($fname, $central);
            if (!$continuation) {
              func_print_log($fname, $footer);
            }
          }
       }
     } else {
       $ext=$GLOBALS['json_ext'];
       $continuation=false;
       $cdec=json_decode_feedcontent($content);
       if (array_key_exists('continuation', $cdec)) $continuation=$cdec['continuation'];
       $tc+=(int)count($cdec['items']);
       unset($cdec);

       if ($GLOBALS['try_consolidate']) {
          if (preg_match('/^([\w\W]*?\,\"items\"\:\[)([\w\W]*)(\]\})$/is', $content, $m)) {
            $header=$m[1];
            $central=$m[2];
            $footer=$m[3];
            $fname=$subdir.$feed_name.'.'.$ext;
            if ($first) {
              file_put_contents($fname, $header);
              $first=false;
            } else {
              func_print_log($fname, ',');
            }
            func_print_log($fname, $central);
            if (!$continuation) {
              func_print_log($fname, $footer);
            }
          }
       }
     }
     if (!$GLOBALS['try_consolidate']) file_put_contents($subdir.$feed_name.'_'.sprintf("%06ld",$part_no++).'.'.$ext, $content);
     print_str($prefix."fetched [".$tc."]...", true);
  }
  print_str($prefix."fetched [".$tc."] OK", false, true);
  return $tc;
}

function json_decode_feedlist($feedlist) {
  if ($GLOBALS['use_json_decode']) {
    $feeds=json_decode($feedlist, true);
    if (is_array($feeds) && array_key_exists('subscriptions', $feeds)) return $feeds['subscriptions'];
    return array();
  } else {
    $res=array();
    $pat_feed_id='/\"id\"\:\"(feed\/[^\"]*?)\"/';
    $pat_feed_sortid='/\"sortid\"\:\"([a-fA-F\d]*?)\"/';
    $feed_id_cnt=(int)preg_match_all($pat_feed_id, $feedlist, $m_id, PREG_SET_ORDER);
    $feed_sortid_cnt=(int)preg_match_all($pat_feed_sortid, $feedlist, $m_sortid, PREG_SET_ORDER);
//    file_put_contents($GLOBALS['save_dir'].'decfeeds.txt', var_export(array($feed_id_cnt, $feed_sortid_cnt, $m_id, $m_sortid), true));exit(0);
    if ($feed_id_cnt>0 && $feed_id_cnt==$feed_sortid_cnt) {
      for ($i=0;$i<$feed_id_cnt;$i++) {
         array_push($res, array('id'=>$m_id[$i][1], 'sortid'=>$m_sortid[$i][1]));
      }
    }
    return $res;
  }
}

function json_decode_feedcontent($feedcontent) {
  if ($GLOBALS['use_json_decode']) {
    return json_decode($feedcontent, true);
  } else {
    $res=array();
    $pat_continuation='/\"continuation\"\:\"([^\"]*?)\"/';
    $pat_item='/\"crawlTimeMsec\":\"/';
    if (preg_match($pat_continuation, $feedcontent, $m_cont)) {
        $res['continuation']=$m_cont[1];
//        file_put_contents($GLOBALS['save_dir'].'decfeed.txt', var_export($res, true));exit(0);
    }
    $res['items']=array();
    $items_cnt=(int)preg_match_all($pat_item, $feedcontent, $res['items'], PREG_SET_ORDER);
    return $res;
  }
}

function print_log_str($fname, $str) {
  if (!$fname || $fname=='') return false;
  return func_print_log($fname, $str);
  return func_print_log($fname, $str."\r\n");
}

  function func_print_log($fname, $str) {
    if (!$fname || $fname=='') return false;
    $fp = fopen ($fname, "ab");
    fputs ($fp, $str);
    fclose ($fp);
  }

  function func_clear_log($fname, $del=false) {
    if (!$fname || $fname=='') return false;
    if ($del) {
       if (file_exists($fname)) return unlink($fname);
       return false;
    }
    $fp = fopen ($fname, "w");
//    $fp = fopen ($fname, "ab");
    ftruncate($fp, 0);
    fclose ($fp);
  }


function print_str($str, $smart_screen=false, $in_log=false) {
   if (strlen($GLOBALS['feed_str'])>0) {
      print(str_repeat("\x08", strlen($GLOBALS['feed_str'])));
      $GLOBALS['feed_str']="";
   }
   if ($smart_screen) {
     $GLOBALS['feed_str']=$str;
     print($GLOBALS['feed_str']);
   } else {
     print($str);
   }
   if ($in_log) {
     print_log_str($GLOBALS['log_file'], $str);
   }
}        






class GRGrabber {
  private $_username;
  private $_password;
  private $_sid;
  private $_lsid;
  private $_auth;
  private $_token;
  
  public $loaded;

  public function __construct($username, $password) {
    if($this->_connect($username, $password)) {
      $this->loaded = true;
    } else {
      $this->_username = null;
      $this->_password = null;
      $this->loaded = false;
    }
  }

  private function _connect($user, $pass) {
    $this->_username = $user;
    $this->_password = $pass;
    
    $this->_getToken();
    return $this->_token != null;
  }
    
  private function _getToken() {
    $this->_getSID();
    $this->_cookie = "SID=" . $this->_sid . "; domain=.google.com; path=/";

    $url = "http://www.google.com/reader/api/0/token";

    $ch = curl_init();
//    curl_setopt($ch, CURLOPT_COOKIE, $this->_cookie);    // This was the old authentication method
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/x-www-form-urlencoded', 'Authorization: GoogleLogin auth=' . $this->_auth));    // This, apparently, is the new one.
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $this->_token = curl_exec($ch);

    curl_close($ch);
  }

  private function _getSID() {
    $requestUrl = "https://www.google.com/accounts/ClientLogin?service=reader&Email=" . urlencode($this->_username) . '&Passwd=' . urlencode($this->_password);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $requestUrl);
    curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $data = curl_exec($ch);
    curl_close($ch);

//    file_put_contents('./curldata.txt', $data);


    $sidIndex = strpos($data, "SID=")+4;
    $lsidIndex = strpos($data, "LSID=")-5;
    $authIndex = strpos($data, "Auth=")+5;

    $this->_sid = substr($data, $sidIndex, $lsidIndex);
    $this->_auth = substr($data, $authIndex, strlen($data));
  }
  
  private function _httpGet($requestUrl, $getArgs) {
    $url = sprintf('%1$s?%2$s', $requestUrl, $getArgs);
//    print($url."\n");
    $https = strpos($requestUrl, "https://");
        
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if($https === true) curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
//    curl_setopt($ch, CURLOPT_COOKIE, $this->_cookie);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/x-www-form-urlencoded', 'Authorization: GoogleLogin auth=' . $this->_auth));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    try {
      $data = curl_exec($ch);
      curl_close($ch);
    } catch(Exception $err) {
      print("\nException: ".$err->getMessage()."\n");
      $data = null;
    }
    return $data;       
  }
  
  /* Public Methods */


  public function getFeed($feed, $atom=false, $count=20, $continuation=false) {
    $feed=preg_replace('/^feed\//', '', $feed);
    $gUrl = "http://www.google.com/reader/api/0/stream/contents/feed/".urlencode($feed);
    $args = "r=n&n=".$count."&".sprintf('ck=%1$s', time())."&client=scroll";
    if (is_string($continuation) && strlen($continuation)>0) $args.="&c=".$continuation;
    if ($atom) {
       $gUrl = "http://www.google.com/reader/atom/feed/".urlencode($feed);
    }
    return $this->_httpGet($gUrl, $args);
  }

  // reading list
  public function getReadingList($atom=false, $count=20, $continuation=false) {
    $gUrl = "http://www.google.com/reader/api/0/stream/contents/user/-/state/com.google/reading-list";
    if ($atom) $gUrl = "http://www.google.com/reader/atom/user/-/state/com.google/reading-list";
    $args = "r=n&n=".$count."&".sprintf('ck=%1$s', time())."&client=scroll";
    if (is_string($continuation) && strlen($continuation)>0) $args.="&c=".$continuation;
    return $this->_httpGet($gUrl, $args);
  }

  // List all subscriptions
  public function listAll($atom=false) {
    $gUrl = "http://www.google.com/reader/api/0/subscription/list";
    $args = sprintf('output=json&ck=%1$s&client=reader', time());
    if ($atom) {
//       $gUrl = "http://www.google.com/reader/api/0/subscription/list";
       $args = sprintf('hl=en&ck=%1$s&client=reader', time());
    }
//    https://www.google.com/reader/api/0/subscription/list?output=json&ck=<timeStamp>&client=<application Name>
//    https://www.google.com/reader/subscriptions/export?hl=en

    return $this->_httpGet($gUrl, $args);
  }

  // starred items
  public function getStarred($atom=false, $count=20, $continuation=false) {
    $gUrl = "http://www.google.com/reader/api/0/stream/contents/user/-/state/com.google/starred";
    if ($atom) $gUrl = "http://www.google.com/reader/atom/user/-/state/com.google/starred";
    $args = "r=n&n=".$count."&".sprintf('ck=%1$s', time())."&client=scroll";
    if (is_string($continuation) && strlen($continuation)>0) $args.="&c=".$continuation;
    return $this->_httpGet($gUrl, $args);
  }

  // notes items
  public function getNotes($atom=false, $count=20, $continuation=false) {
    $gUrl = "http://www.google.com/reader/api/0/stream/contents/user/-/source/com.google/post";
    if ($atom) $gUrl = "http://www.google.com/reader/atom/user/-/source/com.google/post";
    $args = "r=n&n=".$count."&".sprintf('ck=%1$s', time())."&client=scroll";
    if (is_string($continuation) && strlen($continuation)>0) $args.="&c=".$continuation;
    return $this->_httpGet($gUrl, $args);
  }

  // liked items
  public function getLiked($atom=false, $count=20, $continuation=false) {
    $gUrl = "http://www.google.com/reader/api/0/stream/contents/user/-/state/com.google/like";
    if ($atom) $gUrl = "http://www.google.com/reader/atom/user/-/state/com.google/like";
    $args = "r=n&n=".$count."&".sprintf('ck=%1$s', time())."&client=scroll";
    if (is_string($continuation) && strlen($continuation)>0) $args.="&c=".$continuation;
    return $this->_httpGet($gUrl, $args);
  }

  // shared items
  public function getShared($atom=false, $count=20, $continuation=false) {
    $gUrl = "http://www.google.com/reader/api/0/stream/contents/user/-/state/com.google/broadcast";
    if ($atom) $gUrl = "http://www.google.com/reader/atom/user/-/state/com.google/broadcast";
    $args = "r=n&n=".$count."&".sprintf('ck=%1$s', time())."&client=scroll";
    if (is_string($continuation) && strlen($continuation)>0) $args.="&c=".$continuation;
    return $this->_httpGet($gUrl, $args);
  }

  // shared-by-followers items
  public function getSharedFollowers($atom=false, $count=20, $continuation=false) {
    $gUrl = "http://www.google.com/reader/api/0/stream/contents/user/-/state/com.google/broadcast-friends";
    if ($atom) $gUrl = "http://www.google.com/reader/atom/user/-/state/com.google/broadcast-friends";
    $args = "r=n&n=".$count."&".sprintf('ck=%1$s', time())."&client=scroll";
    if (is_string($continuation) && strlen($continuation)>0) $args.="&c=".$continuation;
    return $this->_httpGet($gUrl, $args);
  }

  // List unread counts
  public function listURC() {
    $gUrl = "http://www.google.com/reader/api/0/unread-count";
    $args = sprintf('allcomments=true&autorefresh=2&output=json&ck=%1$s&client=reader', time());
//    https://www.google.com/reader/api/0/unread-count?allcomments=true&autorefresh=2&output=json&ck=<timeStamp>&client=<application Name>

    return $this->_httpGet($gUrl, $args);
  }

  // List all subscriptions
  public function subscriptionsOPML() {
    $gUrl = "http://www.google.com/reader/subscriptions/export";
    $args = sprintf('hl=en&ck=%1$s&client=reader', time());
    return $this->_httpGet($gUrl, $args);
  }

  // userInfo
  public function userInfo() {
    $gUrl = "http://www.google.com/reader/api/0/user-info";
    $args = sprintf('output=json&ck=%1$s&client=reader', time());
//    https://www.google.com/reader/api/0/user-info?&ck=<timeStamp>&client=<application Name>
    return $this->_httpGet($gUrl, $args);
  }
  
}



?>
