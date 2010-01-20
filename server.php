<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/

/*
 * Caching of the process would speed up the paging thru a search-result
 *
 * relation_cache should be cached to facilitate this, key_relation_cache can be used as key
 *
*/


define(DEBUG, FALSE);

require_once("OLS_class_lib/webServiceServer_class.php");
require_once "OLS_class_lib/memcache_class.php";
require_once "OLS_class_lib/cql2solr_class.php";

class openSearch extends webServiceServer {

  protected $curl;

  public function __construct(){
    webServiceServer::__construct('opensearch.ini');

    if (!$timeout = $this->config->get_value("curl_timeout", "setup"))
      $timeout = 20;
    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, $timeout);
  }

  /** \brief Handles the request and set up the response
   */

  function search($param) { 
// set some defines
    define("WSDL", $this->config->get_value("wsdl", "setup"));
    define("SOLR_URI", $this->config->get_value("solr_uri", "setup"));
    define("FEDORA_GET_RAW", $this->config->get_value("fedora_get_raw", "setup"));
    define("FEDORA_GET_DC", $this->config->get_value("fedora_get_dc", "setup"));
    define("FEDORA_GET_RELS_EXT", $this->config->get_value("fedora_get_rels_ext", "setup"));
    //define("VALID_DC_TAGS", $this->config->get_value("valid_dc_tags", "setup"));
    define("MAX_COLLECTIONS", $this->config->get_value("max_collections", "setup"));

// check for unsupported stuff
    $ret_error->searchResponse->_value->error->_value = &$unsupported;
    if ($param->format->_value == "short")
      $unsupported = "Error: format short is not supported";
    if ($param->format->_value == "full")
      $unsupported = "Error: format full is not supported";
    if (empty($param->query->_value))
      $unsupported = "Error: No query found in request";
    if ($agency = $param->agency->_value) {
      $agencies = $this->config->get_value("agency", "agency");
      if (isset($agencies[$agency]))
        $filter_agency = $agencies[$agency];
      else
        $unsupported = "Error: Unknown agancy: " . $agency;
    }
    if ($sort = $param->sort->_value) {
      $sort_type = $this->config->get_value("sort", "setup");
      if (!isset($sort_type[$sort]))
        $unsupported = "Error: Unknown sort: " . $sort;
    }

    if ($unsupported) return $ret_error;

    $use_work_collection = ($param->collectionType->_value <> "manifestation");
/*
 *  Approach
 *  a) Do the solr search and fetch all fedoraPids in result
 *  b) Fetch work-relation from fedora using itql (risearch) unless the
 *     records is included in a earlier found work-relation
 *  c) Fetch fedoraPids in the work-relation from fedora using itql (risearch)
 *  d) repeat b. and c. until the requeste number of objects is found
 *  e) if allObject is not set, do a new search combined the users search
 *     with an or'ed list of the fedoraPids in the active objects and
 *     remove the fedoraPids not found in the result
 *  f) Read full records fom fedora for objects in result
 *
 *  if $use_work_collection is FALSE skip b) to e)
 */

    $ret_error->searchResponse->_value->error->_value = &$error;

    $step_value = min($param->stepValue->_value, MAX_COLLECTIONS);
    $start = $param->start->_value;
    if ($sort)
      $sort_q = "&sort=" . urlencode($sort_type[$sort]);
    if (empty($start) && $step_value) $start = 1;
    $this->watch->start("Solr");
    $cql2solr = new cql2solr('opensearch_cql.xml', $this->config);
    $key_relation_cache = md5($param->query->_value . "_" . $agency . "_" . $use_work_collection . "_" . $sort);
    $query = $cql2solr->convert(urldecode($param->query->_value));
    //$rank_q = urlencode(' AND _query_:"{dismax qf=$qq}' . $query . '"qq=cql.anyIndexes dc.title^4 dc.creator^4 dc.subject^2') . '&tie=0.1';
// 2DO - use fq (filterquery) for angency-filterering instead of expanding the query
    $solr_q = rawurlencode($query);
    if ($filter_agency)
      $filter_q = rawurlencode($filter_agency);
    $rows = ($start + $step_value + 100) * 2;
    if ($param->facets->_value->facetName) {
      $facet_q .= "&facet=true&facet.limit=" . $param->facets->_value->numberOfTerms->_value;
      if (is_array($param->facets->_value->facetName))
        foreach ($param->facets->_value->facetName as $facet_name)
          $facet_q .= "&facet.field=" . $facet_name->_value;
      else
        $facet_q .= "&facet.field=" . $param->facets->_value->facetName->_value;
    }

    $this->verbose->log(TRACE, "CQL to SOLR: " . $param->query->_value . " -> " . $solr_q);

// do the query
    if ($err = $this->get_solr_array($solr_q . $rank_q, $rows, $sort_q, $facet_q, $filter_q, $solr_arr))
      $error = $err;
    $this->watch->stop("Solr");

    if ($error) return $ret_error;

    $search_ids = array();
    foreach ($solr_arr["response"]["docs"] as $fpid)
      $search_ids[] = $fpid["fedoraPid"];

    $numFound = $solr_arr["response"]["numFound"];
    //$start = $solr_arr["response"]["start"];
    $facets = $this->parse_for_facets(&$solr_arr["facet_counts"]);

    $this->watch->start("Build id");
    $cache = new cache($this->config->get_value("cache_host", "setup"), 
                       $this->config->get_value("cache_port", "setup"), 
                       $this->config->get_value("cache_expire", "setup"));
    if ($relation_cache = $cache->get($key_relation_cache))
      $this->verbose->log(STAT, "Cache hit, lines: " . count($relation_cache));
    else
      $this->verbose->log(STAT, "Cache miss");

    $work_ids = $used_search_fids = array();
    $w_no = 0;
if (DEBUG) print_r($search_ids);
    for ($s_idx = 0; isset($search_ids[$s_idx]); $s_idx++) {
      $fid = &$search_ids[$s_idx];
      if (!isset($search_ids[$s_idx+1]) && count($search_ids) < $numFound) {
        $this->watch->start("Solr_add");
        $this->verbose->log(FATAL, "To few search_ids fetched from solr. Query: " . $solr_q);
        $rows *= 2;
        if ($err = $this->get_solr_array($solr_q . $rank_q, $rows, $sort_q, "", $filter_q, $solr_arr)) {
          $error = $err;
          return $ret_error;
        } else {
          $search_ids = array();
          foreach ($solr_arr["response"]["docs"] as $fpid) $search_ids[] = $fpid["fedoraPid"];
          $numFound = $solr_arr["response"]["numFound"];
        }
        $this->watch->stop("Solr_add");
      }
      if ($used_search_fids[$fid]) continue;
      if (count($work_ids) >= $step_value) {
        $more = TRUE;
        break;
      }

      $w_no++;
// find relations for the record in fedora
      if ($relation_cache[$w_no])
        $fid_array = $relation_cache[$w_no];
      else {
        if ($use_work_collection) {
          $this->watch->start("get_w_id");
          $record_uri =  sprintf(FEDORA_GET_RELS_EXT, $fid);
          $record_result = $this->curl->get($record_uri);
          $this->watch->stop("get_w_id");

          if ($work_id = $this->parse_rels_for_work_id($record_result)) {
// find other recs sharing the work-relation
            $this->watch->start("get_fids");
            $work_uri = sprintf(FEDORA_GET_RELS_EXT, $work_id);
            $work_result = $this->curl->get($work_uri);
if (DEBUG) echo $work_result;
            $this->watch->stop("get_fids");
            $fid_array = $this->parse_work_for_fedora_id($work_result);
if ($_REQUEST["work"] == "debug") {  
  echo "fid: " . $fid . " -> " . $work_id . " " . $work_uri . " with manifestations:\n"; print_r($fid_array);
}
          } else
            $fid_array = array($fid);
        } else
          $fid_array = array($fid);
        $relation_cache[$w_no] = $fid_array;
      }
if (DEBUG) print_r($fid_array);
      foreach ($fid_array as $id) {
        $used_search_fids[$id] = TRUE;
        if ($w_no >= $start) $work_ids[$w_no][] = $id;
      }
      if ($w_no >= $start) $work_ids[$w_no] = $fid_array;
    }

    if (count($work_ids) < $step_value && count($search_ids) < $numFound)
      $this->verbose->log(FATAL, "To few search_ids fetched from solr. Query: " . $solr_q);

// check if the search result contains the ids
// allObject=0 - remove objects not included in the search result
// allObject=1 & agency - remove objects not included in agency
    if ($use_work_collection) {
      $add_query = "";
      foreach ($work_ids as $w_no => $w)
        if (count($w) > 1)
          foreach ($w as $id)
            $add_query .= (empty($add_query) ? "" : " OR ") . str_replace(":", "_", $id);
      if (!empty($add_query)) {     // use post here because query can be very long
        if (empty($param->allObjects->_value))
          $q = urldecode($solr_q) . " AND fedoraNormPid:(" . $add_query . ")";
        elseif ($filter_agency)
          $q = urldecode("fedoraNormPid:(" . $add_query . ") ");
        else
          $q = "";
        if ($q) {			// need to remove unwanted object from work_ids
          $this->curl->set_post(array("wt" => "phps",
                                "q" => $q,
                                "fq" => $filter_q,
                                "start" => "0",
                                "rows" => "50000",
                                "fl" => "fedoraPid"));
          $this->watch->start("Solr 2");
          $solr_result = $this->curl->get(SOLR_URI);
          $this->watch->stop("Solr 2");
          if (!$solr_arr = unserialize($solr_result)) {
            $this->verbose->log(FATAL, "Internal problem: Cannot decode Solr re-search");
            $error = "Internal problem: Cannot decode Solr re-search";
            return $ret_error;
          }
          foreach ($work_ids as $w_no => $w)
            if (count($w) > 1) {
              $hit_fid_array = array();
              foreach ($solr_arr["response"]["docs"] as $fpid)
                if (in_array($fpid["fedoraPid"], $w))
                  $hit_fid_array[] = $fpid["fedoraPid"];
              $work_ids[$w_no] = $hit_fid_array;
            }
        }
      }
    }


    if (DEBUG) echo "txt: " . $txt . "\n";
    if (DEBUG) print_r($solr_arr);
    if (DEBUG) print_r($add_query);
    if (DEBUG) print_r($used_search_fids);
    $this->watch->stop("Build id");

    $cache->set($key_relation_cache, $relation_cache);

      if (DEBUG) print_r($work_ids);
// work_ids now contains the work-records and the fedoraPids they consist of
// now fetch the records for each work/collection
    $this->watch->start("get_recs");
    $collections = array();
    $rec_no = max(1,$start);
    foreach ($work_ids as $work) {
      $objects = array();
      foreach ($work as $fid) {
        $fedora_get =  sprintf(FEDORA_GET_RAW, $fid);
        $fedora_result = $this->curl->get($fedora_get);
        $curl_err = $this->curl->get_status();
        if ($curl_err["http_code"] > 299) {
          $error = "Error: Cannot fetch record: " . $fid . " - http-error: " . $curl_err["http_code"];
          return $ret_error;
        }
        $objects[]->_value = $this->parse_for_dc_abm(&$fedora_result, $fid, $param->format->_value);
      }
      $o->collection->_value->resultPosition->_value = $rec_no++;
      $o->collection->_value->numberOfObjects->_value = count($objects);
      $o->collection->_value->object = $objects;
      $collections[]->_value = $o;
      unset($o);
    }
    $this->watch->stop("get_recs");


if ($_REQUEST["work"] == "debug") {  
  echo "returned_work_ids: \n"; print_r($work_ids); echo "cache: \n"; print_r($relation_cache); die();
}
//if (DEBUG) { print_r($relation_cache); die(); }
//if (DEBUG) { print_r($collections); die(); }
//if (DEBUG) { print_r($solr_arr); die(); }

    $result = &$ret->searchResponse->_value->result->_value;
    $result->hitCount->_value = $numFound;
    $result->collectionCount->_value = count($collections);
    $result->more->_value = ($more ? "TRUE" : "FALSE");
    $result->searchResult = $collections;
    $result->facetResult->_value = $facets;

    $this->verbose->log(TIMER, "opensearch:: " . $this->watch->dump());
    return $ret;
  }

    private function get_solr_array($q, $rows, $sort, $facets, $filter, &$solr_arr) {
      $solr_query = SOLR_URI . "?wt=phps&q=$q&fq=$filter&start=0&rows=$rows$sort&fl=fedoraPid$facets";
      $this->verbose->log(TRACE, "Query: " . $solr_query);
      $solr_result = $this->curl->get($solr_query);
      if (empty($solr_result))
        return "Internal problem: No answer from Solr";
      if (!$solr_arr = unserialize($solr_result))
        return "Internal problem: Cannot decode Solr result";
    }
  
  /** \brief Parse a rels-ext record and extract the work id
   *
   */
  private function parse_rels_for_work_id($rels_ext) {
    static $dom;
    if (empty($dom)) $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
    if ($dom->loadXML($rels_ext)) {
      $imo = $dom->getElementsByTagName("isMemberOfWork");
      if ($imo->item(0))
        return($imo->item(0)->nodeValue);
    }
  
    return FALSE;
  }

  /** \brief Parse a work relation and return array of ids
   *
   */
  private function parse_work_for_fedora_id($w_rel) {
    static $dom;
    $res = array();
    if (empty($dom)) $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
    if ($dom->loadXML($w_rel)) {
      $r_list = $dom->getElementsByTagName("hasManifestation");
      foreach ($r_list as $r) 
        $res[] = $r->nodeValue;
      return $res;
    }
  }

  /** \brief Parse a record and extract the dc-records as a dc record
   *
   */
  private function parse_for_dc_abm(&$doc, $rec_id, $format) {
    static $dom;
    //$valids = explode(" ", trim(VALID_DC_TAGS));
    if (empty($format)) $format = "dkabm";
    if (empty($dom)) $dom = new DomDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->loadXML($doc)) {
      $this->verbose->log(FATAL, "Cannot load recid " . $rec_id . " into DomXml");
      return ;
    }
  
    $dc = $dom->getElementsByTagName("record");
    foreach ($dc->item(0)->childNodes as $tag) {
      if ($format == "dkabm" || $tag->prefix == "dc")
        if (trim($tag->nodeValue)) {
          if ($tag->hasAttributes())
            foreach ($tag->attributes as $attr) {
              $o->_attributes->{$attr->localName}->_namespace = $dc->item(0)->lookupNamespaceURI($attr->prefix);
              $o->_attributes->{$attr->localName}->_value = htmlspecialchars($attr->nodeValue);
            }
          $o->_namespace = $dc->item(0)->lookupNamespaceURI($tag->prefix);
          $o->_value = htmlspecialchars($this->char_norm(trim($tag->nodeValue)));
          if (!($tag->localName == "subject" && $tag->nodeValue == "undefined"))
            $rec->{$tag->localName}[] = $o;
          unset($o);
        }
    }

    $ret->identifier->_value = $rec_id;
    $ret->relations->_value = $relations;
    $ret->record->_value = $rec;
    $ret->record->_namespace = $dc->item(0)->lookupNamespaceURI("dkabm");
    if (DEBUG) var_dump($ret);
    return $ret;
  }

  private function char_norm($s) {
    $from[] = "\xEA\x9C\xB2"; $to[] = "Aa";
    $from[] = "\xEA\x9C\xB3"; $to[] = "aa";
    return str_replace($from, $to, $s);
  }

  /** \brief Parse solr facets and build reply
   *
   * array("facet_queries" => ..., "facet_fields" => ..., "facet_dates" => ...)
   *
   * return:
   * facet(*)
   * - facetName
   * - facetTerm(*)
   *   - frequence
   *   - term
   */
  private function parse_for_facets(&$facets) {
    if ($facets["facet_fields"])
      foreach ($facets["facet_fields"] as $facet_name => $facet_field) {
        $facet->facetName->_value = $facet_name;
        foreach ($facet_field as $term => $freq)
          if ($term && $freq) {
            $o->frequence->_value = $freq;
            $o->term->_value = $term;
            $facet->facetTerm[]->_value = $o;
            unset($o);
          }
        $ret->facet[]->_value = $facet;
        unset($facet);
      }
    return $ret;
  }

}

/*
 * MAIN
 */

$ws=new openSearch();

$ws->handle_request();

?>

