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



define(DEBUG, FALSE);

require_once("OLS_class_lib/webServiceServer_class.php");
require_once "OLS_class_lib/cql2solr_class.php";

class openSearch extends webServiceServer {

  /** \brief Handles the request and set up the response
   *
   * Sofar all records er matchet via the work-relation
   * if/when formats without this match is needed, the
   * code must branch according to that
   *
   */
  function search($param) { 
// set some defines
    define("WSDL", $this->config->get_value("wsdl", "setup"));
    define("SOLR_URI", $this->config->get_value("solr_uri", "setup"));
    define("FEDORA_GET_RAW", $this->config->get_value("fedora_get_raw", "setup"));
    define("FEDORA_GET_DC", $this->config->get_value("fedora_get_dc", "setup"));
    define("FEDORA_GET_RELS_EXT", $this->config->get_value("fedora_get_rels_ext", "setup"));
    if ($tmp = $this->config->get_value("solr_timeour", "setup"))
      define("SOLR_TIMEOUT", $tmp);
    else
      define("SOLR_TIMEOUT", 20);
    //define("VALID_DC_TAGS", $this->config->get_value("valid_dc_tags", "setup"));
    define("MAX_COLLECTIONS", $this->config->get_value("max_collections", "setup"));

// check for unsupported stuff
    if ($param->format->_value == "short")
      $unsupported->error->_value = "Error: format short is not supported";
    if ($param->format->_value == "full")
      $unsupported->error->_value = "Error: format full is not supported";
    if (empty($param->query->_value))
      $unsupported->error->_value = "Error: No query found in request";

    if ($unsupported) return $unsupported;

    if ($param->agency->_value) {
      $agencies = $this->config->get_value("agency", "agency");
      $filter_agency = $agencies[$param->agency->_value];
    }

    $step_value = min($param->stepValue->_value, MAX_COLLECTIONS);
    $start = $param->start->_value;
    if ($sort = $param->sort->_value) {
      $sort_type = $this->config->get_value("sort", "setup");
      if ($s = $sort_type[$sort])
        $sort_q = "&sort=" . urlencode($s);
    }
    if (empty($start) && $step_value) $start = 1;
    $this->watch->start("Solr");
    $cql2solr = new cql2solr('opensearch_cql.xml', $this->config);
    $query = $cql2solr->convert(urldecode($param->query->_value));
    //$rank_q = urlencode(' AND _query_:"{dismax qf=$qq}' . $query . '"qq=cql.anyIndexes dc.title^4 dc.creator^4 dc.subject^2') . '&tie=0.1';
    if ($filter_agency)
      $q_solr = rawurlencode("(" . $query .") " . $filter_agency);
    else
      $q_solr = rawurlencode($query);
    $solr_query = SOLR_URI . "?wt=phps" .
                "&q=" . $q_solr . $rank_q . 
                "&start=0" .
                "&rows=" . ($start + $step_value + 50) * 3 .
                $sort_q .
                "&fl=fedoraPid";
    if ($param->facets->_value->facetName) {
      $solr_query .= "&facet=true&facet.limit=" . $param->facets->_value->numberOfTerms->_value;
      if (is_array($param->facets->_value->facetName))
        foreach ($param->facets->_value->facetName as $facet_name)
          $solr_query .= "&facet.field=" . $facet_name->_value;
      else
        $solr_query .= "&facet.field=" . $param->facets->_value->facetName->_value;
    }

    $this->verbose->log(TRACE, "CQL to SOLR: " . $param->query->_value . " -> " . $q_solr);
    $this->verbose->log(TRACE, "Query: " . $solr_query);

/*
 *  Approach 1:
 *  a. Do the solr search and fetch all fedoraPids in result
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
    $this->watch->stop("Solr");

    if (empty($solr_result))
      $error->error->_value = "Internal problem: No answer from Solr";
    if (!$solr_arr = unserialize($solr_result))
      $error->error->_value = "Internal problem: Cannot decode Solr result";

    if ($error) return $error;

    $search_ids = array();
    foreach ($solr_arr["response"]["docs"] as $fpid)
      $search_ids[] = $fpid["fedoraPid"];

    $numFound = $solr_arr["response"]["numFound"];
    //$start = $solr_arr["response"]["start"];
    $facets = $this->parse_for_facets(&$solr_arr["facet_counts"]);

    if ($approach == 1) {
      $this->watch->start("Build id");
      $more = ($step_value == 0 && $numFound);
      $work_ids = $used_search_fids = array();
      $w_no = 0;
      reset($solr_arr["response"]["docs"]);
if (DEBUG) print_r($search_ids);
      while (count($work_ids) < $step_value) {
        list($search_idx, $fid) = each($search_ids);
        if (!$fid) break;
        if ($used_search_fids[$fid]) continue;

        $w_no++;
// find relations for the record in fedora
        if ($relation_cache[$w_no])
          $fid_array = $relation_cache[$w_no];
        else {
          $this->watch->start("get_w_id");
          $record_uri =  sprintf(FEDORA_GET_RELS_EXT, $fid);
          $record_result = $curl->get($record_uri);
          $this->watch->stop("get_w_id");

          if ($work_id = parse_rels_for_work_id($record_result)) {
// find other recs sharing the work-relation
            $this->watch->start("get_fids");
            $work_uri = sprintf(FEDORA_GET_RELS_EXT, $work_id);
            $work_result = $curl->get($work_uri);
            $this->watch->stop("get_f_ids");
            $fid_array = $this->parse_work_for_fedora_id($work_result);
          } else
            $fid_array = array($fid);
          $relation_cache[$w_no] = $fid_array;
        }

        foreach ($fid_array as $id)
          $used_search_fids[$id] = TRUE;
        if ($w_no < $start) continue;

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
          ksort($hit_fid_array);    // to keep same order as search_result
          if ($param->allObjects->_value)
            $work_ids[$w_no] = array_merge($hit_fid_array, $no_hit_fid_array);
          else
            $work_ids[$w_no] = $hit_fid_array;
        }
      }
      if (!is_null($search_idx))
        for ($i = $search_idx; $i < $numFound; $i++) {
          if ($used_search_fids[$i]) continue;
          $more = TRUE;
          break;
        }
      $this->watch->stop("Build id");
    }
    if ($approach == 2) {
      $this->watch->start("Build id");
      $work_ids = $used_search_fids = array();
      $w_no = 0;
if (DEBUG) print_r($search_ids);
      foreach ($search_ids as $fid) {
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
          $this->watch->start("get_w_id");
          $record_uri =  sprintf(FEDORA_GET_RELS_EXT, $fid);
          $record_result = $curl->get($record_uri);
          $this->watch->stop("get_w_id");

          if ($work_id = $this->parse_rels_for_work_id($record_result)) {
// find other recs sharing the work-relation
            $this->watch->start("get_fids");
            $work_uri = sprintf(FEDORA_GET_RELS_EXT, $work_id);
            $work_result = $curl->get($work_uri);
if (DEBUG) echo $work_result;
            $this->watch->stop("get_fids");
            $fid_array = $this->parse_work_for_fedora_id($work_result);
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
        $this->verbose->log(FATAL, "To few search_ids fetched from solr. Query: " . $q_solr);

// check if the search result contains the ids
      $add_query = "";
      foreach ($work_ids as $w_no => $w)
        if (count($w) > 1)
          foreach ($w as $id)
            $add_query .= (empty($add_query) ? "" : " OR ") . str_replace(":", "?", $id);
      if (!empty($add_query)) {     // use post here because query can be very long
        $curl->set_post(array("wt" => "phps",
                              "q" => urldecode($q_solr) . " AND fedoraPid:(" . $add_query . ")",
                              "start" => "0",
                              "rows" => "50000",
                              "fl" => "fedoraPid"));
        $this->watch->start("Solr 2");
        $solr_result = $curl->get(SOLR_URI);
        $this->watch->stop("Solr 2");
        if (!$solr_arr = unserialize($solr_result))
          return array("error" => "Internal problem: Cannot decode Solr re-search");
      foreach ($work_ids as $w_no => $w)
        if (count($w) > 1) {
          $hit_fid_array = array();
          foreach ($solr_arr["response"]["docs"] as $fpid)
            if (in_array($fpid["fedoraPid"], $w))
              $hit_fid_array[] = $fpid["fedoraPid"];
          if ($param->allObjects->_value)
            $work_ids[$w_no] = array_merge($hit_fid_array, array_diff_assoc($w, $hit_fid_array));
          else
            $work_ids[$w_no] = $hit_fid_array;
        }
      }


      if (DEBUG) echo "txt: " . $txt . "\n";
      if (DEBUG) print_r($solr_arr);
      if (DEBUG) print_r($add_query);
      if (DEBUG) print_r($used_search_fids);
      $this->watch->stop("Build id");
    }
      if (DEBUG) print_r($work_ids);
// work_ids now contains the work-records and the fedoraPids the consist of
// now fetch the records for each work/collection
    $this->watch->start("get_recs");
    $collections = array();
    foreach ($work_ids as $work) {
      $objects = array();
      foreach ($work as $fid) {
        $fedora_get =  sprintf(FEDORA_GET_RAW, $fid);
        $fedora_result = $curl->get($fedora_get);
        $curl_err = $curl->get_status();
        if ($curl_err["http_code"] > 299)
          return array("error" => "Error: Cannot fetch record: " . $fid . " - http-error: " . $curl_err["http_code"]);
        $objects[]->_value = $this->parse_for_dc_abm(&$fedora_result, $fid, $param->format->_value);
      }
      $o->collection->_value->resultPosition->_value = $rec_no + 1;
      $o->collection->_value->numberOfObjects->_value = count($objects);
      $o->collection->_value->object = $objects;
      $collections[]->_value = $o;
      unset($o);
    }
    $this->watch->stop("get_recs");

//if (DEBUG) print_r($relation_cache); die();
//if (DEBUG) print_r($collections); die();
//if (DEBUG) print_r($solr_arr); die();

    $result = &$ret->searchResponse->_value->result->_value;
    $result->hitCount->_value = $numFound;
    $result->collectionCount->_value = count($collections);
    $result->more->_value = ($more ? "TRUE" : "FALSE");
    $result->searchResult = $collections;
    $result->facetResult->_value = $facets;

    $this->verbose->log(TIMER, "opensearch:: " . $this->watch->dump());
    return $ret;
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
    if (!$dom->loadXML($doc))
      return ;
  
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
          $o->_value = htmlspecialchars(trim($tag->nodeValue));
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
  private function node2obj(&$node) {
   
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

$ws=new openSearch('opensearch.ini');


$ws->handle_request();

?>

