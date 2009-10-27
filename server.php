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

require_once "OLS_class_lib/inifile_class.php";
require_once "OLS_class_lib/curl_class.php";
require_once "OLS_class_lib/cql2solr_class.php";
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
define("FEDORA_GET_RAW", $config->get_value("fedora_get_raw", "setup"));
define("FEDORA_GET_DC", $config->get_value("fedora_get_dc", "setup"));
define("FEDORA_GET_RELS_EXT", $config->get_value("fedora_get_rels_ext", "setup"));
define("RI_SEARCH", $config->get_value("ri_search", "setup"));
define("RI_SELECT_WORK", $config->get_value("ri_select_work", "setup"));
if ($tmp = $config->get_value("solr_timeour", "setup"))
  define("SOLR_TIMEOUT", $tmp);
else
  define("SOLR_TIMEOUT", 20);
define("VALID_DC_TAGS", $config->get_value("valid_dc_tags", "setup"));
define("MAX_COLLECTIONS", $config->get_value("max_collections", "setup"));

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
        //header("Content-Type: text/xml");
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . 
             array_to_xml($res);
        break;
      default:
        echo "Unknown outputType: " . $search_request->outputType;
    }
  } else {
    $server = new SoapServer(WSDL, array("cache_wsdl" => WSDL_CACHE_NONE));
    //$server = new SoapServer(WSDL);

    $server->setClass('search_it');
    $server->handle();
  }

  $verbose->log(TIMER, $timer->dump());
} elseif ($debug_req) {
  echo '<html><head><title>Test opensearch</title></head><body>' . echo_form($debug_req, $debug_info) . '</body></html>'; 
}



/* ------------------------------------------------- */

  /** \brief Handles the request and set up the response
   *
   * Sofar all records er matchet via the work-relation
   * if/when formats without this match is needed, the 
   * code must branch according to that
   *
   */
