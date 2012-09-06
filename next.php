<?php
//-----------------------------------------------------------------------------
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

//-----------------------------------------------------------------------------
require_once('OLS_class_lib/webServiceServer_class.php');
require_once 'OLS_class_lib/memcache_class.php';
require_once 'OLS_class_lib/cql2solr_class.php';

//-----------------------------------------------------------------------------
define(REL_TO_INTERNAL_OBJ, 1);       // relation points to internal object
define(REL_TO_EXTERNAL_OBJ, 2);     // relation points to external object

//-----------------------------------------------------------------------------
class openSearch extends webServiceServer {
  protected $cql2solr;
  protected $curl;
  protected $cache;
  protected $search_profile;
  protected $search_profile_version;
  protected $repository; // array containing solr and fedora uri's
  protected $work_format; // format for the fedora-objects
  protected $tracking_id; // format for the fedora-objects

  public function __construct() {
    webServiceServer::__construct('opensearch.ini');

    if (!$timeout = $this->config->get_value('curl_timeout', 'setup'))
      $timeout = 20;
    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, $timeout);

    define(DEBUG_ON, $this->debug);
  }

  /**
      \brief Handles the request and set up the response
  */

  public function search($param) {
    // set some defines
    $this->tracking_id = verbose::set_tracking_id('os', $param->trackingId->_value);
    if (!$this->aaa->has_right('opensearch', 500)) {
      $ret_error->searchResponse->_value->error->_value = 'authentication_error';
      return $ret_error;
    }
    define('WSDL', $this->config->get_value('wsdl', 'setup'));
    define('MAX_COLLECTIONS', $this->config->get_value('max_collections', 'setup'));

    // check for unsupported stuff
    $ret_error->searchResponse->_value->error->_value = &$unsupported;
    if ($param->format->_value == 'short') {
      $unsupported = 'Error: format short is not supported';
    }
    if ($param->format->_value == 'full') {
      $unsupported = 'Error: format full is not supported';
    }
    if (empty($param->query->_value)) {
      $unsupported = 'Error: No query found in request';
    }
    $repositories = $this->config->get_value('repository', 'setup');
    if (empty($param->repository->_value)) {
      $repository_name = $this->config->get_value('default_repository', 'setup');
      $this->repository = $repositories[$repository_name];
    }
    else {
      $repository_name = $param->repository->_value;
      if (!$this->repository = $repositories[$param->repository->_value]) {
        $unsupported = 'Error: Unknown repository: ' . $param->repository->_value;
      }
    }
    define(FEDORA_VER_2, $this->repository['work_format'] == 2);

// for testing and group all
    if (count($this->aaa->aaa_ip_groups) == 1 && $this->aaa->aaa_ip_groups['all']) {
      $param->agency->_value = '100200';
      $param->profile->_value = 'test';
    }
    $this->search_profile_version = $this->repository['search_profile_version'];
    if (empty($param->agency->_value) && empty($param->profile->_value)) {
      $param->agency->_value = $this->config->get_value('agency_fallback', 'setup');
      $param->profile->_value = $this->config->get_value('profile_fallback', 'setup');
    }
    if (empty($param->agency->_value)) {
      $unsupported = 'Error: No agency in request';
    }
    elseif (empty($param->profile->_value)) {
      $unsupported = 'Error: No profile in request';
    }
    elseif (!($this->search_profile = $this->fetch_profile_from_agency($param->agency->_value, $param->profile->_value, $this->search_profile_version))) {
      $unsupported = 'Error: Cannot fetch profile: ' . $param->profile->_value .
                     ' for ' . $param->agency->_value;
    }
    if ($unsupported) return $ret_error;
    $filter_agency = $this->set_solr_filter($this->search_profile, $this->search_profile_version);

/// TEST 
//    if (FEDORA_VER_2) {
//      $filter_relations = $this->check_valid_relation('a', 'b', 'c', $this->search_profile);
//    }

    $use_work_collection = ($param->collectionType->_value <> 'manifestation');
    if (($rr = $param->userDefinedRanking) || ($rr = $param->userDefinedBoost->_value->userDefinedRanking)) {
      $rank = 'rank';
      $rank_user['tie'] = $rr->_value->tieValue->_value;

      if (is_array($rr->_value->rankField)) {
        foreach ($rr->_value->rankField as $rf) {
          $boost_type = ($rf->_value->fieldType->_value == 'word' ? 'word_boost' : 'phrase_boost');
          $rank_user[$boost_type][$rf->_value->fieldName->_value] = $rf->_value->weight->_value;
          $rank .= '_' . $boost_type . '-' . $rf->_value->fieldName->_value . '-' . $rf->_value->weight->_value;
        }
      }
      else {
        $boost_type = ($rr->_value->rankField->_value->fieldType->_value == 'word' ? 'word_boost' : 'phrase_boost');
        $rank_user[$boost_type][$rr->_value->rankField->_value->fieldName->_value] = $rr->_value->rankField->_value->weight->_value;
        $rank .= '_' . $boost_type . '-' . $rr->_value->rankField->_value->fieldName->_value . '-' . $rr->_value->rankField->_value->weight->_value;
      }
      // make sure anyIndexes will be part of the dismax-search
      if (empty($rank_user['word_boost']['cql.anyIndexes'])) $rank_user['word_boost']['cql.anyIndexes'] = 1;
      if (empty($rank_user['phrase_boost']['cql.anyIndexes'])) $rank_user['phrase_boost']['cql.anyIndexes'] = 1;
      $rank_type[$rank] = $rank_user;
    }
    elseif ($sort = $param->sort->_value) {
      $sort_type = $this->config->get_value('sort', 'setup');
      if (!isset($sort_type[$sort])) $unsupported = 'Error: Unknown sort: ' . $sort;
    }
    elseif (($rank = $param->rank->_value) || ($rank = $param->userDefinedBoost->_value->rank->_value)) {
      $rank_type = $this->config->get_value('rank', 'setup');
      if (!isset($rank_type[$rank])) $unsupported = 'Error: Unknown rank: ' . $rank;
    }

    if (($boost_str = $this->boostUrl($param->userDefinedBoost->_value->boostField)) && empty($rank)) {
      $rank_type = $this->config->get_value('rank', 'setup');
      $rank = 'rank_none';
    }

    $format = $this->set_format($param->objectFormat, $this->config->get_value('open_format', 'setup'));
    
    if ($unsupported) return $ret_error;

    /**
        pjo 31-08-10
        If source is set and equals 'bibliotekdk' use bib_zsearch_class to zsearch for records
    */
    if ($param->source->_value == 'bibliotekdk') {
      require_once('bib_zsearch_class.php');

      $this->watch->start('bibdk_search');
      $bib_search = new bib_zsearch($this->config,$this->watch);
      $response = $bib_search->response($param);
      $this->watch->stop('bibdk_search');

      return $response;
    }

    /**
    *  Approach
    *  a) Do the solr search and fetch enough fedoraPids in result
    *  b) Fetch a fedoraPids work-object unless the record has been found
    *     in an earlier handled work-objects
    *  c) Collect fedoraPids in this work-object
    *  d) repeat b. and c. until the requeste number of objects is found
    *  e) if allObject is not set, do a new search combined the users search
    *     with an or'ed list of the fedoraPids in the active objects and
    *     remove the fedoraPids not found in the result
    *  f) Read full records fom fedora for objects in result
    *
    *  if $use_work_collection is FALSE skip b) to e)
    */

    $ret_error->searchResponse->_value->error->_value = &$error;

    $this->watch->start('Solr');
    $start = $param->start->_value;
    if (empty($start) && $step_value) {
      $start = 1;
    }
    $step_value = min($param->stepValue->_value, MAX_COLLECTIONS);
    $use_work_collection |= $sort_type[$sort] == 'random';
    $key_relation_cache = md5($param->query->_value . $repository_name . $filter_agency .
                              $use_work_collection .  $sort . $rank . $boost_str . $this->version);

    if ($param->queryLanguage->_value <> 'cqldan') {
      $param->queryLanguage->_value = 'cqleng';
    }
    $this->cql2solr = new cql2solr('opensearch_cql.xml', $this->config, $param->queryLanguage->_value);
    // urldecode ???? $query = $this->cql2solr->convert(urldecode($param->query->_value));
    // ' is handled differently in indexing and searching, so remove it until this is solved
    $query = $this->cql2solr->convert(str_replace("'", '', $param->query->_value), $rank_type[$rank]);
    $solr_query = $this->cql2solr->edismax_convert($param->query->_value, $rank_type[$rank]);
//print_r($query);
//print_r($solr_query);
//die('test');
    //$query = $this->cql2solr->convert($param->query->_value, $rank_type[$rank]);
    if (!$solr_query['operands']) {
      $error = 'Error: No query found in request';
      return $ret_error;
    }
    if ($sort) {
      $sort_q = '&sort=' . urlencode($sort_type[$sort]);
    }
    if ($rank_type[$rank]) {
      $rank_qf = $this->cql2solr->make_boost($rank_type[$rank]['word_boost']);
      $rank_pf = $this->cql2solr->make_boost($rank_type[$rank]['phrase_boost']);
      $rank_tie = $rank_type[$rank]['tie'];
      $rank_q = '&qf=' . urlencode($rank_qf) .  '&pf=' . urlencode($rank_pf) .  '&tie=' . $rank_tie;
    }

    if ($filter_agency) {
      $filter_q = rawurlencode($filter_agency);
    }

    //if (FEDORA_VER_2) $filter_q = '';

    $rows = ($start + $step_value + 100) * 2;
    if ($param->facets->_value->facetName) {
      $facet_q .= '&facet=true&facet.limit=' . $param->facets->_value->numberOfTerms->_value;
      if (is_array($param->facets->_value->facetName)) {
        foreach ($param->facets->_value->facetName as $facet_name) {
          $facet_q .= '&facet.field=' . $facet_name->_value;
        }
      }
      else
        $facet_q .= '&facet.field=' . $param->facets->_value->facetName->_value;
    }

    verbose::log(TRACE, 'CQL to SOLR: ' . $param->query->_value . ' -> ' . $solr_query['edismax']);
    if ($solr_query['edismax'])
      verbose::log(TRACE, 'CQL to EDISMAX: ' . $param->query->_value . ' -> ' . $solr_query['edismax']);

    $debug_query = $this->xs_boolean($param->queryDebug->_value);

    // do the query
    $search_ids = array();
    if ($sort == 'random') {
      if ($err = $this->get_solr_array($solr_query['edismax'], 0, 0, '', '', $facet_q, $filter_q, '', $debug_query, $solr_arr))
        $error = $err;
    }
    else {
      if ($err = $this->get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, $facet_q, $filter_q, $boost_str, $debug_query, $solr_arr))
        $error = $err;
      else {
        foreach ($solr_arr['response']['docs'] as $fdoc) {
          if (FEDORA_VER_2) {
            $uid = $fdoc['unit.id'][0];
            //$local_data[$uid] = $fdoc['rec.collectionIdentifier'];
            $search_ids[] = $uid;
          } else
            $search_ids[] = $fdoc['fedoraPid'];
        }
      }
    }
    $this->watch->stop('Solr');

    if ($error) return $ret_error;

    if ($debug_query) {
      $debug_result->rawQueryString->_value = $solr_arr['debug']['rawquerystring'];
      $debug_result->queryString->_value = $solr_arr['debug']['querystring'];
      $debug_result->parsedQuery->_value = $solr_arr['debug']['parsedquery'];
      $debug_result->parsedQueryString->_value = $solr_arr['debug']['parsedquery_toString'];
    }
    $numFound = $solr_arr['response']['numFound'];
    $facets = $this->parse_for_facets($solr_arr['facet_counts']);

    $this->watch->start('Build_id');
    $work_ids = $used_search_fids = array();
    if ($sort == 'random') {
      $rows = min($step_value, $numFound);
      $more = $step_value < $numFound;
      for ($w_idx = 0; $w_idx < $rows; $w_idx++) {
        do {
          $no = rand(0, $numFound-1);
        }
        while (isset($used_search_fid[$no]));
        $used_search_fid[$no] = TRUE;
        $this->get_solr_array($solr_query['edismax'], $no, 1, '', '', '', $filter_q, '', $debug_query, $solr_arr);
        if (FEDORA_VER_2) {
          $uid = $solr_arr['response']['docs'][0]['unit.id'];
          //$local_data[$uid] = $solr_arr['response']['docs']['rec.collectionIdentifier'];
          $work_ids[] = array($uid);
        } else
          $work_ids[] = array($solr_arr['response']['docs'][0]['fedoraPid']);
      }
    }
    else {
      $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                               $this->config->get_value('cache_port', 'setup'),
                               $this->config->get_value('cache_expire', 'setup'));
      if (empty($_GET['skipCache'])) {
        if ($relation_cache = $this->cache->get($key_relation_cache)) {
          verbose::log(STAT, 'Cache hit, lines: ' . count($relation_cache));
        }
        else {
          verbose::log(STAT, 'Cache miss');
        }
      }

      $w_no = 0;

      if (DEBUG_ON) print_r($search_ids);
      //if (DEBUG_ON) print_r($local_data);

      for ($s_idx = 0; isset($search_ids[$s_idx]); $s_idx++) {
        $fpid = &$search_ids[$s_idx];
        if (!isset($search_ids[$s_idx+1]) && count($search_ids) < $numFound) {
          $this->watch->start('Solr_add');
          verbose::log(FATAL, 'To few search_ids fetched from solr. Query: ' . $solr_query['edismax']);
          $rows *= 2;
          if ($err = $this->get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, '', $filter_q, $boost_str, $debug_query, $solr_arr)) {
            $error = $err;
            return $ret_error;
          }
          else {
            $search_ids = array();
            foreach ($solr_arr['response']['docs'] as $fdoc) {
              if (FEDORA_VER_2) {
                $uid = $fdoc['unit.id'][0];
                //$local_data[$uid] = $fdoc['rec.collectionIdentifier'];
                $search_ids[] = $uid;
              } else
                $search_ids[] = $fdoc['fedoraPid'];
            }
            $numFound = $solr_arr['response']['numFound'];
          }
          $this->watch->stop('Solr_add');
        }
        if (FALSE && FEDORA_VER_2) {
          $this->get_fedora_rels_ext($fpid, $unit_result);
          $unit_id = $this->parse_rels_for_unit_id($unit_result);
          if (DEBUG_ON) echo 'UR: ' . $fpid . ' -> ' . $unit_id . "\n";
          $fpid = $unit_id;
        }
        if ($used_search_fids[$fpid]) continue;
        if (count($work_ids) >= $step_value) {
          $more = TRUE;
          break;
        }

        $w_no++;
        // find relations for the record in fedora
        // fpid: id as found in solr's fedoraPid
        if ($relation_cache[$w_no]) {
          $fpid_array = $relation_cache[$w_no];
        }
        else {
          if ($use_work_collection) {
            $this->watch->start('get_w_id');
            $this->get_fedora_rels_ext($fpid, $record_rels_ext);
            /* ignore the fact that there is no RELS_EXT datastream
            */
            $this->watch->stop('get_w_id');
            if (DEBUG_ON) echo 'RR: ' . $record_rels_ext . "\n";

            if ($work_id = $this->parse_rels_for_work_id($record_rels_ext)) {
              // find other recs sharing the work-relation
              $this->watch->start('get_fids');
              $this->get_fedora_rels_ext($work_id, $work_rels_ext);
              if (DEBUG_ON) echo 'WR: ' . $work_rels_ext . "\n";
              $this->watch->stop('get_fids');
              if (!$fpid_array = $this->parse_work_for_object_ids($work_rels_ext, $fpid)) {
                verbose::log(FATAL, 'Fedora fetch/parse work-record: ' . $work_id . ' refered from: ' . $fpid);
                $fpid_array = array($fpid);
              }
              if (DEBUG_ON) {
                echo 'fid: ' . $fpid . ' -> ' . $work_id . " -> object(s):\n";
                print_r($fpid_array);
              }
            }
            else
              $fpid_array = array($fpid);
          }
          else
            $fpid_array = array($fpid);
          $relation_cache[$w_no] = $fpid_array;
        }
        if (DEBUG_ON) print_r($fpid_array);

        foreach ($fpid_array as $id) {
          $used_search_fids[$id] = TRUE;
          if ($w_no >= $start)
            $work_ids[$w_no][] = $id;
        }
        if ($w_no >= $start)
          $work_ids[$w_no] = $fpid_array;
      }
    }

    if (count($work_ids) < $step_value && count($search_ids) < $numFound) {
      verbose::log(FATAL, 'To few search_ids fetched from solr. Query: ' . $solr_query['edismax']);
    }

    // check if the search result contains the ids
    // allObject=0 - remove objects not included in the search result
    // allObject=1 & agency - remove objects not included in agency
    //
    // split into multiple solr-searches each containing slightly less than 1000 elements
    define('MAX_QUERY_ELEMENTS', 950);
    $block_idx = $no_bool = 0;
    if (DEBUG_ON) echo 'work_ids: ' . print_r($work_ids, TRUE) . "\n";
    if ($use_work_collection && ($this->xs_boolean($param->allObjects->_value) || $filter_agency)) {
      $add_query[$block_idx] = '';
      foreach ($work_ids as $w_no => $w) {
        if (count($w) > 1) {
          if ($add_query[$block_idx] && ($no_bool + count($w)) > MAX_QUERY_ELEMENTS) {
            $block_idx++;
            $no_bool = 0;
          }
          foreach ($w as $id) {
            $add_query[$block_idx] .= (empty($add_query[$block_idx]) ? '' : ' OR ') . $id;
            $no_bool++;
          }
        }
      }
      if (!empty($add_query[0]) || count($add_query) > 1) {    // use post here because query can be very long
        if (FEDORA_VER_2) 
          $which_rec_id = 'unit.id';
        else
          $which_rec_id = 'rec.id';
        foreach ($add_query as $add_idx => $add_q) {
          if (!$this->xs_boolean($param->allObjects->_value)) {
            $chk_query = $this->cql2solr->edismax_convert('(' . $param->query->_value . ') AND ' . $which_rec_id . '=(' . $add_q . ')', $rank_type[$rank]);
            $q = $chk_query['edismax'];
          }
          elseif ($filter_agency) {
            $chk_query = $this->cql2solr->edismax_convert($which_rec_id . '=(' . $add_q . ')');
            $q = $chk_query['edismax'];
          }
          else {
            verbose::log(FATAL, 'Internal problem: Assert error. Line: ' . __LINE__);
            $error = 'Internal problem: Assert error. Line: ' . __LINE__;
            return $ret_error;
          }
          // need to remove unwanted object from work_ids
          $post_array = array('wt' => 'phps',
                              'q' => $q,
                              'fq' => urldecode($filter_q),
                              'start' => '0',
                              'rows' => '50000',
                              'defType' => 'edismax',
                              'fl' => 'fedoraPid,unit.id');
          if ($rank_qf) $post_array['qf'] = $rank_qf;
          if ($rank_pf) $post_array['pf'] = $rank_pf;
          if ($rank_tie) $post_array['tie'] = $rank_tie;

          if (DEBUG_ON) {
            echo 'post_array: ' . $this->repository['solr'];
            foreach ($post_array as $pk => $pv)
              echo '&' . $pk . '=' . urlencode($pv);
            echo "/n";
          }

          $this->curl->set_post($post_array);
          $this->watch->start('Solr 2');
          $solr_result = $this->curl->get($this->repository['solr']);
          $this->curl->set_option(CURLOPT_POST, 0);  // remember to clear POST
          $this->watch->stop('Solr 2');
          if (!($solr_2_arr[$add_idx] = unserialize($solr_result))) {
            verbose::log(FATAL, 'Internal problem: Cannot decode Solr re-search');
            $error = 'Internal problem: Cannot decode Solr re-search';
            return $ret_error;
          }
        }
        foreach ($work_ids as $w_no => $w_list) {
          if (count($w_list) > 1) {
            $hit_fid_array = array();
            foreach ($w_list as $w) {
              foreach ($solr_2_arr as $s_2_a) {
                foreach ($s_2_a['response']['docs'] as $fdoc) {
                  if (FEDORA_VER_2) 
                    $p_id = &$fdoc['unit.id'][0];
                  else
                    $p_id = &$fdoc['fedoraPid'];
                  if ($p_id == $w) {
                    $hit_fid_array[] = $w;
                    break 2;
                  }
                }
              }
            }
            $work_ids[$w_no] = $hit_fid_array;
          }
        }
      }
      if (DEBUG_ON) echo 'work_ids after research: ' . print_r($work_ids, TRUE) . "\n";
    }

    if (DEBUG_ON) echo 'txt: ' . $txt . "\n";
    if (DEBUG_ON) echo 'solr_2_arr: ' . print_r($solr_2_arr, TRUE) . "\n";
    if (DEBUG_ON) echo 'add_query: ' . print_r($add_query, TRUE) . "\n";
    if (DEBUG_ON) echo 'used_search_fids: ' . print_r($used_search_fids, TRUE) . "\n";

    $this->watch->stop('Build_id');

    if ($this->cache)
      $this->cache->set($key_relation_cache, $relation_cache);

    $missing_record = $this->config->get_value('missing_record', 'setup');

    // work_ids now contains the work-records and the fedoraPids they consist of
    // now fetch the records for each work/collection
    $this->watch->start('get_recs');
    $collections = array();
    $rec_no = max(1, $start);
    foreach ($work_ids as $work) {
      $objects = array();
      foreach ($work as $fpid) {
        if ($param->collectionType->_value <> 'work-1' || empty($objects)) {
          if (FEDORA_VER_2) {
            $this->get_fedora_rels_addi($fpid, $fedora_addi_relation);
            $this->get_fedora_rels_ext($fpid, $unit_rels_ext);
            list($fpid, $unit_members) = $this->parse_unit_for_object_ids($unit_rels_ext);
            if ($this->xs_boolean($param->includeHoldingsCount->_value)) {
              $no_of_holdings = $unit_members + $this->get_solr_holdings($fpid);
            }
          }
          if ($error = $this->get_fedora_raw($fpid, $fedora_result)) {
// fetch empty record from ini-file and use instead of error
            if ($missing_record) {
              $error = NULL;
              $fedora_result = sprintf($missing_record, $fpid);
            }
            else {
              return $ret_error;
            }
          }
          if ($debug_query) {
            unset($explain);
            foreach ($solr_arr['response']['docs'] as $solr_idx => $solr_rec) {
              if ($fpid == $solr_rec['fedoraPid']) {
                //$strange_idx = $solr_idx ? ' '.$solr_idx : '';
                $explain = $solr_arr['debug']['explain'][$fpid];
                break;
              }
            }

          }
          $objects[]->_value =
            $this->parse_fedora_object($fedora_result,
                                       $fedora_addi_relation,
                                       $param->relationData->_value,
                                       $fpid,
                                       NULL, // no $filter_agency on search - bad performance
                                       $format,
                                       $no_of_holdings,
                                       $explain);
        }
        else
          $objects[]->_value = NULL;
        // else $objects[]->_value = NULL;
      }
      $o->collection->_value->resultPosition->_value = $rec_no++;
      $o->collection->_value->numberOfObjects->_value = count($objects);
      $o->collection->_value->object = $objects;
      $collections[]->_value = $o;
      unset($o);
    }
    $this->watch->stop('get_recs');

    if ($format['found_open_format']) {
      $this->format_records($collections, $format);
    }

    if ($_REQUEST['work'] == 'debug') {
      echo "returned_work_ids: \n";
      print_r($work_ids);
      echo "cache: \n";
      print_r($relation_cache);
      die();
    }
    //if (DEBUG_ON) { print_r($relation_cache); die(); }
    //if (DEBUG_ON) { print_r($collections); die(); }
    //if (DEBUG_ON) { print_r($solr_arr); die(); }

    $result = &$ret->searchResponse->_value->result->_value;
    $result->hitCount->_value = $numFound;
    $result->collectionCount->_value = count($collections);
    $result->more->_value = ($more ? 'true' : 'false');
    $result->searchResult = $collections;
    $result->facetResult->_value = $facets;
    $result->queryDebugResult->_value = $debug_result;
    $result->time->_value = $this->watch->splittime('Total');

    //print_r($collections[0]);
    //exit;

    return $ret;
  }


  /** \brief Get an object in a specific format
  *
  * param: agency: 
  *        profile:
  *        identifier - fedora pid
  *        objectFormat - one of dkabm, docbook, marcxchange, opensearchobject
  *        includeHoldingsCount - boolean
  *        relationData - type, uri og full
  *        repository
  */
  public function getObject($param) {
    $this->tracking_id = verbose::set_tracking_id('os', $param->trackingId->_value);
    $ret_error->searchResponse->_value->error->_value = &$error;
    if (!$this->aaa->has_right('opensearch', 500)) {
      $error = 'authentication_error';
      return $ret_error;
    }
    $repositories = $this->config->get_value('repository', 'setup');
    if (empty($param->repository->_value)) {
      $this->repository = $repositories[$this->config->get_value('default_repository', 'setup')];
    }
    elseif (!$this->repository = $repositories[$param->repository->_value]) {
      $error = 'Error: Unknown repository: ' . $param->repository->_value;
      verbose::log(FATAL, $error);
      return $ret_error;
    }
    if (empty($param->agency->_value) && empty($param->profile->_value)) {
      $param->agency->_value = $this->config->get_value('agency_fallback', 'setup');
      $param->profile->_value = $this->config->get_value('profile_fallback', 'setup');
    }
    $this->search_profile_version = $this->repository['search_profile_version'];
    if ($agency = $param->agency->_value) {
      if ($param->profile->_value) {
        if (!($this->search_profile = $this->fetch_profile_from_agency($agency, $param->profile->_value, $this->search_profile_version))) {
          $error = 'Error: Cannot fetch profile: ' . $param->profile->_value . ' for ' . $agency;
          return $ret_error;
        }
      }
      else
        $agencies = $this->config->get_value('agency', 'agency');
      $agencies[$agency] = $this->set_solr_filter($this->search_profile, $this->search_profile_version);
      if (isset($agencies[$agency]))
        $filter_agency = $agencies[$agency];
      else {
        $error = 'Error: Unknown agency: ' . $agency;
        return $ret_error;
      }
    }

    $format = $this->set_format($param->objectFormat, $this->config->get_value('open_format', 'setup'));

    $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                             $this->config->get_value('cache_port', 'setup'),
                             $this->config->get_value('cache_expire', 'setup'));
    $fpid = $param->identifier->_value;
    if ($this->deleted_object($fpid)) {
      $error = 'Error: deleted record: ' . $fpid;
      return $ret_error;
    }
    if ($error = $this->get_fedora_raw($fpid, $fedora_result))
      return $ret_error;
