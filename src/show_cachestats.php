
<?php

echo "<h1>Memcache info</h1>";

echo "<p><ul>";
echo "<li>Host cache at ". trim(getenv("CACHE_HOST"), '"') . ":" . trim(getenv("CACHE_PORT"), '"') ."</li>";
echo "<li>Agency cache at ".trim(getenv("AGENCY_CACHE_HOST"), '"') . ":" . trim(getenv("AGENCY_CACHE_PORT"), '"') ."</li>";
echo "</ul></p>";

echo "<p><em>The settings for this page are picked from environment variables, NOT ini files.</em></p>";

$cache_type = trim(getenv("CACHE_TYPE"), '"');
$agency_cache_host = trim(getenv("AGENCY_CACHE_HOST"), '"');
$agency_cache_port = trim(getenv("AGENCY_CACHE_PORT"), '"');
$cache_host = trim(getenv("CACHE_HOST"), '"');
$cache_port = trim(getenv("CACHE_PORT"), '"');

echo "<p>Cache configuration:</p>";
echo "<table border='1'>";
tr3_me("<em>Object type</em>", "<em>Cache type</em>", "<em>Cache host:port</em>");
tr3_me("aaa/fors & solr_file", "memcache internal to pod", "localhost:11211");
tr3_me("OpenAgency/VIP", $cache_type, $agency_cache_host . ":" . $agency_cache_port );
tr3_me("SOLR and COREPO objects", $cache_type, $cache_host . ":" . $cache_port);
echo "</table></p>";

echo "<p>OpenSearch <em>always</em> uses a local memcache at 11211 for aaa/fors and solr_file entries. Due to the very static nature of this cache, the hit ratios on this cache will often be >90%.</p>";
echo "<p>OpenSearch <em>always</em> uses a memcache OR redis cache for OpenAgency/VIP and SOLR/COREPO objects. These caches can be different, but this script does not make this distinction.</p>";

// There are more information about the stats to get out of redis here: https://github.com/phpredis/phpredis#info
// and here: https://redis.io/commands/info

if ($cache_type == "redis") {
  echo "<h2>OpenAgency/VIP cache info (AGENCY_CACHE_HOST)</h2>";
  $rediscache_obj = new Redis();
  $rediscache_obj->connect($agency_cache_host, $agency_cache_port);
  rediscache_info($rediscache_obj->info("all"));

  echo "<h2>SOLR/COREPO cache info (CACHE_HOST)</h2>";
  $rediscache_obj = new Redis();
  $rediscache_obj->connect($cache_host, $cache_port);
  rediscache_info($rediscache_obj->info("all"));
} else {
  echo "<h2>OpenAgency/VIP cache info (AGENCY_CACHE_HOST)</h2>";
  $memcache_obj = new Memcache();
  $memcache_obj->connect($agency_cache_host, $agency_cache_port);
  memcache_info($memcache_obj->getStats());

  echo "<h2>SOLR/COREPO cache info (CACHE_HOST)</h2>";
  $memcache_obj = new Memcache();
  $memcache_obj->connect($cache_host, $cache_port);
  memcache_info($memcache_obj->getStats());
}

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
  tr2_me("Total number of items stored by this server since it started ", $status ["total_items"]);
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
  tr2_me("Total number of bytes read by this server from network ", round($MBRead,4)." Megabytes");

  $MBWrite=(real) $status["bytes_written"]/(1024*1024);
  tr2_me("Total number of bytes sent by this server to network ", round($MBWrite,4)." Megabytes");

  $MBSize=(real) $status["limit_maxbytes"]/(1024*1024) ;
  tr2_me("Number of bytes this server is allowed to use for storage.", $MBSize." Megabytes");
  $MBSize=(int)( $status["bytes"]/(1024*1024) );
  tr2_me("Number of bytes currently in storage.",$MBSize." Megabytes");

  tr2_me("Number of valid items removed from cache to free memory for new items.", $status ["evictions"]);

 echo "</table>";
}

function rediscache_info($status){
  echo "<table border='1'>";

  $interesting = array(
    "redis_version" => "Redis version",
    "redis_mode" => "Redis mode",
    "uptime_in_seconds" => "Uptime in seconds",
    "uptime_in_days" => "Uptime in days",
    "connected_clients" => "Connected clients",
    "used_memory_human" => "Used memory",
    "used_memory_rss_human" => "Used memory resident",
    "used_memory_peak_human" => "Used memory peak",
    "used_memory_peak_perc" => "Used memory peak percentage",
    "used_memory_overhead" => "Used memory overhead",
    "used_memory_startup" => "Used memory startup",
    "used_memory_dataset" => "Used memory dataset",
    "used_memory_dataset_perc" => "Used memory data percentage",
    "total_system_memory_human" => "Total system memory",
    "used_memory_lua_human" => "Used memory lua",
    "used_memory_scripts_human" => "Used memory scripts",
    "maxmemory_human" => "Max memory",
    "maxmemory_policy" => "Max memory policy",
    "expired_keys"=> "Expired keys",
    "expired_stale_perc" => "Expired stale percentage",
    "expired_time_cap_reached_count" => "Expired time cap reached count",
    "evicted_keys" => "Evicted keys",
    "used_cpu_sys" => "Used CPU system",
    "used_cpu_user" => "Used CPU user",
    "used_cpu_sys_children" => "Used CPU system (children)",
    "used_cpu_user_children" => "Used CPU user (children)",
    "cmdstat_get" => "Get command stats",
    "cmdstat_info	calls" => "Info command stats",
    "cmdstat_setex" => "Set(ex) command stats",
    "cmdstat_command" => "Command command stats",
    "cluster_enabled"	=> "Cluster enabled",
    "db0" => "DB0 stats",
    "db1" => "DB0 stats",
    "db2" => "DB0 stats",
    "db3" => "DB0 stats",
    "db4" => "DB0 stats",
    "db5" => "DB0 stats",
    "db6" => "DB0 stats",
    "db7" => "DB0 stats",
    "db8" => "DB0 stats",
    "db9" => "DB0 stats"

    // "keyspace_hits" => "Keyspace hits",
    // "keyspace_misses" => "Keyspace misses"
  );

  // Translate the keys we have, ignore the rest.
  foreach ($status as $key => $value) {
    if (array_key_exists($key, $interesting)) {
      tr2_me($interesting[$key], $value);
    } else {
      // Enable if you wish to see all values.
      //tr2_me($key, $value);
    }
  }

  // Some calculated stats, made to match the stuff from the memcache caches.
  $total_gets = (real) $status["keyspace_hits"] + (real) $status["keyspace_misses"];
  $percCacheHit = ((real)$status["keyspace_hits"]) / $total_gets * 100.0;
  $percCacheHit=round($percCacheHit,2);
  $percCacheMiss=100-$percCacheHit;

  tr2_me("Cumulative number of retrieval requests", $total_gets);
  tr2_me("Number of keys that have been requested and found present ", $status ["keyspace_hits"]." ($percCacheHit%)");
  tr2_me("Number of items that have been requested and not found ", $status ["keyspace_misses"]." ($percCacheMiss%)");


  echo "</table>";
}


