<?php

// File to get ids from
define('ID_FILE', 'uniq_idnr.lst');
// define('ID_FILE', 'test.lst');
// define('ID_FILE', 'idnr.lst');

// What service to get objects from
// "real" Open Searches
// define('OS', 'https://opensearch.addi.dk/b3.5_5.2/');
// This disables the rediscache "locally"
// define('OS', 'https://opensearch.addi.dk/b3.5_5.2/?rediscache=SkipCache');

// Custom Open Searches
// define('OS', 'http://opensearch-5-2-service.user-mabd.svc.cloud.dbc.dk/b3.5_5.2/');
define('OS', 'http://localhost:33333/');
// define('OS', 'http://devel10.dbc.dk:54855/?rediscache=SkipCache');
// define('OS', 'https://opensearch.addi.dk/staging_5.2/');
//define('OS', 'http://opensearch-dit-service.dit-kwc.svc.cloud.dbc.dk/opensearch/');

// And what repo
define('REPO', '');
//define('REPO', 'corepo');

// When priming, get this amount of objects each time
define('PRIME_STEP', 50);

// Initial sleep length, to "clear" cache after getting the objects
define('INIT_SLEEP', 70);  // minutes

// Number of random records to fetch each time.
define('FETCH', 40);

// Sleeptime between each fetch 
define('SLEEP', 1);  // minutes

// Stop after this many loops
define('LOOPS', 5000);

// Set a tracking id. This is appended with loop number for each call
define('BASE_TRACKING_ID', 'ose_46_diff_getobject-' . uniqid() . '-');