// 2DO 
// relations are now on the unit, so this has to be found
    if ($param->relationData->_value || $this->xs_boolean($param->includeHoldingsCount->_value)) {
      $this->get_fedora_rels_ext($fpid, $fedora_rels_ext);
      $unit_id = $this->parse_rels_for_unit_id($fedora_rels_ext);
      if ($param->relationData->_value) {
        $this->get_fedora_rels_addi($unit_id, $fedora_addi_relation);
      }
      if ($this->xs_boolean($param->includeHoldingsCount->_value)) {
        $this->get_fedora_rels_ext($unit_id, $unit_rels_ext);
        list($dummy, $no_of_holdings) = $this->parse_unit_for_object_ids($unit_rels_ext);
        $this->cql2solr = new cql2solr('opensearch_cql.xml', $this->config);
        $no_of_holdings += $this->get_solr_holdings($fpid);
      }
    }
//var_dump($fedora_rels_ext);
//var_dump($unit_id);
//var_dump($fedora_addi_relation);
//die();
    $o->collection->_value->resultPosition->_value = 1;
    $o->collection->_value->numberOfObjects->_value = 1;
    $o->collection->_value->object[]->_value =
      $this->parse_fedora_object($fedora_result,
                                 $fedora_addi_relation,
                                 $param->relationData->_value,
                                 $fpid,
                                 $filter_agency,
                                 $format,
                                 $no_of_holdings);
    $collections[]->_value = $o;

