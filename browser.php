<?php

require_once("OLS_class_lib/curl_class.php");
require_once("OLS_class_lib/inifile_class.php");

  $ini = new inifile("opensearch.ini");
  $fedora_uri = $ini->get_value("fedora_get_rels_ext", "setup");
  //if ($p = strpos($fedora_uri, "%s")) $fedora_uri = substr($fedora_uri, 0, $p+2);
  $ego_uri = "http://" . $_SERVER["HTTP_HOST"] . dirname($_SERVER["PHP_SELF"]) . "/";

  if (!$fedora = $_REQUEST["fedora"]) $fedora = $fedora_uri;
  if (!strpos($fedora, "%s")) $fedora .= "%s";
  if (!$uri = $_REQUEST["uri"]) $uri = $ego_uri;
  if (!$start = $_REQUEST["start"]) $start = 1;
  if (!$step = $_REQUEST["step"]) $step = 10;
$fields = array("creator" => "dc_creator", "title" => "dc_title");

  echo '<html><head><title>Simple OpenSearch Browser</title><style>td {vertical-align:top; padding-right:1em}</style></<head><body>
        <form name="f" method="get"><table>
        <tr><td>Fedora</td><td><input type="text" name="fedora" size="80" value="' . $fedora . '"></td></tr>
        <tr><td>OpenSearch</td><td style="padding-bottom:1em"><input type="text" name="uri" size="80" value="' . $uri . '"></td></tr>';
  foreach ($fields as $tag => $field) {
    echo '<tr><td>' . $tag . ': </td><td> <input type="text" name="' . $field .'" size="50" value="' . $_REQUEST[$field] . '"></td></tr><br/>';
    if ($val = $_REQUEST[$field])
      $query .= (empty($query) ? "" : " AND ") . str_replace("_", ".", $field) . "=" . $val;
  }
  echo '<tr><td>agency: </td><td><input type="test" size="6" value="' . $_REQUEST["agency"] . '"> Start: <input type="text" size="2" name="start" value="' . $start . '"> Step: <input type="text" size="2" name="step" value="' . $step . '"></td></tr><br/><tr><td>fields</td><td>'; 
  $disps = array("type", "title", "creator", "source", "all");
  foreach ($disps as $dtag) {
    if ($_REQUEST[$dtag]) $disp_tag[$dtag] = TRUE;
    echo $dtag . ':<input type="checkbox" name="' . $dtag . '" ' . ($disp_tag[$dtag] ? "checked" : "") . '> &nbsp; ';
  }
  echo '<tr><td colspan="2"><input type="submit" value="Try me"></td></tr></table></form>';

  if ($query) {
    $curl = new curl();
    $curl->set_option(CURLOPT_TIMEOUT, 20);
    $os_result = unserialize($curl->get($uri . "?action=search&query=" . urlencode($query) . "&start=$start&stepValue=$step&agency=" . $_REQUEST["agency"] . "&outputType=php"));

    if ($result = $os_result->searchResponse->_value->result->_value) {
      echo "<hr>";
      echo "hitcount: " . $result->hitCount->_value . 
           " &nbsp; collections: " . $result->collectionCount->_value . 
           " &nbsp; more: " . $result->more->_value . "<br/>";
      foreach ($result->searchResult as $recno => $rec) {
        $collection = &$rec->_value->collection->_value;
        echo 'collection no: ' . $collection->resultPosition->_value . 
             ' objects: ' . $collection->numberOfObjects->_value . '<table style="margin-left:1em">';
        foreach ($collection->object as $ono => $object) {
          $fpid = $object->_value->identifier->_value;
          $record = &$object->_value->record->_value;
          echo '<tr><td nowrap>Object #' . ($ono+1) . '</td><td>identifier</td><td><a href="' . sprintf($fedora, $fpid) . '">' . $fpid . '</a> </td></tr>';
          foreach ($record as $tag => $vals)
            if ($disp_tag[$tag] || $disp_tag["all"]) 
              foreach ($vals as $val) {
                $namespace = "";
                $namespace = $val->_namespace;
                $title = "";
                if ($val->_attributes) 
                  foreach ($val->_attributes as $aname => $aval)
                    $title .= $aname . "=" . $aval->_value . " ";
                echo '<tr><td></td><td' . ($namespace ? ' title="' . $namespace . '"' : '') . '>' . $tag . ' </td><td' . ($title ? ' title="' . $title . '"' : '') . '> ' . $val->_value . '</td></tr>';
              }
        }
        echo '</table><hr style="text-align:left;width:20em">';
      }
    } else {
      echo "Ukendt resultat<br/>"; 
      var_dump($os_result);
    }

  }

  echo '</body></html>';

?>