define('XMLID', PHP_EOL . '      <ns1:identifier>%s</ns1:identifier>');
define('REQ','
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://oss.dbc.dk/ns/opensearch">
  <SOAP-ENV:Body>
    <ns1:getObjectRequest>%s
      <ns1:agency>190102</ns1:agency>
      <ns1:profile>danbib</ns1:profile>
      <ns1:repository>' . REPO . '</ns1:repository>
      <ns1:trackingId>%s</ns1:trackingId>
      <ns1:outputType>json</ns1:outputType>  
    </ns1:getObjectRequest>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>');

$curl = curl_init();
curl_setopt($curl, CURLOPT_POST, 1);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($curl, CURLOPT_TIMEOUT, 5);
curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type: text/xml;charset=UTF-8"]);
curl_setopt($curl, CURLOPT_URL, OS);

$idds = id_list(ID_FILE);
$titles = [];

echo date(DATE_ATOM) . ' Base trackingId is ' . BASE_TRACKING_ID . PHP_EOL;

echo date(DATE_ATOM) . ' Prereading all pids and storing them for later comparision ' . PHP_EOL;
$prime = [];
$count = 0;
foreach ($idds as $id) {
  $prime[] = $id;
  if (count($prime) >= PRIME_STEP) {
    get_and_save_titles($curl, $prime, build_tracking_id('prime-' . $count), $titles);
    $prime = [];
    echo date(DATE_ATOM) . ' Preread ' . count($titles) . ' titles of ' . count($idds) . PHP_EOL;
  }
  $count += 1;
}
if (!empty($prime)) {
  get_and_save_titles($curl, $prime, build_tracking_id('prime-' . $count), $titles);
}
echo date(DATE_ATOM) . ' Preread ' . count($titles) . ' titles of ' . count($idds) . PHP_EOL;
print_r($titles);


echo date(DATE_ATOM) . ' Sleep ' . INIT_SLEEP . ' minutes to clear cached records' . PHP_EOL;
sleep(INIT_SLEEP * 60);

echo date(DATE_ATOM) . ' Starting loops' . PHP_EOL;
$loop = LOOPS;
do {
  $ids = select_some_random_ids($idds);
  $trackingId = build_tracking_id((LOOPS - $loop + 1));
  curl_setopt($curl, CURLOPT_POSTFIELDS, build_req($ids, $trackingId));
  $reply = json_decode(curl_exec($curl));
  if (curl_errno($curl)) {
    echo date(DATE_ATOM) . " CURL_ERROR: errno: " . curl_errno($curl) . ", err: " . curl_error($curl) . PHP_EOL;
  } else {
    fetch_titles($reply, $ids, $trackingId, $titles);
    echo date(DATE_ATOM) . ' Loop: ' . (LOOPS - $loop + 1) . '/' . LOOPS . '. Found ' . count($titles) . ' of ' . count($idds) . PHP_EOL;
  }
  if ($loop) {
    sleep(SLEEP * 60);
    if (count($titles) == count($idds)) {
      if (empty($disp_titles)) {
        $disp_titles = 40; 
      }
      $disp_titles--;
    }
  }
} while (--$loop);

print_r($titles);

// ------------------------------------------------------------

function build_tracking_id($loopnum) {
  return BASE_TRACKING_ID . $loopnum;
}

function get_and_save_titles($curl, $prime, $trackingId, &$titles) {
  curl_setopt($curl, CURLOPT_POSTFIELDS, build_req($prime, $trackingId));
  $reply = json_decode(curl_exec($curl));
  if (curl_errno($curl)) {
    echo date(DATE_ATOM) . " CURL_ERROR: errno: " . curl_errno($curl) . ", err: " . curl_error($curl) . PHP_EOL;
    return;
  }
  foreach ($reply->searchResponse->result->searchResult as $idx => $collection) {
    foreach ($collection->collection->object as $no => $object) {
      $identifier = isset($object->record->identifier) ? $object->record->identifier[0]->{'$'} : $prime[$idx];
      $rec_title = isset($object->record->title) ? $object->record->title[0]->{'$'} : 'no title';
      $titles[$prime[$idx]] = sprintf('%-20s %s', $identifier, $rec_title);
    }
  }
}

function select_some_random_ids($ids) {
  $used = [];
  do {
    $id = $ids[rand(0, count($ids) - 1)];
    if (empty($used[$id])) {
      $used[] = $id;
    }
  } while (count($used) < min(FETCH, count($ids)));
  return $used;
}

function build_req($ids, $trackingId) {
  $xmlid = '';
  foreach ($ids as $id) {
      $xmlid .= sprintf(XMLID, $id);
  }
  echo date(DATE_ATOM) . ' Build request with trackingId: ' . $trackingId  . PHP_EOL;
  return sprintf(REQ, $xmlid, $trackingId);
}

function fetch_titles($reply, $ids, $trackingId, &$titles) {
  $ret = '';
  $idx = 0;
  foreach ($reply->searchResponse->result->searchResult as $collection) {
    foreach ($collection->collection->object as $no => $object) {
      $identifier = isset($object->record->identifier) ? $object->record->identifier[0]->{'$'} : $ids[$idx];
      $rec_title = isset($object->record->title) ? $object->record->title[0]->{'$'} : 'no title';
      $title = sprintf('%-20s %s', $identifier, $rec_title);
      $id = $ids[$idx];
      if (!empty($titles[$id]) && ($titles[$id] <> $title)) {
        echo date(DATE_ATOM) . ' **** ERROR_HR: in results from request: ' . $trackingId . '. Difference in title for ' . $id . '. Stored (expected) title: \'' . $titles[$id] . '\'. Retrieved (actual) title: \'' . $title . '\'' . PHP_EOL;
        echo date(DATE_ATOM) . ' **** ERROR_MR: %' . $trackingId . '%' . $id . '%' . $titles[$id] . '%' . $title . PHP_EOL;
      }
      $titles[$ids[$idx]] = $title;
    }
    $idx++;
  }
}

function id_list($file) {
  $ids = [];
  if (is_readable($file)) {
    $fp = fopen($file, 'r');
    while (($line = fgets($fp)) !== FALSE) $ids[] = trim($line);
    fclose($fp);
  }
  return $ids;
}