// FVS format
    if ($format['found_open_format']) {
      $this->format_records($collections, $format);
    }

    $result = &$ret->searchResponse->_value->result->_value;
    $result->hitCount->_value = 1;
    $result->collectionCount->_value = count($collections);
    $result->more->_value = 'false';
    $result->searchResult = $collections;
    $result->facetResult->_value = '';
    $result->time->_value = $this->watch->splittime('Total');

    //print_r($param);
    //print_r($fedora_result);
    //print_r($objects);
    //print_r($ret); die();
    return $ret;
  }

  /*******************************************************************************/

  private function set_format($objectFormat, $open_format) {
    if (is_array($objectFormat))
      $help = $objectFormat;
    elseif (empty($objectFormat->_value))
      $help[]->_value = 'dkabm';
    else
      $help[] = $objectFormat;
    foreach ($help as $of) {
      if ($open_format[$of->_value]) {
        $ret[$of->_value] = array('user_selected' => TRUE, 'is_open_format' => TRUE, 'format_name' => $open_format[$of->_value]['format']);
        $ret['found_open_format'] = TRUE;
      }
      else {
        $ret[$of->_value] = array('user_selected' => TRUE, 'is_open_format' => FALSE);
      }
    }
    if ($ret['found_open_format']) {
      if (empty($ret['dkabm']))
        $ret['dkabm'] = array('user_selected' => FALSE, 'is_open_format' => FALSE);
      if (empty($ret['marcxchange']))
        $ret['marcxchange'] = array('user_selected' => FALSE, 'is_open_format' => FALSE);
    }
    return $ret;
  }

  /** \brief
   *
   */
  private function get_solr_holdings($pid) {
    $holds = 0;
    $q = $this->cql2solr->edismax_convert('rec.id=' . $pid);
    $solr_query = $this->repository['solr'] . 
                    '?q=' . $q['edismax'] .
                    '&rows=1&fl=ols.holdingsCount&defType=edismax&wt=phps';

    $this->watch->start('solr_holdings');
    $solr_result = $this->curl->get($solr_query);
    $this->watch->stop('solr_holdings');
    if (($solr_result) && ($solr_arr = unserialize($solr_result)))
      $holds = intval($solr_arr['response']['docs'][0]['ols.holdingsCount'][0]);

    if ($holds) $holds--;

    return $holds;
  }

  /** \brief
   *
   */
  private function format_records(&$collections, $format) {
    $this->watch->start('format');
    foreach ($format as $format_name => $format_arr) {
      if ($format_arr['is_open_format']) {
        $f_obj->formatRequest->_namespace = $this->xmlns['of'];
        $f_obj->formatRequest->_value->originalData = $collections;
        foreach ($f_obj->formatRequest->_value->originalData as $i => $o)
          $f_obj->formatRequest->_value->originalData[$i]->_namespace = $this->xmlns['of'];
        $f_obj->formatRequest->_value->outputFormat->_namespace = $this->xmlns['of'];
        $f_obj->formatRequest->_value->outputFormat->_value = $format_arr['format_name'];
        $f_obj->formatRequest->_value->outputType->_namespace = $this->xmlns['of'];
        $f_obj->formatRequest->_value->outputType->_value = 'php';
        $f_obj->formatRequest->_value->trackingId->_value = $this->tracking_id;
        $f_xml = $this->objconvert->obj2soap($f_obj);
        $this->curl->set_post($f_xml);
        $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: text/xml; charset=UTF-8'));
        $open_format_uri = $this->config->get_value('ws_open_format_uri', 'setup');
        $f_result = $this->curl->get($open_format_uri);
        //$fr_obj = unserialize($f_result);
        $fr_obj = $this->objconvert->set_obj_namespace(unserialize($f_result), $this->xmlns['']);
        if (!$fr_obj) {
          $curl_err = $this->curl->get_status();
          verbose::log(FATAL, 'openFormat http-error: ' . $curl_err['http_code'] . ' from: ' . $open_format_uri);
        }
        else {
          $struct = key($fr_obj->formatResponse->_value);
          foreach ($collections as $idx => &$c) {
            $c->_value->formattedCollection->_value->{$struct} = $fr_obj->formatResponse->_value->{$struct}[$idx];
          }
        }
      }
    }
    foreach ($collections as $idx => &$c) {
  // remove unwanted structures
      foreach ($c->_value->collection->_value->object as &$o) {
        if (!$format['dkabm']['user_selected'])
          unset($o->_value->record);
        if (!$format['marcxchange']['user_selected'])
          unset($o->_value->collection);
      }
    }
    $this->watch->stop('format');
  }

  /** \brief
   *
   */
  private function deleted_object($fpid) {
    static $dom;
    $state = '';
    if ($obj_url = $this->repository['fedora_get_object_profile']) {
      $this->get_fedora($obj_url, $fpid, $obj_rec);
      if ($obj_rec) {
        if (empty($dom))
          $dom = new DomDocument();
        $dom->preserveWhiteSpace = false;
        if (@ $dom->loadXML($obj_rec))
          $state = $dom->getElementsByTagName('objState')->item(0)->nodeValue;
      }
    }
    return $state == "D";
  }

  /** \brief
   *
   */
  private function get_fedora_raw($fpid, &$fedora_rec) {
    return $this->get_fedora($this->repository['fedora_get_raw'], $fpid, $fedora_rec);
  }

  /** \brief
   *
   */
  private function get_fedora_rels_addi($fpid, &$fedora_rel) {
    if ($this->repository['fedora_get_rels_addi']) {
      return $this->get_fedora($this->repository['fedora_get_rels_addi'], $fpid, $fedora_rel, FALSE);
    }
    else {
      return FALSE;
    }
  }

  /** \brief
   *
   */
  private function get_fedora_rels_ext($fpid, &$fedora_rel) {
    return $this->get_fedora($this->repository['fedora_get_rels_ext'], $fpid, $fedora_rel);
  }

  /** \brief
   *
   */
  private function get_fedora_datastreams($fpid, &$fedora_streams) {
    return $this->get_fedora($this->repository['fedora_get_datastreams'], $fpid, $fedora_streams);
  }

  /** \brief
   *
   */
  private function get_fedora($uri, $fpid, &$rec, $mandatory=TRUE) {
    $record_uri =  sprintf($uri, $fpid);
    if (DEBUG_ON) echo 'Fetch record: /' . $record_uri . "/\n";
    if (!$this->cache || !$rec = $this->cache->get($record_uri)) {
      $this->curl->set_authentication('fedoraAdmin', 'fedoraAdmin');
      $this->watch->start('fedora');
      $rec = $this->curl->get($record_uri);
      $this->watch->stop('fedora');
      $curl_err = $this->curl->get_status();
      if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
        $rec = '';
        if ($mandatory) {
          verbose::log(FATAL, 'Fedora http-error: ' . $curl_err['http_code'] . ' from: ' . $record_uri);
          return 'Error: Cannot fetch record: ' . $fpid . ' - http-error: ' . $curl_err['http_code'];
        }
      }
      if ($this->cache) $this->cache->set($record_uri, $rec);
    }
    // else verbose::log(STAT, 'Fedora cache hit for ' . $fpid);
    return;
  }

  /** \brief Build Solr filter_query parm
   *
   */
  private function set_solr_filter($profile, $profile_version) {
    $ret = '';
    foreach ($profile as $p) {
      if ($profile_version == 3) {
        if ($this->xs_boolean($p['sourceSearchable']))
          $ret .= ($ret ? ' OR ' : '') .
                  'rec.collectionIdentifier:' . $p['sourceIdentifier'];
      }
      else
        $ret .= ($ret ? ' OR ' : '') .
                '(submitter:' . $p['sourceOwner'] .  
                ' AND original_format:' . $p['sourceFormat'] . ')';
    }
    return $ret;
  }

  /** \brief Check a relation against the search_profile
   *
   */
  private function check_valid_relation($from_id, $to_id, $relation, &$profile) {
    static $rels, $source;
    if (!isset($rels)) {
      $rel_from = $rel_to = array();
      foreach ($profile as $src) {
        $source[$src['sourceIdentifier']] = TRUE;
        if ($src['relation']) {
          foreach ($src['relation'] as $rel) {
            if ($rel['rdfLabel'])
              $rels[$src['sourceIdentifier']][$rel['rdfLabel']] = TRUE;
            if ($rel['rdfInverse'])
              $rels[$src['sourceIdentifier']][$rel['rdfInverse']] = TRUE;
          }
        }
      }

//      print_r($profile);
//      echo "rels:\n"; print_r($rels); echo "source:\n"; print_r($source);
    }
    if (substr($to_id, 0, 5) == 'unit:') {
      $this->get_fedora_rels_ext($to_id, $rels_sys);
      $to_id = $this->fetch_primary_bib_object($rels_sys);
    }
    $from = $this->kilde($from_id);
    $to = $this->kilde($to_id);
//    echo "from: $from to: $to relation: $relation \n";

    return (isset($rels[$to][$relation]));
  }

  private function kilde($id) {
    list($ret, $dummy) = explode(':', $id);
    return $ret;
  }

  /** \brief Fetch a profile $profile_name for agency $agency
   *
   */
  private function fetch_profile_from_agency($agency, $profile_name, $profile_version) {
    require_once 'OLS_class_lib/search_profile_class.php';
    if (!($host = $this->config->get_value('profile_cache_host', 'setup')))
      $host = $this->config->get_value('cache_host', 'setup');
    if (!($port = $this->config->get_value('profile_cache_port', 'setup')))
      $port = $this->config->get_value('cache_port', 'setup');
    if (!($expire = $this->config->get_value('profile_cache_expire', 'setup')))
      $expire = $this->config->get_value('cache_expire', 'setup');
    $profiles = new search_profiles($this->config->get_value('open_agency', 'setup'), $host, $port, $expire);
    $profile_version = ($profile_version ? intval($profile_version) : 2);
    $profile = $profiles->get_profile($agency, $profile_name, $profile_version);
    if (is_array($profile)) {
      return $profile;
    }
    else {
      return FALSE;
    }
  }

  /** \brief Build bq (BoostQuery) as field:content^weight
   *
   */
  public static function boostUrl($boost) {
    $ret = '';
    if ($boost) {
      $boosts = (is_array($boost) ? $boost : array($boost));
      foreach ($boosts as $bf) {
        $ret .= '&bq=' .
                urlencode($bf->_value->fieldName->_value . ':"' .
                          str_replace('"', '', $bf->_value->fieldValue->_value) . '"^' .
                          $bf->_value->weight->_value);
      }
    }
    return $ret;
  }

  /** \brief
   *
   * @param $q
   * @param $start
   * @param $rows
   * @param $sort
   * @param $facets
   * @param $filter
   * @param $boost
   * @param $debug
   * @param $solr_arr
   *
   */
  private function get_solr_array($q, $start, $rows, $sort, $rank, $facets, $filter, $boost, $debug, &$solr_arr) {
  // '&fl=' . (FEDORA_VER_2 ? 'unit.id,rec.collectionIdentifier' : 'fedoraPid') .
    $solr_query = $this->repository['solr'] . 
                    '?q=' . urlencode($q) . 
                    '&fq=' . $filter . 
                    '&start=' . $start . 
                    '&rows=' . $rows . $sort . $rank . $boost . $facets . 
                    ($debug ? '&debugQuery=on' : '') . 
                    '&fl=' . (FEDORA_VER_2 ? 'unit.id' : 'fedoraPid') . 
                    '&defType=edismax&wt=phps';

    //echo $solr_query;
    //exit;

    verbose::log(TRACE, 'Query: ' . $solr_query);
    verbose::log(DEBUG, 'Query: ' . $this->repository['solr'] . "?q=" . urlencode($q) . "&fq=$filter&start=$start&rows=1$sort$boost&fl=fedoraPid,unit.id$facets&defType=edismax&debugQuery=on");
    $solr_result = $this->curl->get($solr_query);
    if (empty($solr_result))
      return 'Internal problem: No answer from Solr';
    if (!$solr_arr = unserialize($solr_result))
      return 'Internal problem: Cannot decode Solr result';
  }

  /** \brief Parse a rels-ext record and extract the unit id
   *
   */
  private function parse_rels_for_unit_id($rels_ext) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    $dom->preserveWhiteSpace = false;
    if (@ $dom->loadXML($rels_ext)) {
      $imo = $dom->getElementsByTagName('isPrimaryBibObjectFor');
      if ($imo->item(0))
        return($imo->item(0)->nodeValue);
      else {
        $imo = $dom->getElementsByTagName('isMemberOfUnit');
        if ($imo->item(0))
          return($imo->item(0)->nodeValue);
      }
    }

    return FALSE;
  }

  /** \brief Parse a rels-ext record and extract the work id
   *
   */
  private function parse_rels_for_work_id($rels_ext) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    $dom->preserveWhiteSpace = false;
    if (@ $dom->loadXML($rels_ext))
      if (FEDORA_VER_2) {
        $imo = $dom->getElementsByTagName('isPrimaryUnitObjectFor');
        if ($imo->item(0))
          return($imo->item(0)->nodeValue);
        else {
          $imo = $dom->getElementsByTagName('isMemberOfWork');
          if ($imo->item(0))
            return($imo->item(0)->nodeValue);
        }
      }
      else {
        $imo = $dom->getElementsByTagName('isMemberOfWork');
        if ($imo->item(0))
          return($imo->item(0)->nodeValue);
      }

    return FALSE;
  }

  /** \brief Echos config-settings
   *
   */
  public function show_info() {
    echo '<pre>';
    echo 'version             ' . $this->config->get_value('version', 'setup') . '<br/>';
    echo 'agency              ' . $this->config->get_value('open_agency', 'setup') . '<br/>';
    echo 'aaa_credentials     ' . $this->strip_oci_pwd($this->config->get_value('aaa_credentials', 'aaa')) . '<br/>';
    echo 'default_repository  ' . $this->config->get_value('default_repository', 'setup') . '<br/>';
    echo 'repository          ' . print_r($this->config->get_value('repository', 'setup'), true) . '<br/>';
    echo '</pre>';
    die();
  }

  private function strip_oci_pwd($cred) {
    if (($p1 = strpos($cred, '/')) && ($p2 = strpos($cred, '@')))
      return substr($cred, 0, $p1) . '/********' . substr($cred, $p2);
    else
      return $cred;
  }

  /** \brief Fetch id for primaryBibObject
   *
   */
  private function fetch_primary_bib_object($u_rel) {
    $arr = $this-> parse_unit_for_object_ids($u_rel);
    return $arr[0];
  }

  /** \brief Parse a work relation and return array of ids
   *
   */
  private function parse_unit_for_object_ids($u_rel) {
    static $dom;
    $res = array();
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    $dom->preserveWhiteSpace = false;
    if (@ $dom->loadXML($u_rel)) {
      $res = array();
      $hmof = $dom->getElementsByTagName('hasMemberOfUnit');
      $hpbo = $dom->getElementsByTagName('hasPrimaryBibObject');
      if ($hpbo->item(0))
        return(array($hpbo->item(0)->nodeValue, $hmof->length));
      return array(FALSE, FALSE);
    }
  }

  /** \brief Parse a work relation and return array of ids
   *
   */
  private function parse_work_for_object_ids($w_rel, $fpid) {
    static $dom;
    $res = array();
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    $dom->preserveWhiteSpace = false;
    if (@ $dom->loadXML($w_rel)) {
      $res = array();
      $res[] = $fpid;
      if (FEDORA_VER_2) {
        //$hpuo = $dom->getElementsByTagName('hasPrimaryUnitObject');
        //if ($hpuo->item(0))
          //$res[] = $puo = $hpuo->item(0)->nodeValue;
        $r_list = $dom->getElementsByTagName('hasMemberOfWork');
        foreach ($r_list as $r) {
          if ($r->nodeValue <> $fpid) $res[] = $r->nodeValue;
        }
      }
      else {
        $r_list = $dom->getElementsByTagName('hasManifestation');
        foreach ($r_list as $r) {
          if ($r->nodeValue <> $fpid) $res[] = $r->nodeValue;
        }
      }
      return $res;
    }
  }

  /** \brief Parse a fedora object and extract record and relations
   *
   * @param $fedora_obj      - the bibliographic record from fedora
   * @param $fedora_rels_obj - corresponding relation object
   * @param $rels_type       - level for returning relations
   * @param $rec_id          - record id of the record
   * @param $filter          - agency filter
   * @param $format          -
   * @param $debug_info      -
   */
  private function parse_fedora_object(&$fedora_obj, &$fedora_rels_obj, $rels_type, $rec_id, $filter, $format, $holdings_count=NULL, $debug_info='') {
    static $dom, $rels_dom, $stream_dom, $allowed_relation;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = false;
    }
    if (@ !$dom->loadXML($fedora_obj)) {
      verbose::log(FATAL, 'Cannot load recid ' . $rec_id . ' into DomXml');
      return;
    }

    $rec = $this->extract_record($dom, $rec_id, $format);

    if (!isset($allowed_relation)) {
      $allowed_relation = $this->config->get_value('relation', 'setup');
    }
    if (empty($rels_dom)) {
      $rels_dom = new DomDocument();
    }

