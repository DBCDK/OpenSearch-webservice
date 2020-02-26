
<?php

echo "<h1>Memcache info</h1>";

echo "<p><ul>";
echo "<li>Host cache at ". trim(getenv("CACHE_HOST"), '"') . ":" . trim(getenv("CACHE_PORT"), '"') ."</li>";
echo "<li>Agency cache at ".trim(getenv("AGENCY_CACHE_HOST"), '"') . ":" . trim(getenv("AGENCY_CACHE_PORT"), '"') ."</li>";
echo "</ul></p>";

echo "<p><em>The settings for this page is picked from environment variables, not ini files.</em></p>";

echo "<h2>Host cache info</h2>";
$memcache_obj = new Memcache;
$memcache_obj->connect(trim(getenv("CACHE_HOST"), '"'), trim(getenv("CACHE_PORT"), '"') );
cache_info($memcache_obj->getStats());

echo "<h2>Agency cache info</h2>";
$memcache_obj = new Memcache;
$memcache_obj->connect(trim(getenv("AGENCY_CACHE_HOST"), '"'), trim(getenv("AGENCY_CACHE_PORT"), '"') );
cache_info($memcache_obj->getStats());


/********************************************************************/


function tr_me($txt, $val) {
  echo "<tr><td>$txt</td><td> $val</td></tr>";
}
function cache_info($status){
  echo "<table border='1'>";
  tr_me("Memcache Server version: ", $status ["version"]);
  tr_me("Process id of this server process ", $status ["pid"]);
  tr_me("Number of seconds this server has been running ", $status ["uptime"]);
  tr_me("Accumulated user time for this process ", round($status ["rusage_user"], 4)." seconds");
  tr_me("Accumulated system time for this process ", round($status ["rusage_system"], 4)." seconds");
  tr_me("Total number of items stored by this server ever since it started ", $status ["total_items"]);
  tr_me("Number of open connections ", $status ["curr_connections"]);
  tr_me("Total number of connections opened since the server started running ", $status ["total_connections"]);
  tr_me("Number of connection structures allocated by the server ", $status ["connection_structures"]);
  tr_me("Cumulative number of retrieval requests ", $status ["cmd_get"]);
  tr_me("Cumulative number of storage requests ", $status ["cmd_set"]);

  $percCacheHit=((real)$status ["get_hits"]/ (real)$status ["cmd_get"] *100);
  $percCacheHit=round($percCacheHit,2);
  $percCacheMiss=100-$percCacheHit;

  tr_me("Number of keys that have been requested and found present ", $status ["get_hits"]." ($percCacheHit%)");
  tr_me("Number of items that have been requested and not found ", $status ["get_misses"]." ($percCacheMiss%)");

  $MBRead= (real)$status["bytes_read"]/(1024*1024);
  tr_me("Total number of bytes read by this server from network ", round($MBRead,4)." Mega Bytes");

  $MBWrite=(real) $status["bytes_written"]/(1024*1024);
  tr_me("Total number of bytes sent by this server to network ", round($MBWrite,4)." Mega Bytes");

  $MBSize=(real) $status["limit_maxbytes"]/(1024*1024) ;
  tr_me("Number of bytes this server is allowed to use for storage.", $MBSize." Mega Bytes");
$MBSize=(int)( $status["bytes"]/(1024*1024) );
tr_me("Number of bytes currently in storage.",$MBSize." Mega Bytes");

  tr_me("Number of valid items removed from cache to free memory for new items.", $status ["evictions"]);

 echo "</table>";
}
