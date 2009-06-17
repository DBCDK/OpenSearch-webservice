<?php
/**
 *   
 * This file is part of openSearch.
 * Copyright © 2009, Dansk Bibliotekscenter a/s, 
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * openSearch is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * openSearch is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with openSearch.  If not, see <http://www.gnu.org/licenses/>.
*/


/** \brief opensearch webservice server
 *
 * 2do: error handling in wsdl and below
 */

// resttest: http://vision.dbc.dk/~fvs/broend/opensearch/?action=searchRequest&query=dc.title:danmark&format=dc&facets.number=10&facets.facetName=dc.creator&facetName=dc.title&outputType=json

require_once "OLS_class_lib/inifile_class.php";
require_once "OLS_class_lib/curl_class.php";
require_once "OLS_class_lib/verbose_class.php";
require_once "OLS_class_lib/timer_class.php";

// create timer and define timer format
$timer = new stopwatch("", " ", "", "%s:%01.3f");

// Fetch ini file and Check for needed settings
define("INIFILE", "opensearch.ini");
$config = new inifile(INIFILE);
if ($config->error)
  usage($config->error);

// some constants
define("WSDL", $config->get_value("wsdl", "setup"));
define("SOLR_URI", $config->get_value("solr_uri", "setup"));
if ($tmp = $config->get_value("solr_timeour", "setup"))
  define("SOLR_TIMEOUT", $tmp);
else
  define("SOLR_TIMEOUT", 20);
define("VALID_DC_TAGS", $config->get_value("valid_dc_tags", "setup"));

// for logging
$verbose = new verbose($config->get_value("logfile", "setup"), 
                       $config->get_value("verbose", "setup"));

// Essentials
if (!constant("WSDL"))			usage("No WSDL defined in " . INIFILE);
if (!constant("SOLR_URI"))	usage("No SOLR_URI defined in " . INIFILE);


// environment ok, ready and eager to serve


// surveil check?
if (isset($_GET["HowRU"]))	how_am_i($config);


// Look for a request. SOAP or REST or test from browser
if (!isset($HTTP_RAW_POST_DATA))
  if (!$HTTP_RAW_POST_DATA =  get_REST_request($config))
    if (!$HTTP_RAW_POST_DATA = stripslashes($_REQUEST["request"])) {
       $debug_req = $config->get_value("debug_request", "debug");
       $debug_info = $config->get_value("debug_info", "debug");
    } 

if (empty($HTTP_RAW_POST_DATA) && empty($debug_req))	
  usage("No input data found");


// Request found
if ($HTTP_RAW_POST_DATA) {
  $verbose->log(TRACE, $HTTP_RAW_POST_DATA);

  $request_obj = soap_to_obj($HTTP_RAW_POST_DATA);
  $search_request = &$request_obj->{'Envelope'}->{'Body'}->{'searchRequest'};
  if ($search_request->outputType) {
    $timer->start("Solr");
    $search_it = new search_it();
    $res = $search_it->search($search_request);
    switch ($search_request->outputType) {
      case "json":
        if ($search_request->callback)
          echo $search_request->callback . " && " . $search_request->callback . "(" . json_encode($res) . ")";
        else
          echo json_encode($res);
        break;
      case "php":
        echo serialize($res);
        break;
      case "xml":
        header("Content-Type: text/xml");
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . 
             array_to_xml($res);
        break;
      default:
        echo "Unknown outputType: " . $search_request->outputType;
    }
    $timer->stop("Solr");
  } else {
    $timer->start("SoapServer");
    //$server = new SoapServer(WSDL, array("cache_wsdl" => WSDL_CACHE_NONE));
    $server = new SoapServer(WSDL);
    $timer->stop("SoapServer");

    $timer->start("Solr");
    $server->setClass('search_it');
    $server->handle();
    $timer->stop("Solr");
  }

  $verbose->log(TIMER, $timer->dump());
} elseif ($debug_req) {
  echo '<html><head><title>Test opensearch</title></head><body>' . echo_form($debug_req, $debug_info) . '</body></html>'; 
}



/* ------------------------------------------------- */

  /** \brief Handles the request and set up the response
   *
   */