class search_it {
	public function search($param) {
    global $verbose, $timer, $config;

    if ($param->format == "short")
      return array("error" => "Error: format short is not supported");
    if ($param->format == "full")
      return array("error" => "Error: format full is not supported");

    if ($param->agency) {
      $agencies = $config->get_value("agency", "agency");
      $filter_agency = $agencies[$param->agency];
    }

    // No defaulting if (!isset($param->stepValue)) $param->stepValue = 10;
    $param->stepValue = min($param->stepValue, MAX_COLLECTIONS);
    if (empty($param->start) && $param->stepValue) $param->start = 1;
    $timer->start("Solr");
    $cql2solr = new cql2solr('opensearch_cql.xml');
    $q_solr = urlencode($cql2solr->convert(urldecode($param->query)) . ($filter_agency ? " " . $filter_agency : ""));
    $solr_query = SOLR_URI . "?wt=phps" . 
                "&q=" . $q_solr . 
                "&start=0" . 
                "&rows=" . ($param->start + $param->stepValue + 50) * 3 . 
                "&fl=fedoraPid";
    if ($param->facets->facetName) {
      $solr_query .= "&facet=true&facet.limit=" . $param->facets->numberOfTerms;
      if (is_array($param->facets->facetName))
        foreach ($param->facets->facetName as $facet_name)
          $solr_query .= "&facet.field=" . $facet_name;
      else
        $solr_query .= "&facet.field=" . $param->facets->facetName;
    }

		$verbose->log(TRACE, "CQL to SOLR: " . $param->query . " -> " . $q_solr);
		$verbose->log(TRACE, "Query: " . $solr_query);

/*
 *  Approach 1:
 *  a. Do the solr search and fecth all fedoraPids in result
 *  b. Fetch work-relation from fedora using itql (risearch) unless the
 *     records is included in a earlier found work-relation
 *  c. Fetch fedoraPids in the work-relation from fedora using itql (risearch)
 *  d. Remove fedoraPids which are not in search result unless allObjects is set
 *  e. Repeat b. to d. until the requeste number of objects is found
 *  f. Read full records fom fedora for objects in result
 *
 *  Approach 2:
 *  a. as above
 *  b. as above
 *  c. as above
 *  d. repeat b. and c. until the requeste number of objects is found
 *  e. if allObject is not set, do a new search combined the users search
 *     with an or'ed list of the fedoraPids in the active objects and
 *     remove the fedoraPids not found in the result
 *  f. as above
 * 
 */
    $approach = 2;

/*
 * Caching of the process would speed up the paging thru a search-result
 * 
 * relation_cache should be cached to facilitate this
 *
 */

// do the query
    $curl = new curl();
    $curl->set_option(CURLOPT_TIMEOUT, SOLR_TIMEOUT);
    $solr_result = $curl->get($solr_query);
    $timer->stop("Solr");

    if (empty($solr_result))
      return array("error" => "Internal problem: No answer from Solr");

    if (!$solr_arr = unserialize($solr_result))
      return array("error" => "Internal problem: Cannot decode Solr result");

    $search_ids = array();
    foreach ($solr_arr["response"]["docs"] as $fpid)
      $search_ids[] = $fpid["fedoraPid"];

    $numFound = $solr_arr["response"]["numFound"];
    $start = $solr_arr["response"]["start"];
    $facets = parse_for_facets(&$solr_arr["facet_counts"]);

    if ($approach == 1) {
      $work_ids = $used_search_fids = array();
      $w_no = 0;
      reset($solr_arr["response"]["docs"]);
print_r($search_ids);
      $timer->start("RIsearch");
      while (count($work_ids) < $param->stepValue) {
        list($search_idx, $fid) = each($search_ids);
        if (!$fid) break;
        if ($used_search_fids[$fid]) continue;

        $w_no++;
// find relations for the record in fedora
        if ($relation_cache[$w_no]) 
          $fid_array = $relation_cache[$w_no];
        else {
          $fedora_uri =  sprintf(FEDORA_GET_RELS_EXT, $fid);
          $fedora_result = $curl->get($fedora_uri);

          if ($work_id = parse_rels_for_work_id($fedora_result)) {
// find other recs sharing the work-relation
            $risearch_uri =  RI_SEARCH . urlencode(sprintf(RI_SELECT_WORK, $work_id));
            $risearch_result = $curl->get($risearch_uri);
            $fid_array = parse_work_for_fedora_id($risearch_result);
          } else 
            $fid_array = array($fid);
          $relation_cache[$w_no] = $fid_array;
        }

        foreach ($fid_array as $id)
          $used_search_fids[$id] = TRUE;
        if ($w_no < $param->start) continue;

// pick ids in work found in searchresult
        if (count($fid_array) == 1) 
          $work_ids[$w_no] = $fid_array;
        else {
          $hit_fid_array = $no_hit_fid_array = array();
          foreach ($fid_array as $id)
            if (is_int($idx = array_search($id, $search_ids)))
              $hit_fid_array[$idx] = $id;
            else
              $no_hit_fid_array[] = $id;
          ksort($hit_fid_array);		// to keep same order as search_result
          if ($param->allObjects)
            $work_ids[$w_no] = array_merge($hit_fid_array, $no_hit_fid_array);
          else
            $work_ids[$w_no] = $hit_fid_array;
        }
      }
      $timer->stop("RIsearch");
      $more = ($param->stepValue == 0 && $numFound);
      if (!is_null($search_idx))
        for ($i = $search_idx; $i < $numFound; $i++) {
          if ($used_search_fids[$i]) continue;
          $more = TRUE;
          break;
        }
    }
    if ($approach == 2) {
      $work_ids = $used_search_fids = array();
      $w_no = 0;
print_r($search_ids);
      foreach ($search_ids as $fid) {
        if ($used_search_fids[$fid]) continue;
        if (count($work_ids) >= $param->stepValue) break;

        $w_no++;
// find relations for the record in fedora
        if ($relation_cache[$w_no]) 
          $fid_array = $relation_cache[$w_no];
        else {
          $timer->start("Get rels_ext");
          $fedora_uri =  sprintf(FEDORA_GET_RELS_EXT, $fid);
          $fedora_result = $curl->get($fedora_uri);
          $timer->stop("Get rels_ext");

          if ($work_id = parse_rels_for_work_id($fedora_result)) {
// find other recs sharing the work-relation
            $timer->start("Get work");
            $risearch_uri =  RI_SEARCH . urlencode(sprintf(RI_SELECT_WORK, $work_id));
		        $verbose->log(TRACE, "GetWork: " . $risearch_uri);
            $risearch_result = $curl->get($risearch_uri);
            $timer->stop("Get work");
            $fid_array = parse_work_for_fedora_id($risearch_result);
          } else
            $fid_array = array($fid);
          $relation_cache[$w_no] = $fid_array;
        }
print_r($fid_array);
        foreach ($fid_array as $id) {
          $used_search_fids[$id] = TRUE;
          if ($w_no >= $param->start) $work_ids[$w_no][] = $id;
        }
        if ($w_no >= $param->start) $work_ids[$w_no] = $fid_array;
      }

      if (count($work_ids) < $param->stepValue && count($search_ids) < $numFound) 
		    $verbose->log(FATAL, "To few search_ids fetched from solr. Query: " . $q_solr);
      

// check if the search result contains the ids
      $add_query = "";
      foreach ($work_ids as $w_no => $w)
        if (count($w) > 1)
          foreach ($w as $id)
            $add_query .= (empty($add_query) ? "" : " OR ") . str_replace(":", "?", $id);
      if (!empty($add_query)) {			// use post here because query can be very long
        $curl->set_post(array("wt" => "phps", 
                              "q" => urldecode($q_solr) . " AND fedoraPid:(" . $add_query . ")", 
                              "start" => "0", 
                              "rows" => "50000", 
                              "fl" => "fedoraPid"));
        $timer->start("Solr 2");
        $solr_result = $curl->get(SOLR_URI);
        $timer->stop("Solr 2");
        if (!$solr_arr = unserialize($solr_result))
          return array("error" => "Internal problem: Cannot decode Solr re-search");
      foreach ($work_ids as $w_no => $w)
        if (count($w) > 1) {
          $hit_fid_array = array();
          foreach ($solr_arr["response"]["docs"] as $fpid)
            if (in_array($fpid["fedoraPid"], $w))
              $hit_fid_array[] = $fpid["fedoraPid"];
          if ($param->allObjects)
            $work_ids[$w_no] = array_merge($hit_fid_array, array_diff_assoc($w, $hit_fid_array));
          else
            $work_ids[$w_no] = $hit_fid_array;
        }
      }


      echo "txt: " . $txt . "\n";
      print_r($solr_arr);
      print_r($add_query);
      print_r($used_search_fids);
    }
      print_r($work_ids); 
    
// work_ids now contains the work-records and the fedoraPids the consist of
// now fetch the records for each work/collection
    $timer->start("Fedora");
    $collections = array();
    foreach ($work_ids as $work) {
      $objects = array();
      foreach ($work as $fid) {
        $fedora_get =  sprintf(FEDORA_GET_RAW, $fid);
        $fedora_result = $curl->get($fedora_get);
        $curl_err = $curl->get_status();
        if ($curl_err["http_code"] > 299)
          return array("error" => "Error: Cannot fetch record: " . $fid . " - http-error: " . $curl_err["http_code"]);
        $timer->start("dc_parse");
        $objects[] = parse_for_dc_abm(&$fedora_result, $fid, $param->format);
        $timer->stop("dc_parse");
      }
      $collections[] = array("resultPosition" => $rec_no + 1,
                             "numberOfObjects" => count($objects),
                             "object" => $objects);
    }
    $timer->stop("Fedora");
      
//print_r($relation_cache); die();
//print_r($collections); die();
//print_r($solr_arr); die();

		return array("result" => 
             array("hitCount" => $numFound, 
                   "collectionCount" => count($collections),
                   "more" => $more,
                   "searchResult" => $collections,
                   "facetResult" => $facets));
	}
}