// Handle relations comming from local_data streams
// 2DO some testing to ensure this is only done when needed (asked for)
    $this->get_fedora_datastreams($rec_id, $fedora_streams);
    if (empty($stream_dom)) {
      $stream_dom = new DomDocument();
    }
    if (@ !$stream_dom->loadXML($fedora_streams)) {
      verbose::log(DEBUG, 'Cannot load STREAMS for ' . $rec_id . ' into DomXml');
    } else {
      if ($rels_type == 'type' || $rels_type == 'uri' || $rels_type == 'full') {
        foreach ($stream_dom->getElementsByTagName('datastream') as $node) {
          if (substr($node->getAttribute('ID'), 0, 9) == 'localData') {
            $dub_check = array();
            foreach ($node->getElementsByTagName('link') as $link) {
              $url = $link->getelementsByTagName('url')->item(0)->nodeValue;
              if (empty($dup_check[$url])) {
                unset($relation);
                $relation->relationType->_value = 
                      $link->getelementsByTagName('relationType')->item(0)->nodeValue;
                if ($rels_type == 'uri' || $rels_type == 'full') {
                  $relation->relationUri->_value = $url;
                  $relation->linkObject->_value->accessType->_value = 
                      $link->getelementsByTagName('accessType')->item(0)->nodeValue;
                  $relation->linkObject->_value->access->_value = 
                      $link->getelementsByTagName('access')->item(0)->nodeValue;
                  $relation->linkObject->_value->linkTo->_value = 
                      $link->getelementsByTagName('LinkTo')->item(0)->nodeValue;
                }
                $dup_check[$url] = TRUE;
                $relations->relation[]->_value = $relation;
                unset($relation);
              }
              //echo 'll: ' . $link->getelementsByTagName('LinkTo')->item(0)->nodeValue;
            }
          }
        }
      }
    }
        //    var_dump($local_links);