class search_it {
	public function search($param) {
    global $verbose;
//var_dump($param);
		$this->query = $param->query;
		$this->source = $param->source;
		$this->facets = $param->facets;
		$this->start = $param->start;
		$this->step_value = $param->stepValue;
		$this->sort = $param->sort;

    $solr_query = SOLR_URI . "?wt=xml" . 
                "&q=" . $this->query . 
                "&start=" . max(0, $this->start - 1).
                "&rows=" . $this->step_value .
                "&facet=true&facet.limit=" . $this->facets->number;
    if (is_array($this->facets->facetName))
      foreach ($this->facets->facetName as $facet_name)
        $solr_query .= "&facet.field=" . $facet_name;
    else
      $solr_query .= "&facet.field=" . $this->facets->facetName;

		$verbose->log(TRACE, "Query: " . $solr_query);

// do the query
    $curl = new curl();
    $curl->set_option(CURLOPT_TIMEOUT, SOLR_TIMEOUT);
    $solr_result = $curl->get($solr_query);

    if (empty($solr_result))
      usage("No result back from solr");  // 2do real error answer

    $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if (!$dom->loadXML($solr_result))
      usage("No god xml back from solr");  // 2do real error answer
    //$encoding = $dom->xmlEncoding;
    $root_node = $dom->documentElement;

    foreach ($dom->childNodes as $node)
      if($node->nodeName == "response")
        foreach ($node->childNodes as $response)
          switch ($response->nodeName) {
            case "result" : 
              $numFound = $response->getAttribute("numFound");
              $start = $response->getAttribute("start");
              $maxScore = $response->getAttribute("maxScore");
              $dc_records = parse_for_dc_records(&$response);
              break;
            case "lst" : 
              switch ($response->getAttribute("name")) {
                case "responseHeader":
                  break;
                case "facet_counts":
                  $facets = parse_for_facets(&$response);
                  break;
              }
              break;
          }
          

    foreach ($dc_records as $dc_rec)
      $records->tingRecord[] = array("identifier" => "dc", "dc" => $dc_rec);

		return array("searchResult" => array("hitCount" => $numFound, 
                                         "records" => $records, 
                                         "facetResult" => $facets));
	}
}

/* ------------------------------------------------- */

/** \brief Parse a record and extract the dc-records as a dc record
 *
 */
function parse_for_dc_records(&$docs) {
  $ret = array();
  $valids = explode(" ", trim(VALID_DC_TAGS));

  foreach ($docs->childNodes as $doc)
    if ($doc->nodeName == "doc") {
      $rec = array();
      foreach ($doc->childNodes as $tag)
        if (in_array($tag->getAttribute("name"), $valids)) {
          switch ($tag->nodeName) {
            case "str":
              $rec[$tag->getAttribute("name")][] = trim($tag->nodeValue);
              break;
            case "arr":
              foreach ($tag->childNodes as $item)
// BUG ??? only forward unique entries
                if (!is_array($rec[$tag->getAttribute("name")]) || 
                    !in_array(trim($item->nodeValue), $rec[$tag->getAttribute("name")]))
                  $rec[$tag->getAttribute("name")][] = trim($item->nodeValue);
              break;
          }
        }
      $ret[] = $rec;
    }
  //print_r($ret);
  return $ret;
}

/** \brief Parse solr facets and build reply
 *
 */
function parse_for_facets(&$facets) {
  $ret = array();
  //echo "parse_for_facets";
  foreach ($facets->childNodes as $facet)
    if ($facet->nodeName == "lst")
      switch ($facet->getAttribute("name")) {
        case "facet_fields": 
          foreach ($facet->childNodes as $facet_tag)
            if ($facet_tag->nodeName == "lst") {
              $facet_array = array("facetName" => $facet_tag->getAttribute("name"), "facetTerm" => array());
              foreach ($facet_tag->childNodes as $facet_item)
                if ($facet_item->nodeValue)
                  $facet_array["facetTerm"][] = array("term" => $facet_item->getAttribute("name"),
                                                    "frequence" => $facet_item->nodeValue);
              $ret[] = $facet_array;
            }

          break;
        case "facet_queries": 
        case "facet_dates": 
          break;
      }
  //print_r($ret);
  return $ret;
}

/** \brief Echoes a string, display usage info and die
 *
 */
function usage($str = "") {
  if ($str) echo $str . "<br/>";
	echo "Usage: ";
  die();
}

/** \brief Checks if needed components are available and responds
 *
 * 2do: This will be replaces by a test-class
 */