/* ------------------------------------------------- */

/** \brief Parse a rels-ext record and extract the work id
 *
 */
function parse_rels_for_work_id($rels_ext) {
  static $dom;
  if (empty($dom)) $dom = new DomDocument();
  $dom->preserveWhiteSpace = false;
  if ($dom->loadXML($rels_ext)) {
    $imo = $dom->getElementsByTagName("isMemberOf");
    if ($imo->item(0))
      return($imo->item(0)->getAttribute("rdf:resource"));
  }

  return FALSE;
}

/** \brief Parse a work relation and return array of ids
 *
 */
function parse_work_for_fedora_id($w_rel) {
  static $dom;
  $res = array();
  if (empty($dom)) $dom = new DomDocument();
  $dom->preserveWhiteSpace = false;
  if ($dom->loadXML($w_rel)) {
    $r_list = $dom->getElementsByTagName("result");
    foreach ($r_list as $r) {
      list($dummy, $res[]) = split("/", $r->getElementsByTagName("s")->item(0)->getAttribute("uri"), 2);
    }
    return $res;
  }
}

/** \brief Parse a record and extract the dc-records as a dc record
 *
 */
function parse_for_dc_abm(&$doc, $rec_id, $format) {
  static $dom;
  //$valids = explode(" ", trim(VALID_DC_TAGS));
  if (empty($format)) $format = "dkabm";
  if (empty($dom)) $dom = new DomDocument();
  $dom->preserveWhiteSpace = false;
  if (!$dom->loadXML($doc))
    return array();

  $dc = $dom->getElementsByTagName("record");
  $rec = array();
  foreach ($dc->item(0)->childNodes as $tag) {
    if ($format == "dkabm" || $tag->prefix == "dc")
      if (trim($tag->nodeValue)) {
        $nmsp = xmlify_namespace($dc->item(0)->lookupNamespaceURI($tag->prefix));
        $rec[$nmsp . "." . $tag->localName][] = trim($tag->nodeValue);
      }
  }

  return array("identifier" => $rec_id, "relations" => $relations, $format => $rec);
}

