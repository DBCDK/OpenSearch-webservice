
<?php

echo "<h1>Memcache info</h1>";

echo "<p><ul>";
echo "<li>Host cache at ". trim(getenv("CACHE_HOST"), '"') . ":" . trim(getenv("CACHE_PORT"), '"') ."</li>";
echo "<li>Agency cache at ".trim(getenv("AGENCY_CACHE_HOST"), '"') . ":" . trim(getenv("AGENCY_CACHE_PORT"), '"') ."</li>";
echo "</ul></p>";

echo "<p><em>The settings for this page is picked from environment variables, NOT ini files.</em></p>";


echo "<p>Cache configuration:</p>";
echo "<table border='1'>";
tr3_me("<em>Object type</em>", "<em>Cache type</em>", "<em>Cache host:port</em>");
tr3_me("aaa/fors & solr_file", "memcache internal to pod", "localhost:11211");
tr3_me("OpenAgency/VIP", "Redis cache", trim(getenv("AGENCY_CACHE_HOST"), '"') . ":" . trim(getenv("AGENCY_CACHE_PORT"), '"'));
tr3_me("SOLR and COREPO objects", "Redis cache", trim(getenv("CACHE_HOST"), '"') . ":" . trim(getenv("CACHE_PORT"), '"'));

echo "<p>OpenSearch *always* use a local memcache at 11211 for aaa/fors and solr_file entries. Due to the very static nature of this cache, the hit ratios on this cache will often be >90%.</p>";
echo "<p>OpenSearch *always* use a redis cache for OpenAgency/VIP/SOLR/COREPO objects.</p>";

echo "<h2>OpenAgency/VIP cache info (AGENCY_CACHE_HOST)</h2>";
$rediscache_obj = new Redis();
$rediscache_obj->connect(trim(getenv("AGENCY_CACHE_HOST"), '"'), trim(getenv("AGENCY_CACHE_PORT"), '"') );
rediscache_info($rediscache_obj->info());
/*
echo "<h2>SOLR/COREPO cache info (CACHE_HOST)</h2>";
$memcache_obj = new Memcache;
$memcache_obj->connect(trim(getenv("CACHE_HOST"), '"'), trim(getenv("CACHE_PORT"), '"') );
cache_info($memcache_obj->getStats());
*/

echo "<h2>aaa/fors & solr_file cache info (localhost:11211)</h2>";
$memcache_obj = new Memcache;
$memcache_obj->connect("localhost", 11211);
memcache_info($memcache_obj->getStats());


/********************************************************************/


function tr2_me($txt, $val) {
  echo "<tr><td>$txt</td><td> $val</td></tr>";
}
function tr3_me($txt, $val1, $val2) {
  echo "<tr><td>$txt</td><td> $val1</td><td> $val2</td></tr>";
}
function memcache_info($status){
  echo "<table border='1'>";
  tr2_me("Memcache Server version: ", $status ["version"]);
  tr2_me("Process id of this server process ", $status ["pid"]);
  tr2_me("Number of seconds this server has been running ", $status ["uptime"]);
  tr2_me("Accumulated user time for this process ", round($status ["rusage_user"], 4)." seconds");
  tr2_me("Accumulated system time for this process ", round($status ["rusage_system"], 4)." seconds");
  tr2_me("Total number of items stored by this server ever since it started ", $status ["total_items"]);
  tr2_me("Number of open connections ", $status ["curr_connections"]);
  tr2_me("Total number of connections opened since the server started running ", $status ["total_connections"]);
  tr2_me("Number of connection structures allocated by the server ", $status ["connection_structures"]);
  tr2_me("Cumulative number of retrieval requests ", $status ["cmd_get"]);
  tr2_me("Cumulative number of storage requests ", $status ["cmd_set"]);

  $percCacheHit=((real)$status ["get_hits"]/ (real)$status ["cmd_get"] *100);
  $percCacheHit=round($percCacheHit,2);
  $percCacheMiss=100-$percCacheHit;

  tr2_me("Number of keys that have been requested and found present ", $status ["get_hits"]." ($percCacheHit%)");
  tr2_me("Number of items that have been requested and not found ", $status ["get_misses"]." ($percCacheMiss%)");

  $MBRead= (real)$status["bytes_read"]/(1024*1024);
  tr2_me("Total number of bytes read by this server from network ", round($MBRead,4)." Mega Bytes");

  $MBWrite=(real) $status["bytes_written"]/(1024*1024);
  tr2_me("Total number of bytes sent by this server to network ", round($MBWrite,4)." Mega Bytes");

  $MBSize=(real) $status["limit_maxbytes"]/(1024*1024) ;
  tr2_me("Number of bytes this server is allowed to use for storage.", $MBSize." Mega Bytes");
$MBSize=(int)( $status["bytes"]/(1024*1024) );
tr2_me("Number of bytes currently in storage.",$MBSize." Mega Bytes");

  tr2_me("Number of valid items removed from cache to free memory for new items.", $status ["evictions"]);

 echo "</table>";
}

function rediscache_info($status){
  echo "<table border='1'>";
  tr2_me("Rediscache Server version: ", $status ["redis_version"]);

  echo "</table>";
}