function how_am_i(&$config) {
  // Check solr
  $solr_test = $config->get_value("solr_test", "howru");
  $solr_match = $config->get_value("solr_match", "howru");
  $solr_error = $config->get_value("solr_error", "howru");

  $curl = new curl();
  $curl->set_option(CURLOPT_TIMEOUT, 5);
  foreach ($solr_test as $key => $val) {
    $solr_result = $curl->get(SOLR_URI."?".$val);
    if (! ereg($solr_match[$key], $solr_result) )
      if ($solr_error[$key])
        $err .= $solr_error[$key] . "\n";
      else
        $err .= "Failed test no: " . $key . "\n";
  }

  // Checks done
  if (empty($err)) die("Gr8\n"); else die($err);
}

/** \brief Create a SOAP-object
 *
 */
function soap_to_obj(&$request) {
  $dom = new DomDocument();
  $dom->preserveWhiteSpace = false;
  if ($dom->loadXML($request))
    return xml_to_obj($dom);
}
function xml_to_obj($domobj) {
  //var_dump($domobj->nodeName);
  //echo "len: " . $domobj->domobj->childNodes->length;
  foreach ($domobj->childNodes as $node) {
    $nodename = $node->nodeName;
    if ($i = strpos($nodename, ":")) $nodename = substr($nodename, $i+1);
    if ($node->nodeName == "#text")
      $ret = $node->nodeValue;
    elseif (is_array($ret->{$node->nodeName}))
      $ret->{$nodename}[] = xml_to_obj($node);
    elseif (isset($ret->$nodename)) {
      $tmp = $ret->$nodename;
      unset($ret->$nodename);
      $ret->{$nodename}[] = $tmp;
      $ret->{$nodename}[] = xml_to_obj($node);
    } else
      $ret->$nodename = xml_to_obj($node);
  }

  return $ret;
}

/** \brief Creates xml from array. Numeric indices creates repetitive tags
 *
 */
function array_to_xml($arr) {
  if (is_scalar($arr))
    return htmlspecialchars($arr);
  else {
    foreach ($arr as $key => $val)
      if (is_array($val) && is_numeric(array_shift(array_keys($val))))
        foreach ($val as $num_val)
          $ret .= tag_me($key, array_to_xml($num_val));
      else
        $ret .= tag_me($key, array_to_xml($val));
    return $ret;
  }
}

/** \brief Transform REST parameters to SOAP-request
 *
 */
function get_REST_request(&$config) {
  $action_pars = $config->get_value("action", "rest");
  if (is_array($action_pars) && $_GET["action"] && $action_pars[$_GET["action"]]) {
    if ($node_value = build_xml(&$action_pars[$_GET["action"]], explode("&", $_SERVER["QUERY_STRING"])))
      return html_entity_decode($config->get_value("soap_header", "rest")) . 
             tag_me($_GET["action"], $node_value) . 
             html_entity_decode($config->get_value("soap_footer", "rest"));
  }
}

function build_xml($action, $query) {
  foreach ($action as $key => $tag)
    if (is_array($tag))
      $ret .= tag_me($key, build_xml($tag, $query));
    else
      foreach ($query as $parval) {
        list($par, $val) = par_split($parval);
        if ($tag == $par && $val) $ret .= tag_me($tag, $val);
      }
  return $ret;
}

function par_split($parval) {
  list($par, $val) = explode("=", $parval, 2);
  return array(preg_replace("/\[[^]]*\]/", "", urldecode($par)), $val);
}

function tag_me($tag, $val) {
  if ($val) {
    if ($i = strrpos($tag, "."))
      $tag = substr($tag, $i+1);
    return "<$tag>$val</$tag>"; 
  }
  return;
}

/** \brief For browsertesting
 *
 */
function echo_form(&$reqs, $info="") {
  foreach ($reqs as $key => $req)
    $reqs[$key] = addcslashes(html_entity_decode($req), '"');

  $ret = '<script language="javascript">' . "\n" . 'reqs = Array("' . implode('","', $reqs) . '");</script>';
  $ret .= '<form name="f" method="post"><textarea rows="18" cols="80" name="request">' . stripslashes($_REQUEST["request"]) . '</textarea>';
  $ret .= '<br/><br/><select name="no" onChange="if (this.selectedIndex) document.f.request.value = reqs[this.options[this.selectedIndex].value];"><option>Pick a test-request</option>';
  foreach ($reqs as $key => $req)
    $ret .= '<option value="' . $key . '">Test request nr ' . $key . '</option>';
  $ret .= '</select> &nbsp; <input type="submit" name="subm" value="Try me">';
  $ret .= '</form>';
  return $ret . html_entity_decode($info);
}

?>