/** \brief Create a path from a namespace 
 *
 * Given: http://aaa.bbb.ccc/ddd/eee/
 * Returns: ccc.bbb.aaa.ddd.eee
 *
 */
function xmlify_namespace($uri) {
  static $cache = array();
  if (empty($cache[$uri])) {
    $parts = parse_url($uri);
    $h = split("[\.]", $parts["host"]);
    $p = split("[/]", $parts["path"]);
    for ($i = count($h); $i; $i--)
      if ($h[$i - 1]) $ret .= ($ret ? "." : "") . $h[$i - 1];
    for ($i = 0; $i < count($p); $i++)
      if ($p[$i]) $ret .= ($ret ? "." : "") . $p[$i];
    $cache[$uri] = $ret;
  }
  return $cache[$uri];
}

/** \brief Parse solr facets and build reply
 *
 * array("facet_queries" => ..., "facet_fields" => ..., "facet_dates" => ...)
 *
 */
function parse_for_facets(&$facets) {
  $ret = array();
  if ($facets["facet_fields"])
    foreach ($facets["facet_fields"] as $facet_name => $facet_field) {
      $r_arr = array("facetName" => $facet_name);
      foreach ($facet_field as $term => $freq)
        if ($term && $freq)
          $r_arr["facetTerm"][] = array("term" => $term, "frequence" => $freq);
      $ret[] = $r_arr;
    }
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
  elseif ($arr) {
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
        if ($tag == $par) $ret .= tag_me($tag, $val);
      }
  return $ret;
}

function par_split($parval) {
  list($par, $val) = explode("=", $parval, 2);
  return array(preg_replace("/\[[^]]*\]/", "", urldecode($par)), $val);
}

function tag_me($tag, $val) {
//  if ($i = strrpos($tag, "."))
//    $tag = substr($tag, $i+1);
  return "<$tag>$val</$tag>"; 
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