//echo "\nStream: " . $fedora_streams . "\n"; 
//echo "\nrels_addi: " . $fedora_rels_obj . "\n"; 

// Handle relations comming from RELS_EXT
// rels_ext is already in fedora_streams, so use that instead og fedora_rels_ext
    if (FEDORA_VER_2) {
      @ $rels_dom->loadXML($fedora_rels_obj);
    }
    else {
      $rels_dom = $stream_dom->getElementsByTagName('RDF')->item(0);
    }
    if ($rels_dom->getElementsByTagName('Description')->item(0)) {
      foreach ($rels_dom->getElementsByTagName('Description')->item(0)->childNodes as $tag) {
        if ($tag->nodeType == XML_ELEMENT_NODE) {
          if ($rel_prefix = array_search($tag->getAttribute('xmlns'), $this->xmlns))
            $this_relation = $rel_prefix . ':' . $tag->localName;
          else
            $this_relation = $tag->localName;
          $relation_type = $allowed_relation[$this_relation];
//echo "this_relation: $this_relation relation_type: $relation_type\n";
          //verbose::log(DEBUG,  "this_relation: $this_relation relation_type: $relation_type");
          if (FEDORA_VER_2 && $relation_type == 1) {
            if (! $this->check_valid_relation($rec_id, $tag->nodeValue, $this_relation, $this->search_profile))
              unset($relation_type);
          }
          if ($relation_type) {
            //verbose::log(DEBUG, $tag->localName . ' ' . $tag->getAttribute('xmlns'). ' -> ' .  array_search($tag->getAttribute('xmlns'), $this->xmlns));
            if ($rels_type == 'type' || $rels_type == 'uri' || $rels_type == 'full')
            if ($relation_type <> REL_TO_INTERNAL_OBJ || $this->is_searchable($tag->nodeValue, $filter)) {
              $relation->relationType->_value = $this_relation;
              if ($rels_type == 'uri' || $rels_type == 'full') {
                if (FEDORA_VER_2) {
                  $this->get_fedora_rels_ext($tag->nodeValue, $rels_sys);
                  $rel_uri = $this->fetch_primary_bib_object($rels_sys);
                }
                else {
                  $rel_uri = $tag->nodeValue;
                }
                $relation->relationUri->_value = $rel_uri;
              }
              if ($rels_type == 'full' && $relation_type == REL_TO_INTERNAL_OBJ) {
                //verbose::log(DEBUG, 'RFID: ' . $tag->nodeValue);
                $this->get_fedora_raw($rel_uri, $related_obj);
                if (@ !$rels_dom->loadXML($related_obj)) {
                  verbose::log(FATAL, 'Cannot load ' . $rel_uri . ' object for ' . $rec_id . ' into DomXml');
                }
                else {
                  $rel_obj = &$relation->relationObject->_value->object->_value;
                  $rel_obj = $this->extract_record($rels_dom, $tag->nodeValue, $format);
                  $rel_obj->identifier->_value = $rel_uri;
                  $rel_obj->creationDate->_value = $this->get_creation_date($rels_dom);
                  $rel_obj->formatsAvailable->_value = $this->scan_for_formats($rels_dom);
                }
              }
              if ($rels_type == 'type' || $relation->relationUri->_value) {
                $relations->relation[]->_value = $relation;
              }
              unset($relation);
            }
          }
        }
      }  // foreach ...
    }

    $ret = $rec;
    $ret->identifier->_value = $rec_id;
    $ret->creationDate->_value = $this->get_creation_date($dom);
    if (isset($holdings_count)) 
      $ret->holdingsCount->_value = $holdings_count;
    if ($relations) $ret->relations->_value = $relations;
    $ret->formatsAvailable->_value = $this->scan_for_formats($dom);
    if ($debug_info) $ret->queryResultExplanation->_value = $debug_info;
    if (DEBUG_ON) var_dump($ret);

    //print_r($ret);
    //exit;

    return $ret;
  }

  /** \brief Check if a record is searchable
   *
   */
  private function is_searchable($rec_id, $filter_q) {
// do not check for searchability, since the relation is found in the search_profile, it's ok to use it
    return TRUE;
    if (empty($filter_q)) return TRUE;

    $this->get_solr_array((FEDORA_VER_2 ? 'unit.id:' : 'rec.id:') . str_replace(':', '\:', $rec_id), 1, 0, '', '', '', rawurlencode($filter_q), '', '', $solr_arr);
    return $solr_arr['response']['numFound'];
  }

  /** \brief Check rec for available formats
   *
   */
  private function get_creation_date(&$dom) {
    if ($p = &$dom->getElementsByTagName('adminData')->item(0)) {
      return $p->getElementsByTagName('creationDate')->item(0)->nodeValue;
    }
  }

  /** \brief Check rec for available formats
   *
   */
  private function scan_for_formats(&$dom) {
    static $form_table;
    if (!isset($form_table)) {
      $form_table = $this->config->get_value('scan_format_table', 'setup');
    }

    if ($p = &$dom->getElementsByTagName('container')->item(0)) {
      foreach ($p->childNodes as $tag) {
        if ($x = &$form_table[$tag->tagName])
          $ret->format[]->_value = $x;
      }
    }

    return $ret;
  }

  /** \brief Extract record and namespace for it
   *
   */
  private function extract_record(&$dom, $rec_id, $format) {
    foreach ($format as $format_name => $format_arr) {
      switch ($format_name) {
        case 'dkabm':
          $rec = &$ret->record->_value;
          $record = &$dom->getElementsByTagName('record');
          if ($record->item(0)) {
            $ret->record->_namespace = $record->item(0)->lookupNamespaceURI('dkabm');
          }
          if ($record->item(0)) {
            foreach ($record->item(0)->childNodes as $tag) {
//              if ($format_name == 'dkabm' || $tag->prefix == 'dc') {
                if (trim($tag->nodeValue)) {
                  if ($tag->hasAttributes()) {
                    foreach ($tag->attributes as $attr) {
                      $o->_attributes-> {$attr->localName}->_namespace = $record->item(0)->lookupNamespaceURI($attr->prefix);
                      $o->_attributes-> {$attr->localName}->_value = $attr->nodeValue;
                    }
                  }
                  $o->_namespace = $record->item(0)->lookupNamespaceURI($tag->prefix);
                  $o->_value = $this->char_norm(trim($tag->nodeValue));
                  if (!($tag->localName == 'subject' && $tag->nodeValue == 'undefined'))
                    $rec-> {$tag->localName}[] = $o;
                  unset($o);
                }
//              }
            }
          }
          else
            verbose::log(FATAL, 'No dkabm record found in ' . $rec_id);
          break;
die();
  
        case 'marcxchange':
          $record = &$dom->getElementsByTagName('collection');
          if ($record->item(0)) {
            $ret->collection->_value = $this->xmlconvert->xml2obj($record->item(0), $this->xmlns['marcx']);
            //$ret->collection->_namespace = $record->item(0)->lookupNamespaceURI('collection');
            $ret->collection->_namespace = $this->xmlns['marcx'];
          }
          break;
  
        case 'docbook':
          $record = &$dom->getElementsByTagNameNS($this->xmlns['docbook'], 'article');
          if ($record->item(0)) {
            $ret->article->_value = $this->xmlconvert->xml2obj($record->item(0));
            $ret->article->_namespace = $record->item(0)->lookupNamespaceURI('docbook');
            //print_r($ret); die();
          }
          break;
        case 'opensearchobject':
          $record = &$dom->getElementsByTagNameNS($this->xmlns['oso'], 'object');
          if ($record->item(0)) {
            $ret->object->_value = $this->xmlconvert->xml2obj($record->item(0));
            $ret->object->_namespace = $record->item(0)->lookupNamespaceURI('oso');
            //print_r($ret); die();
          }
          break;
      }
    }
    return $ret;
  }

  private function char_norm($s) {
    $from[] = "\xEA\x9C\xB2";
    $to[] = 'Aa';
    $from[] = "\xEA\x9C\xB3";
    $to[] = 'aa';
    return str_replace($from, $to, $s);
  }

  /** \brief Parse solr facets and build reply
  *
  * array('facet_queries' => ..., 'facet_fields' => ..., 'facet_dates' => ...)
  *
  * return:
  * facet(*)
  * - facetName
  * - facetTerm(*)
  *   - frequence
  *   - term
  */
  private function parse_for_facets(&$facets) {
    if ($facets['facet_fields']) {
      foreach ($facets['facet_fields'] as $facet_name => $facet_field) {
        $facet->facetName->_value = $facet_name;
        foreach ($facet_field as $term => $freq) {
          if ($term && $freq) {
            $o->frequence->_value = $freq;
            $o->term->_value = $term;
            $facet->facetTerm[]->_value = $o;
            unset($o);
          }
        }
        $ret->facet[]->_value = $facet;
        unset($facet);
      }
    }
    return $ret;
  }

  /** \brief
   *  return true if xs:boolean is so
   */
  private function xs_boolean($str) {
    return (strtolower($str) == 'true' || $str == 1);
  }

}

/*
 * MAIN
 */

if (!defined('PHPUNIT_RUNNING')) {
  $ws=new openSearch();

  $ws->handle_request();
}
