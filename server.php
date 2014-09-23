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
require_once 'OLS_class_lib/solr_query_class.php';

//-----------------------------------------------------------------------------
class openSearch extends webServiceServer {
  protected $cql2solr;
  protected $curl;
  protected $cache;
  protected $search_profile;
  protected $search_profile_version = 3;
  protected $repository_name;
  protected $repository; // array containing solr and fedora uri's
  protected $tracking_id; 
  protected $query_language = 'cqleng'; 
  protected $number_of_fedora_calls = 0;
  protected $number_of_fedora_cached = 0;
  protected $agency_catalog_source = '';
  protected $filter_agency = '';
  protected $format = '';
  protected $which_rec_id = '';
  protected $collapsing_field = FALSE;  // if used, defined in ini-file
  protected $separate_field_query_style = TRUE; // seach as field:(a OR b) ie FALSE or (field:a OR field:b) ie TRUE
  protected $valid_relation = array(); 
  protected $valid_source = array(); 
  protected $rank_frequence_debug;


  public function __construct() {
    webServiceServer::__construct('opensearch.ini');

    if (!$timeout = $this->config->get_value('curl_timeout', 'setup'))
      $timeout = 20;
    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, $timeout);

    define(DEBUG_ON, $this->debug);
    if (!$mir = $this->config->get_value('max_identical_relation_names', 'setup'))
      $mir = 20;
    define(MAX_IDENTICAL_RELATIONS, $mir);
    define(MAX_OBJECTS_IN_WORK, 100);
    define('AND_OP', 'AND');
    define('OR_OP', 'OR');
  }

  /** \brief Entry search: Handles the request and set up the response
   *
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
    if (empty($param->query->_value)) {
      $unsupported = 'Error: No query found in request';
    }
    if ($repository_error = self::set_repositories($param->repository->_value)) {
      $unsupported = $repository_error;
    }

// for testing and group all
    if (count($this->aaa->aaa_ip_groups) == 1 && $this->aaa->aaa_ip_groups['all']) {
      $param->agency->_value = '100200';
      $param->profile->_value = 'test';
    }
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
    elseif (!($this->search_profile = self::fetch_profile_from_agency($param->agency->_value, $param->profile->_value))) {
      $unsupported = 'Error: Cannot fetch profile: ' . $param->profile->_value .
                     ' for ' . $param->agency->_value;
    }
    if ($unsupported) return $ret_error;
    $this->filter_agency = self::set_solr_filter($this->search_profile);

    if ($ufc = $this->config->get_value('collapsing_field', 'setup')) {
      $this->collapsing_field = $ufc;
    }
    $use_work_collection = ($param->collectionType->_value <> 'manifestation');

    $sort = array();
    $rank_types = $this->config->get_value('rank', 'setup');
    if (!self::parse_for_ranking($param, $rank, $rank_types)) {
      if ($unsupported = self::parse_for_sorting($param, $sort, $sort_types)) {
        return $ret_error;
      }
    }

    $this->format = self::set_format($param->objectFormat, $this->config->get_value('open_format', 'setup'), $this->config->get_value('solr_format', 'setup'));

    if ($unsupported) return $ret_error;

    $ret_error->searchResponse->_value->error->_value = &$error;
    $start = $param->start->_value;
    $step_value = min($param->stepValue->_value, MAX_COLLECTIONS);
    if (empty($start) && $step_value) {
      $start = 1;
    }
    if ($param->queryLanguage->_value) {
      $this->query_language = $param->queryLanguage->_value;
    }
    $debug_query = $this->xs_boolean($param->queryDebug->_value);
    $this->agency_catalog_source = $param->agency->_value . '-katalog';


    if ($us_settings = $this->repository['universal']) {
      require_once 'OLS_class_lib/universal_search_class.php';
      $this->watch->start('UniSea');
      $universal = new UniversalSearch($this->config->get_section($us_settings), $this->xmlns['mx']);
      $collections = $universal->search($param->query->_value, $start, $step_value);
      $this->watch->stop('UniSea');
      if (is_scalar($collections)) {
        $error = $collections;
        return $ret_error;
      }
      if (is_array($collections)) {
        self::format_records($collections);
      }
      $result = &$ret->searchResponse->_value->result->_value;
      $result->hitCount->_value = $universal->get_hits();
      $result->collectionCount->_value = count($collections);
      $result->more->_value = (($start + $step_value) <= $result->hitCount->_value ? 'true' : 'false');
      $result->searchResult = &$collections;
      $result->statInfo->_value->time->_value = $this->watch->splittime('Total');
      $result->statInfo->_value->trackingId->_value = $this->tracking_id;
      return $ret;
    }

    if ($pg_repos = $this->repository['postgress']) {
      $this->watch->start('postgress');
      $this->cql2solr = new SolrQuery($this->repository, $this->config, $this->query_language);
      $solr_query = $this->cql2solr->parse($param->query->_value);
      if ($solr_query['error']) {
        $error = self::cql2solr_error_to_string($solr_query['error']);
        return $ret_error;
      }
      verbose::log(TRACE, 'CQL to SOLR: ' . $param->query->_value . ' -> ' . preg_replace('/\s+/', ' ', print_r($solr_query, TRUE)));
      $q = implode(' and ', $solr_query['edismax']['q']);
      $filter = '';
      foreach ($solr_query['edismax']['fq'] as $fq) {
        $filter .= '&fq=' . rawurlencode($fq);
      }
      $solr_urls[0]['url'] = $this->repository['solr'] .
                    '?q=' . urlencode($q) .
                    '&fq=' . $filter .
                    '&start=' . ($start - 1).  
                    '&rows=' . $step_value .  
                    '&defType=edismax&wt=phps&fl=' . ($debug_query ? '&debugQuery=on' : '');
      if ($err = self::do_solr($solr_urls, $solr_arr)) {
        $error = $err;
        return $ret_error;
      }
      $collections = self::get_records_from_postgress($pg_repos, $solr_arr['response']);
      $this->watch->stop('postgress');
      if (is_scalar($collections)) {
        $error = $collections;
        return $ret_error;
      }
      self::filter_marcxchange_records($collections, $this->repository['filter']['marcxchange']);
      $result = &$ret->searchResponse->_value->result->_value;
      $result->hitCount->_value = self::get_num_found($solr_arr);
      $result->collectionCount->_value = count($collections);
      $result->more->_value = (($start + $step_value) <= $result->hitCount->_value ? 'true' : 'false');
      $result->searchResult = &$collections;
      $result->statInfo->_value->time->_value = $this->watch->splittime('Total');
      $result->statInfo->_value->trackingId->_value = $this->tracking_id;
      if ($debug_query) {
        $result->queryDebugResult->_value = self::set_debug_info($solr_arr['debug']);
      }
      return $ret;
    }

    /**
    *  Approach
    *  a) Do the solr search and fetch enough unit-ids in result
    *  b) Fetch a unit-ids work-object unless the record has been found
    *     in an earlier handled work-objects
    *  c) Collect unit-ids in this work-object
    *  d) repeat b. and c. until the requested number of objects is found
    *  e) if allObject is not set, do a new search combining the users search
    *     with an or'ed list of the unit-ids in the active objects and
    *     remove the unit-ids not found in the result
    *  f) Read full records from fedora for objects in result or fetch display-fields
    *     from solr, depending on the selected format
    *
    *  if $use_work_collection is FALSE skip b) to e)
    */

    $this->watch->start('Solr');
    $use_work_collection |= $sort_types[$sort[0]] == 'random';
    $key_work_struct = md5($param->query->_value . $this->repository_name . $this->filter_agency .
                           $use_work_collection .  implode('', $sort) . $rank . $boost_str . $this->config->get_inifile_hash());

    $this->cql2solr = new SolrQuery($this->repository, $this->config, $this->query_language);
    $solr_query = $this->cql2solr->parse($param->query->_value);
    if ($solr_query['error']) {
      $error = self::cql2solr_error_to_string($solr_query['error']);
      return $ret_error;
    }
    if (!count($solr_query['operands'])) {
      $error = 'Error: No query found in request';
      return $ret_error;
    }

    if ($this->filter_agency) {
      $filter_q = rawurlencode($this->filter_agency);
    }

    //var_dump($solr_query); die();
    if (is_array($solr_query['edismax']['ranking'])) {
      if (!$rank_cql = $rank_types['rank_cql'][reset($solr_query['edismax']['ranking'])]) {
        $rank_cql = $rank_types['rank_cql']['default'];
      }
      if ($rank_cql) {
        $rank = $rank_cql;
      }
    }
    if ($this->query_language == 'bestMatch') {
      $sort_q .= '&mm=1';
      $solr_query['edismax'] = $solr_query['best_match'];
      foreach ($solr_query['best_match']['sort'] as $key => $val) {
        $sort_q .= '&' . $key . '=' . urlencode($val);
        $best_match_debug->$key->_value = $val;
      }
    }
    elseif ($sort) {
      foreach ($sort as $s) {
        $ss[] = urlencode($sort_types[$s]);
      }
      $sort_q = '&sort=' . implode(',', $ss);
    }
    if ($rank == 'rank_frequency') {
      if ($new_rank = self::guess_rank($solr_query, $rank_types[$rank], $filter_q)) {
        $rank = $new_rank;
      }
      else {
        $rank = 'rank_none';
      }
    }
    if ($rank_types[$rank]) {
      $rank_qf = $this->cql2solr->make_boost($rank_types[$rank]['word_boost']);
      $rank_pf = $this->cql2solr->make_boost($rank_types[$rank]['phrase_boost']);
      $rank_tie = $rank_types[$rank]['tie'];
      $rank_q = '&qf=' . urlencode($rank_qf) .  '&pf=' . urlencode($rank_pf) .  '&tie=' . $rank_tie;
    }

    $rows = ($start + $step_value + 100) * 2;
    if ($param->facets->_value->facetName) {
      $pfacets = &$param->facets->_value;
      $facet_min = 1;
      if (isset($pfacets->facetMinCount->_value)) {
        $facet_min = $pfacets->facetMinCount->_value;
      }
      $facet_q .= '&facet=true&facet.limit=' . $pfacets->numberOfTerms->_value .  '&facet.mincount=' . $facet_min;
      if ($facet_sort = $pfacets->facetSort->_value) {
        $facet_q .= '&facet.sort=' . $facet_sort;
      }
      if (is_array($pfacets->facetName)) {
        foreach ($pfacets->facetName as $facet_name) {
          $facet_q .= '&facet.field=' . $facet_name->_value;
        }
      }
      elseif (is_scalar($pfacets->facetName->_value)) {
        $facet_q .= '&facet.field=' . $pfacets->facetName->_value;
      }
    }

    verbose::log(STAT, 'CQL to EDISMAX: ' . $param->query->_value . ' -> ' . preg_replace('/\s+/', ' ', print_r($solr_query['edismax'], TRUE)));
    verbose::log(TRACE, 'CQL to SOLR: ' . $param->query->_value . ' -> ' . preg_replace('/\s+/', ' ', print_r($solr_query, TRUE)));

    // do the query
    if ($sort[0] == 'random') {
      if ($err = self::get_solr_array($solr_query['edismax'], 0, 0, '', '', $facet_q, $filter_q, '', $debug_query, $solr_arr))
        $error = $err;
      else {
        $numFound = self::get_num_found($solr_arr);
      }
    }
    else {
      if ($err = self::get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, '', $filter_q, $boost_str, $debug_query, $solr_arr))
        $error = $err;
      else {
        self::extract_unit_id_from_solr($solr_arr, $search_ids);
        $numFound = self::get_num_found($solr_arr);
      }
    }
    $this->watch->stop('Solr');

    if ($error) return $ret_error;

    if ($debug_query) {
      $debug_result = self::set_debug_info($solr_arr['debug'], $this->rank_frequence_debug, $best_match_debug);
    }
    //$facets = self::parse_for_facets($solr_arr);

    $this->watch->start('Build_id');
    $work_ids = $used_search_fids = array();
    if ($sort[0] == 'random') {
      $rows = min($step_value, $numFound);
      $more = $step_value < $numFound;
      for ($w_idx = 0; $w_idx < $rows; $w_idx++) {
        do {
          $no = rand(0, $numFound-1);
        } while (isset($used_search_fid[$no]));
        $used_search_fid[$no] = TRUE;
        self::get_solr_array($solr_query['edismax'], $no, 1, '', '', '', $filter_q, '', $debug_query, $solr_arr);
        $uid =  self::get_first_solr_element($solr_arr, 'unit.id');
        //$local_data[$uid] = $solr_arr['response']['docs']['rec.collectionIdentifier'];
        $work_ids[] = array($uid);
      }
    }
    else {
      $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                               $this->config->get_value('cache_port', 'setup'),
                               $this->config->get_value('cache_expire', 'setup'));
      if (empty($_GET['skipCache'])) {
        if ($work_cache_struct = $this->cache->get($key_work_struct)) {
          verbose::log(TRACE, 'Cache hit, lines: ' . count($work_cache_struct));
        }
        else {
          verbose::log(TRACE, 'Cache miss');
        }
      }

      $w_no = 0;

      if (DEBUG_ON) print_r($search_ids);
      //if (DEBUG_ON) print_r($local_data);

      for ($s_idx = 0; isset($search_ids[$s_idx]); $s_idx++) {
        $uid = &$search_ids[$s_idx];
        if (!isset($search_ids[$s_idx+1]) && count($search_ids) < $numFound) {
          $this->watch->start('Solr_add');
          verbose::log(FATAL, 'To few search_ids fetched from solr. Query: ' . $solr_query['edismax']);
          $rows *= 2;
          if ($err = self::get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, '', $filter_q, $boost_str, $debug_query, $solr_arr)) {
            $error = $err;
            return $ret_error;
          }
          else {
            self::extract_unit_id_from_solr($solr_arr, $search_ids);
            $numFound = self::get_num_found($solr_arr);
          }
          $this->watch->stop('Solr_add');
        }
        if (FALSE) {
          self::get_fedora_rels_hierarchy($uid, $unit_result);
          $unit_id = self::parse_rels_for_unit_id($unit_result);
          if (DEBUG_ON) echo 'UR: ' . $uid . ' -> ' . $unit_id . "\n";
          $uid = $unit_id;
        }
        if ($used_search_fids[$uid]) continue;
        if (count($work_ids) >= $step_value) {
          $more = TRUE;
          break;
        }

        $w_no++;
        // find relations for the record in fedora
        // uid: id as found in solr's fedoraPid
        if ($work_cache_struct[$w_no]) {
          $uid_array = $work_cache_struct[$w_no];
        }
        else {
          if ($use_work_collection) {
            $this->watch->start('get_w_id');
            self::get_fedora_rels_hierarchy($uid, $record_rels_hierarchy);
            /* ignore the fact that there is no RELS_HIERARCHY datastream
            */
            $this->watch->stop('get_w_id');
            if (DEBUG_ON) echo 'RR: ' . $record_rels_hierarchy . "\n";

            if ($work_id = self::parse_rels_for_work_id($record_rels_hierarchy)) {
              // find other recs sharing the work-relation
              $this->watch->start('get_fids');
              self::get_fedora_rels_hierarchy($work_id, $work_rels_hierarchy);
              if (DEBUG_ON) echo 'WR: ' . $work_rels_hierarchy . "\n";
              $this->watch->stop('get_fids');
              if (!$uid_array = self::parse_work_for_object_ids($work_rels_hierarchy, $uid)) {
                verbose::log(FATAL, 'Fedora fetch/parse work-record: ' . $work_id . ' refered from: ' . $uid);
                $uid_array = array($uid);
              }
              if (DEBUG_ON) {
                echo 'fid: ' . $uid . ' -> ' . $work_id . " -> object(s):\n";
                print_r($uid_array);
              }
            }
            else {
              verbose::log(WARNING, 'Cannot find work_id for unit: ' . $uid);
              $uid_array = array($uid);
            }
          }
          else
            $uid_array = array($uid);
        }

        foreach ($uid_array as $id) {
          $used_search_fids[$id] = TRUE;
        }
        $work_cache_struct[$w_no] = $uid_array;
        if (count($uid_array) >= MAX_OBJECTS_IN_WORK) {
          verbose::log(FATAL, 'Fedora work-record: ' . $work_id . ' refered from: ' . $uid . ' contains ' . count($uid_array) . ' objects');
          array_splice($uid_array, MAX_OBJECTS_IN_WORK);
        }
        if ($w_no >= $start)
          $work_ids[$w_no] = $uid_array;
      }
    }

    if (count($work_ids) < $step_value && count($search_ids) < $numFound) {
      verbose::log(FATAL, 'To few search_ids fetched from solr. Query: ' . $solr_query['edismax']);
    }

    // check if the search result contains the ids
    // allObject=0 - remove objects not included in the search result
    // allObject=1 & agency - remove objects not included in agency
    //
    if (DEBUG_ON) echo 'work_ids: ' . print_r($work_ids, TRUE) . "\n";

    define('MAX_QUERY_ELEMENTS', 950);
    if ($work_ids && $numFound && $use_work_collection && $step_value) {
      $add_queries = self::make_add_queries($work_ids);
      $this->watch->start('Solr_filt');
      $solr_2_arr = self::do_add_queries($add_queries, $param->query->_value, self::xs_boolean($param->allObjects->_value), $filter_q);
// fetch display here to get sort-keys for primary objects
      $this->watch->stop('Solr_filt');
      $this->watch->start('Solr_disp');
      $display_solr_arr = self::do_add_queries_and_fetch_solr_data_fields($add_queries, 'unit.isPrimaryObject=true', self::xs_boolean($param->allObjects->_value), $filter_q);
      $this->watch->stop('Solr_disp');
      if (is_scalar($solr_2_arr)) {
        $error = 'Internal problem: Cannot decode Solr re-search';
        return $ret_error;
      }
      foreach ($work_ids as $w_no => $w_list) {
        if (count($w_list) > 0) {
          $hit_fid_array = array();
          foreach ($w_list as $w) {
            foreach ($solr_2_arr as $s_2_a) {
              foreach ($s_2_a['response']['docs'] as $fdoc) {
                $u_id =  self::scalar_or_first_elem($fdoc['unit.id']);
                if ($u_id == $w) {
                  $hit_fid_array[$u_id] = $u_id;
                  break 2;
                }
              }
            }
            foreach ($display_solr_arr as $d_s_a) {
              foreach ($d_s_a['response']['docs'] as $fdoc) {
                $u_id =  self::scalar_or_first_elem($fdoc['unit.id']);
                if ($u_id == $w) {
                  $unit_sort_keys[$u_id] = $fdoc['sort.complexKey'] . '  ' . $u_id;
                  $collection_identifier[$u_id] =  self::scalar_or_first_elem($fdoc['rec.collectionIdentifier']);
                  break 2;
                }
              }
            }
          }
          if (empty($hit_fid_array)) {
            verbose::log(ERROR, 'Re-search: Cannot find any of ' . implode(',', $w_list) . ' in unit.id');
            $work_ids[$w_no] = array($w_list[0]);
          }
          else {
            $work_ids[$w_no] = $hit_fid_array;
          }
        }
      }
      if (DEBUG_ON) echo 'work_ids after research: ' . print_r($work_ids, TRUE) . "\n";
    }

    if (DEBUG_ON) echo 'txt: ' . $txt . "\n";
    if (DEBUG_ON) echo 'solr_2_arr: ' . print_r($solr_2_arr, TRUE) . "\n";
    if (DEBUG_ON) echo 'add_queries: ' . print_r($add_queries, TRUE) . "\n";
    if (DEBUG_ON) echo 'used_search_fids: ' . print_r($used_search_fids, TRUE) . "\n";

    $this->watch->stop('Build_id');

    if ($this->cache)
      $this->cache->set($key_work_struct, $work_cache_struct);

    $missing_record = $this->config->get_value('missing_record', 'setup');

    // work_ids now contains the work-records and the fedoraPids they consist of
    // now fetch the records for each work/collection
    $this->watch->start('get_recs');
    $collections = array();
    $rec_no = max(1, $start);
    $HOLDINGS = ' holdings ';
    foreach ($work_ids as &$work) {
      $objects = array();
      foreach ($work as $unit_id) {
        $data_stream = self::set_data_stream_name($collection_identifier[$unit_id]);
        self::get_fedora_rels_addi($unit_id, $fedora_addi_relation);
        self::get_fedora_rels_hierarchy($unit_id, $unit_rels_hierarchy);
        list($fpid, $unit_members) = self::parse_unit_for_object_ids($unit_rels_hierarchy);
        $sort_holdings = ' ';
        unset($no_of_holdings);
        if (self::xs_boolean($param->includeHoldingsCount->_value)) {
          $no_of_holdings = self::get_holdings($fpid);
        }
        if ((strpos($unit_sort_keys[$unit_id], $HOLDINGS) !== FALSE)) {
          $holds = isset($no_of_holdings) ? $no_of_holdings : self::get_holdings($fpid);
          $sort_holdings = sprintf(' %04d ', 9999 - intval($holds['have']));
        }
        $fpid_sort_keys[$fpid] = str_replace($HOLDINGS, $sort_holdings, $unit_sort_keys[$unit_id]);
        if ($error = self::get_fedora_raw($fpid, $fedora_result, $data_stream)) {
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
          if ($this->collapsing_field) {
            foreach ($solr_arr['grouped'][$this->collapsing_field]['groups'] as $solr_idx => $solr_grp) {
              if ($fpid == $solr_grp['groupValue']) {
                $explain = $solr_arr['debug']['explain'][$fpid];
                break;
              }
            }
          }
          else {
            foreach ($solr_arr['response']['docs'] as $solr_idx => $solr_rec) {
              if ($fpid == $solr_rec['fedoraPid']) {
                $explain = $solr_arr['debug']['explain'][$fpid];
                break;
              }
            }
          }

        }
        $sort_key = $fpid_sort_keys[$fpid] . ' ' . sprintf('%04d', count($objects));
        $sorted_work[$sort_key] = $unit_id;
        $objects[$sort_key]->_value =
          self::parse_fedora_object($fedora_result,
                                    $fedora_addi_relation,
                                    $param->relationData->_value,
                                    $fpid,
                                    NULL, // no primary Pid
                                    NULL, // no $filter_agency on search - bad performance
                                    $no_of_holdings,
                                    $explain);
      }
      $work = $sorted_work;
      if (DEBUG_ON) print_r($sorted_work);
      unset($sorted_work);
      $o->collection->_value->resultPosition->_value = $rec_no++;
      $o->collection->_value->numberOfObjects->_value = count($objects);
      if (count($objects) > 1) {
        ksort($objects);
      }
      $o->collection->_value->object = $objects;
      $collections[]->_value = $o;
      unset($o);
    }
    if (DEBUG_ON) print_r($unit_sort_keys);
    if (DEBUG_ON) print_r($fpid_sort_keys);
    $this->watch->stop('get_recs');

  // TODO: if an openFormat is specified, we need to remove data so openFormat dont format unneeded stuff
  // But apparently, openFormat breaks when receiving an empty object
    if ($param->collectionType->_value == 'work-1') {
      foreach ($collections as &$c) {
        $collection_no = 0;
        foreach ($c->_value->collection->_value->object as &$o) {
          if ($collection_no++) {
            foreach ($o->_value as $tag => $val) {
              if (!in_array($tag, array('identifier', 'creationDate', 'formatsAvailable'))) {
                unset($o->_value->$tag);
              }
            }
          }
        }
      }
    }

    if ($step_value) {
      if ($this->format['found_open_format']) {
        self::format_records($collections);
      }
      if ($this->format['found_solr_format']) {
        $this->watch->start('Solr_disp');
        //$display_solr_arr = self::do_add_queries_and_fetch_solr_data_fields($add_queries, 'unit.isPrimaryObject=true', self::xs_boolean($param->allObjects->_value), $filter_q);
        $this->watch->stop('Solr_disp');
        self::format_solr($collections, $display_solr_arr, $work_ids, $fpid_sort_keys);
      }
      self::remove_unselected_formats($collections);
      if ($this->format['marcxchange']['user_selected']) {
        self::filter_marcxchange_records($collections, $this->repository['filter']['marcxchange']);
      }
    }

// try to get a better hitCount by looking for primaryObjects only 
    $nfcl = intval($this->config->get_value('num_found_collaps_limit', 'setup'));
    if ($nfcl >= $numFound) {
      if ($nfcf = $this->config->get_value('num_found_collapsing_field', 'setup')) {
        $this->collapsing_field = $nfcf;
      }
    }
    $this->watch->start('Solr_hits');
    // obsolete - handled by collaps-settings above   
    // $solr_query['edismax']['fq'][] = 'unit.isPrimaryObject:true';   // need some discussion to decide for or against this line
    if ($err = self::get_solr_array($solr_query['edismax'], 0, 0, '', $rank_q, $facet_q, $filter_q, '', $debug_query, $solr_arr)) {
      $this->watch->stop('Solr_hits');
      $error = $err;
      return $ret_error;
    }
    else {
      $this->watch->stop('Solr_hits');
      if ($n = self::get_num_found($solr_arr)) {
        verbose::log(TRACE, 'Modify hitcount from: ' . $numFound . ' to ' . $n);
        $numFound = $n;
      }
      $facets = self::parse_for_facets($solr_arr);
    }

//var_dump($solr_2_arr);
//var_dump($work_cache_struct);
//die();
    if ($_REQUEST['work'] == 'debug') {
      echo "returned_work_ids: \n";
      print_r($work_ids);
      echo "cache: \n";
      print_r($work_cache_struct);
      die();
    }
    //if (DEBUG_ON) { print_r($work_cache_struct); die(); }
    //if (DEBUG_ON) { print_r($collections); die(); }
    //if (DEBUG_ON) { print_r($solr_arr); die(); }

    $result = &$ret->searchResponse->_value->result->_value;
    $result->hitCount->_value = $numFound;
    $result->collectionCount->_value = count($collections);
    $result->more->_value = ($more ? 'true' : 'false');
    self::set_sortUsed($result, $rank, $sort);
    $result->searchResult = $collections;
    $result->facetResult->_value = $facets;
    if ($debug_query && $debug_result) {
      $result->queryDebugResult->_value = $debug_result;
    }
    $result->statInfo->_value->fedoraRecordsCached->_value = $this->number_of_fedora_cached;
    $result->statInfo->_value->fedoraRecordsRead->_value = $this->number_of_fedora_calls;
    $result->statInfo->_value->time->_value = $this->watch->splittime('Total');
    $result->statInfo->_value->trackingId->_value = $this->tracking_id;

    return $ret;
  }


  /** \brief Entry getObject: Get an object in a specific format
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
    if ($error = self::set_repositories($param->repository->_value)) {
      verbose::log(FATAL, $error);
      return $ret_error;
    }
    if (empty($param->agency->_value) && empty($param->profile->_value)) {
      $param->agency->_value = $this->config->get_value('agency_fallback', 'setup');
      $param->profile->_value = $this->config->get_value('profile_fallback', 'setup');
    }
    if ($agency = $param->agency->_value) {
      if ($param->profile->_value) {
        if (!($this->search_profile = self::fetch_profile_from_agency($agency, $param->profile->_value))) {
          $error = 'Error: Cannot fetch profile: ' . $param->profile->_value . ' for ' . $agency;
          return $ret_error;
        }
      }
      else
        $agencies = $this->config->get_value('agency', 'agency');
      $agencies[$agency] = self::set_solr_filter($this->search_profile);
      if (isset($agencies[$agency]))
        $this->filter_agency = $agencies[$agency];
      else {
        $error = 'Error: Unknown agency: ' . $agency;
        return $ret_error;
      }
    }
    if ($this->filter_agency) {
      $filter_q = rawurlencode($this->filter_agency);
    }

    $this->agency_catalog_source = $agency . '-katalog';
    $this->format = self::set_format($param->objectFormat, 
                               $this->config->get_value('open_format', 'setup'), 
                               $this->config->get_value('solr_format', 'setup'));
    $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                             $this->config->get_value('cache_port', 'setup'),
                             $this->config->get_value('cache_expire', 'setup'));

    if (is_array($param->identifier)) {
      $fpids = $param->identifier;
    }
    else {
      $fpids = array($param->identifier);
    }
    if ($this->format['found_solr_format']) {
      foreach ($this->format as $f) {
        if ($f['is_solr_format']) {
          $add_fl .= ',' . $f['format_name'];
        }
      }
    }
    if ($pg_repos = $this->repository['postgress']) {
      foreach ($fpids as $fpid_number => $fpid) {
        list($owner_collection, $id) = explode(':', $fpid->_value);
        list($owner, $coll) = explode('-', $owner_collection);
        $docs['docs'][] = array('marc.001a' => array(0 => $id), 'marc.001b' => $owner);
      }
      $collections = self::get_records_from_postgress($pg_repos, $docs);
      //var_dump($collections); die();
      if (is_scalar($collections)) {
        $error = $collections;
        return $ret_error;
      }
      self::filter_marcxchange_records($collections, $this->repository['filter']['marcxchange']);
      //self::filter_marcxchange_records($collections, '^[a-z]');
      $result = &$ret->searchResponse->_value->result->_value;
      $result->hitCount->_value = count($fpids);
      $result->collectionCount->_value = count($collections);
      $result->more->_value = 'false';
      $result->searchResult = &$collections;
      $result->statInfo->_value->time->_value = $this->watch->splittime('Total');
      $result->statInfo->_value->trackingId->_value = $this->tracking_id;
      if ($debug_query) {
        $debug_result->rawQueryString->_value = $solr_arr['debug']['rawquerystring'];
        $debug_result->queryString->_value = $solr_arr['debug']['querystring'];
        $debug_result->parsedQuery->_value = $solr_arr['debug']['parsedquery'];
        $debug_result->parsedQueryString->_value = $solr_arr['debug']['parsedquery_toString'];
        $result->queryDebugResult->_value = $debug_result;
      }
      return $ret;

    }
    foreach ($fpids as $fpid_number => $fpid) {
      $id_array[] = $fpid->_value;
    }
    $this->cql2solr = new SolrQuery($this->repository, $this->config);
    $chk_query = $this->cql2solr->parse('rec.id=(' . implode(' or ', $id_array) . ')');
        $solr_q = $this->repository['solr'] .
        '?wt=phps' .
              '&q=' . urlencode(implode(' and ', $chk_query['edismax']['q'])) .
              '&fq=' . $filter_q .
              // if briefDisplay data must be fetched from primaryObject '&fq=unit.isPrimaryObject:true' . 
              '&start=0' .
              '&rows=50000' .
              '&defType=edismax' .
              '&fl=rec.collectionIdentifier,fedoraPid,rec.id,unit.id,unit.isPrimaryObject' . $add_fl;
    $solr_result = $this->curl->get($solr_q);
    $solr_2_arr[] = unserialize($solr_result);

    foreach ($fpids as $fpid_number => $fpid) {
      $collection_identifier = $is_primary = '';
      foreach ($solr_2_arr as $s_2_a) {
        foreach ($s_2_a['response']['docs'] as $fdoc) {
          $p_id =  self::scalar_or_first_elem($fdoc['fedoraPid']);
          if ($p_id == $fpid->_value) {
            $is_primary =  self::scalar_or_first_elem($fdoc['unit.isPrimaryObject']);
            $collection_identifier =  self::scalar_or_first_elem($fdoc['rec.collectionIdentifier']);
            break 2;
          }
        }
      }
      if (is_primary == 'true') {
        $primary_pid = $p_id;
        unset($unit_id);
      }
      else {
        self::get_fedora_rels_hierarchy($fpid->_value, $fedora_rels_hierarchy);
        $unit_id = self::parse_rels_for_unit_id($fedora_rels_hierarchy);
        self::get_fedora_rels_hierarchy($unit_id, $unit_rels_hierarchy);
        $primary_pid = self::fetch_primary_bib_object($unit_rels_hierarchy);
        // The record will always be the primary object
        // Someday we have to get local streams, so collection_identifier har to be set to
        // $agency . '-katalog' if such a data-stream exist in the object
      }
      $data_stream = self::set_data_stream_name($collection_identifier);
//var_dump($filter_q);
//var_dump($solr_2_arr); 
//var_dump($collection_identifier);
//var_dump($data_stream); die();
      
      if (self::deleted_object($fpid->_value)) {
        $rec_error = 'Error: deleted record: ' . $fpid->_value;
      }
      elseif ($error = self::get_fedora_raw($fpid->_value, $fedora_result, $data_stream)) {
        $rec_error = 'Error: unknown/missing record: ' . $fpid->_value;
      }
      elseif ($param->relationData->_value || 
          $this->format['found_solr_format'] || 
          self::xs_boolean($param->includeHoldingsCount->_value)) {
        if (empty($unit_id)) {
          self::get_fedora_rels_hierarchy($fpid->_value, $fedora_rels_hierarchy);
          $unit_id = self::parse_rels_for_unit_id($fedora_rels_hierarchy);
        }
        if ($param->relationData->_value) {
          self::get_fedora_rels_addi($unit_id, $fedora_addi_relation);
        }
        if (self::xs_boolean($param->includeHoldingsCount->_value)) {
          //self::get_fedora_rels_hierarchy($unit_id, $unit_rels_hierarchy);
          //list($dummy, $dummy) = self::parse_unit_for_object_ids($unit_rels_hierarchy);
          $this->cql2solr = new SolrQuery($this->repository, $this->config);
          $no_of_holdings = self::get_holdings($fpid->_value);
        }
      }
//var_dump($fedora_rels_hierarchy);
//var_dump($unit_id);
//var_dump($fedora_addi_relation);
//die();
      $o->collection->_value->resultPosition->_value = $fpid_number + 1;
      $o->collection->_value->numberOfObjects->_value = 1;
      if ($rec_error) {
        $o->collection->_value->object[]->_value->error->_value = $rec_error;
        unset($rec_error);
      } 
      else {
        $o->collection->_value->object[]->_value =
          self::parse_fedora_object($fedora_result,
                                    $fedora_addi_relation,
                                    $param->relationData->_value,
                                    $fpid->_value,
                                    $primary_pid,
                                    $this->filter_agency,
                                    $no_of_holdings);
      }
      $collections[]->_value = $o;
      unset($o);
      $id_array[] = $unit_id;
      $work_ids[$fpid_number + 1] = array($unit_id);
      unset($unit_id);
    }

    if ($this->format['found_open_format']) {
      self::format_records($collections);
    }
    if ($this->format['found_solr_format']) {
      self::format_solr($collections, $solr_2_arr, $work_ids);
    }
    self::remove_unselected_formats($collections);
    if ($this->format['marcxchange']['user_selected']) {
      self::filter_marcxchange_records($collections, $this->repository['filter']['marcxchange']);
    }

    $result = &$ret->searchResponse->_value->result->_value;
    $result->hitCount->_value = count($collections);
    $result->collectionCount->_value = 1;
    $result->more->_value = 'false';
    $result->searchResult = $collections;
    $result->facetResult->_value = '';
    $result->statInfo->_value->fedoraRecordsCached->_value = $this->number_of_fedora_cached;
    $result->statInfo->_value->fedoraRecordsRead->_value = $this->number_of_fedora_calls;
    $result->statInfo->_value->time->_value = $this->watch->splittime('Total');
    $result->statInfo->_value->trackingId->_value = $this->tracking_id;

    //print_r($param);
    //print_r($fedora_result);
    //print_r($objects);
    //print_r($ret); die();
    return $ret;
  }

  /*******************************************************************************/

  /** \brief sets sortUsed if rank or sort is used
   * 
   * $param $ret (object)
   * $param $rank (string) 
   * $param $sort (string) 
   */
  private function set_sortUsed(&$ret, $rank, $sort) {
    if (isset($rank)) {
      $ret->sortUsed->_value = $rank;
    }
    elseif (!empty($sort)) {
      foreach ($sort as $s) {
        $ret->sortUsed[]->_value = $s;
      }
    }
  }

  /** \brief Compares registers in cql_file with solr, using the luke request handler:
   *   http://wiki.apache.org/solr/LukeRequestHandler
   */
  protected function diffCqlFileWithSolr() {
    if ($error = self::set_repositories($_REQUEST['repository'])) {
      die('Error setting repository: ' . $error);
    }
    $luke_url = $this->repository['solr'];
    if (empty($luke_url)) {
      die('Cannot find url to solr for repository');
    }
    $luke = $this->config->get_value('luke', 'setup');
    foreach ($luke as $from => $to) {
      $luke_url = str_replace($from, $to, $luke_url);
    }
    $luke_result = json_decode($this->curl->get($luke_url));
    if (!$luke_result) {
      die('Cannot fetch register info from solr: ' . $luke_url);
    }
    $luke_fields = &$luke_result->fields;
    $dom = new DomDocument();
    $dom->load($this->repository['cql_file']) || die('Cannot read cql_file: ' . $this->repository['cql_file']);

    foreach ($dom->getElementsByTagName('indexInfo') as $info_item) {
      foreach ($info_item->getElementsByTagName('index') as $index_item) {
        if ($map_item = $index_item->getElementsByTagName('map')->item(0)) {
          if ($name_item = $map_item->getElementsByTagName('name')->item(0)) {
            $full_name = $name_item->getAttribute('set').'.'.$name_item->nodeValue;
            if ($luke_fields->$full_name) {
              unset($luke_fields->$full_name);
            } 
            else {
              $cql_regs[] = $full_name;
            } 
          } 
        }
      }
    }

    echo '<html><body><h1>Found in ' . $this->repository['cql_file'] . ' but not in Solr</h1>';
    foreach ($cql_regs as $cr)
      echo $cr . '</br>';
    echo '</br><h1>Found in Solr but not in ' . $this->repository['cql_file'] . '</h1>';
    foreach ($luke_fields as $lf => $obj)
      echo $lf . '</br>';
    
    die('</body></html>');
  }

  /*******************************************************************************/

  /** \brief Reads records from Raw Record Database
   *   
   * @param $solr_response array   Response from a solr search in php object
   * 
   * return mixed  array of collections or error string
   */
  private function get_records_from_postgress($pg_db, $solr_response) {
    require_once 'OLS_class_lib/pg_database_class.php';
    $dom = new DomDocument();
    $ret = array();
    try {
      $pg = new pg_database($pg_db);
      $pg->open();
      $rec_pos = $solr_response['start'];
      foreach ($solr_response['docs'] as $solr_doc) {
        if (empty($solr_doc['marc.001a']) || empty($solr_doc['marc.001b'])) {
          verbose::log(FATAL, 'SOLR error: cannot find field marc.001a or marc.001b');
          @ $dom->loadXml('<?xml version="1.0" encoding="UTF-8"?' . '><marcx:record format="danMARC2" type="Bibliographic" xmlns:marcx="info:lc/xmlns/marcxchange-v1"><marcx:datafield tag="245" ind1="0" ind2="0"><marcx:subfield code="a">ERROR: Cannot read record from repository: ' . $this->repository_name . '</marcx:subfield></marcx:datafield></marcx:record>');
        }
        else {
          $query = 'SELECT content FROM records WHERE id = \'' . $solr_doc['marc.001a'][0] . '\' AND library = ' . $solr_doc['marc.001b'];
          $pg->set_query($query);
          $pg->execute();
          $row = $pg->get_row();
          @ $dom->loadXml(base64_decode($row['content']));
        }
        $marc_obj = $this->xmlconvert->xml2obj($dom, $this->xmlns['marcx']);
        $rec_pos++;
        $ret[$rec_pos]->_value->collection->_value->resultPosition->_value = $rec_pos;
        $ret[$rec_pos]->_value->collection->_value->numberOfObjects->_value = 1;
        $ret[$rec_pos]->_value->collection->_value->object[0]->_value->collection->_value = $marc_obj;
        $ret[$rec_pos]->_value->collection->_value->object[0]->_value->collection->_namespace = $this->xmlns['marcx'];
      }
      $pg->close();
    }
    catch (Exception $e) {
      verbose::log(FATAL, 'Database error: ' . str_replace("\n", '', $e->getMessage()));
      return 'Error fatching records from postgress in repository: ' . $this->repository_name;
    }
    return $ret;
  }

  /** \brief Change cql_error to string
   *
   * $param $solr_error array
   */
  private function cql2solr_error_to_string($solr_error) {
    $str = '';
    foreach (array('no' => '|: ', 'description' => '', 'details' => ' (|)', 'pos' => ' at pos ') as $tag => $txt) {
      list($pre, $post) = explode('|', $txt);
      if ($solr_error[0][$tag]) {
        $str .= $pre . $solr_error[0][$tag]. $post;
      }
    }
    return $str;
  }

  /** \brief split into multiple solr-searches each containing less than MAX_QUERY_ELEMENTS elements
   *
   */
  private function make_add_queries($work_ids) {
    $block_idx = $no_bool = 0;
    $no_of_rows = 1;
    $add_queries[$block_idx] = '';
    $this->which_rec_id = 'unit.id';
    foreach ($work_ids as $w_no => $w) {
      if ($add_queries[$block_idx] && ($no_bool + count($w)) > MAX_QUERY_ELEMENTS) {
        $block_idx++;
        $no_bool = 0;
      }
      foreach ($w as $id) {
        $id = str_replace(':', '\:', $id);
        if ($this->separate_field_query_style) {
          $add_queries[$block_idx] .= (empty($add_queries[$block_idx]) ? '' : ' ' . OR_OP . ' ') . $this->which_rec_id . ':' . $id;
        }
        else {
          $add_queries[$block_idx] .= (empty($add_queries[$block_idx]) ? '' : ' ' . OR_OP . ' ') . $id;
        }
        $no_bool++;
        $no_of_rows++;
      }
    }
    return $add_queries;
  }

  private function do_add_queries_and_fetch_solr_data_fields($add_queries, $query, $all_objects, $filter_q) {
    if ($this->format['found_solr_format']) {
      foreach ($this->format as $f) {
        if ($f['is_solr_format']) {
          $add_fl .= ',' . $f['format_name'];
        }
      }
    }
    return self::do_add_queries($add_queries, $query, $all_objects, $filter_q, $add_fl);
  }
  /** \brief Create solr array with records valid for the search-profile and parameters. If needed fetch data for display as well
   *
   */
  private function do_add_queries($add_queries, $query, $all_objects, $filter_q, $add_field_list='') {
    foreach ($add_queries as $add_idx => $add_query) {
      if ($this->separate_field_query_style) {
          $add_q =  '(' . $add_query . ')';
      }
      else {
          $add_q =  $this->which_rec_id . ':(' . $add_query . ')';
      }
      if ($all_objects) {
        $chk_query['edismax']['q'][] =  $add_q;
      }
      else {
        $chk_query = $this->cql2solr->parse($query);
        if ($add_query) {
          $chk_query['edismax']['q'][] =  $add_q;
        }
      }
      if ($chk_query['error']) {
        $error = $chk_query['error'];
        return $ret_error;
      }
      $q = $chk_query['edismax'];
      $solr_url = self::create_solr_url($q, 0, 999999, $filter_q);
      list($solr_host, $solr_parm) = explode('?', $solr_url['url'], 2);
      $solr_parm .= '&fl=rec.collectionIdentifier,unit.isPrimaryObject,unit.id,sort.complexKey' . $add_field_list;
      verbose::log(DEBUG, 'Re-search: ' . $this->repository['solr'] . '?' . str_replace('&wt=phps', '', $solr_parm) . '&debugQuery=on');
      if (DEBUG_ON) {
        echo 'post_array: ' . $$solr_url['url'] . PHP_EOL;
      }

      $this->curl->set_post($solr_parm, 0); // use post here because query can be very long
      $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded; charset=utf-8'), 0);
      $solr_result = $this->curl->get($solr_host, 0);
// remember to clear POST 
      $this->curl->set_option(CURLOPT_POST, 0, 0);
      if (!($solr_arr[$add_idx] = unserialize($solr_result))) {
        verbose::log(FATAL, 'Internal problem: Cannot decode Solr re-search');
        return 'Internal problem: Cannot decode Solr re-search';
      }
    }
    return $solr_arr;
  }

  /** \brief Sets this->repository from user parameter or defaults to ini-file setup
   *
   */
  private function set_repositories($repository) {
    $repositories = $this->config->get_value('repository', 'setup');
    if (!$this->repository_name = $repository) {
      $this->repository_name = $this->config->get_value('default_repository', 'setup');
    }
    if ($this->repository = $repositories[$this->repository_name]) {
      foreach ($repositories['defaults'] as $key => $url_par) {
        if (empty($this->repository[$key])) {
          if (substr($key, 0, 7) == 'fedora_') {
            $this->repository[$key] = $this->repository['fedora'];
          }
          $this->repository[$key] .= $url_par;
        }
      }
    }
    else {
      return 'Error: Unknown repository: ' . $this->repository_name;
    }
  }

  /** \brief return data stream name depending on collection identifier
   *  - if $col_id (rec.collectionIdentifier) startes with 7 - dataStream: localData.$col_id
   *  - else dataStream: commonData
   *
   */
  private function set_data_stream_name($col_id) {
    if ($col_id && (substr($col_id, 0, 1) == '7')) {
      $data_stream = 'localData.' . $col_id;
    }
    else {
      $data_stream = 'commonData';
    }
    if (DEBUG_ON) {
      echo 'dataStream: ' . $data_stream . PHP_EOL;
    }
    return $data_stream;
  }

  /** \brief parse input for rank parameters
   *
   * @param $param object       The request
   * @param $rank string        Name of rank used by request
   * @param $rank_types array   Settings for the given rank
   * 
   * return boolean  True if a ranking is found
   */
  private function parse_for_ranking($param, &$rank, &$rank_types) {
    if ($rr = $param->userDefinedRanking) {
      $rank = 'rank';
      $rank_user['tie'] = $rr->_value->tieValue->_value;
      $rfs = (is_array($rr->_value->rankField) ? $rr->_value->rankField : array($rr->_value->rankField));
      foreach ($rfs as $rf) {
        $boost_type = ($rf->_value->fieldType->_value == 'word' ? 'word_boost' : 'phrase_boost');
        $rank_user[$boost_type][$rf->_value->fieldName->_value] = $rf->_value->weight->_value;
        $rank .= '_' . $boost_type . '-' . $rf->_value->fieldName->_value . '-' . $rf->_value->weight->_value;
      }
      $rank_types[$rank] = $rank_user;
    }
    elseif (is_scalar($param->sort->_value)) {
      if ($rank_types[$param->sort->_value]) {
        $rank = $param->sort->_value;
      }
    }
    return !empty($rank);
  }

  /** \brief parse input for sort parameters
   *
   * @param $param object       The request
   * @param $sort string        Name of sort used by request
   * @param $sort_types array   Settings for the given sort
   * 
   * return none  Modifies $sort and $sort_types
   */
  private function parse_for_sorting($param, &$sort, &$sort_types) {
    if (!is_array($sort)) {
      $sort = array();
    }
    if ($param->sort) {
      $random = FALSE;
      $sorts = (is_array($param->sort) ? $param->sort : array($param->sort));
      $sort_types = $this->config->get_value('sort', 'setup');
      foreach ($sorts as $s) {
        if (!isset($sort_types[$s->_value])) {
          return 'Error: Unknown sort: ' . $s->_value;
        }
        $random = $random || ($s->_value == 'random');
        if ($random && count($sort)) {
          return 'Error: Random sorting can only be used alone';
        }
        $sort[] = $s->_value;
      }
    }
  }

  /** \brief Selects a ranking scheme depending on some register frequency lookups
   *
   * @param $solr_query array - the parsed user query
   * @param $guesses array - registers to get frequence for
   * @param $user_filter string - filter query as set by users profile
   *
   * $return string - the ranking scheme with highest number of hits
   *
   */
  private function guess_rank($solr_query, $guesses, $user_filter) {
    $guess_filter = array();
    if ($freq_filter = $this->config->get_value('frequency_filter', 'setup')) {
      foreach ($freq_filter as $f) {
        if ($f == 'user_profile') {
          $guess_filter[] = $user_filter;
        }
        else {
          $guess_filter[] = rawurlencode($f);
        }
      }
    }
    
    $freqs = self::get_register_freqency($solr_query['edismax'], array_keys($guesses), $guess_filter);
    $max = -1;
    $idx = 0;
    foreach ($guesses as $reg => $rank) {
      $this->rank_frequence_debug->$reg->_value = $freqs[$idx];
      $debug_str .= $rank . '(' . $freqs[$idx] . ') ';
      if ($freqs[$idx] > $max) {
        $ret = $rank;
        $max = $freqs[$idx];
      }
      $idx++;
    }
    verbose::log(DEBUG, 'Rank frequency: ' . $ret . ' from: ' . $debug_str);
    return $ret;

  }

  /** \brief Validate sort and rank
   *
   */
  private function validate_rank_sort($param, $rank, $sort) {
  }

  /** \brief Encapsules how to get the data from the first element
   *
   */
  private function get_first_solr_element($solr_arr, $element) {
    if ($this->collapsing_field) {
      $solr_docs = &$solr_arr['grouped'][$this->collapsing_field]['groups'][0]['doclist']['docs'];
    }
    else {
      $solr_docs = &$solr_arr['response']['docs'];
    }
    return self::scalar_or_first_elem($solr_docs[0][$element]);
  }

  /** \brief Encapsules how to get hit count from the solr result
   *
   */
  private function get_num_found($solr_arr) {
    if ($this->collapsing_field) {
      return self::get_num_grouped($solr_arr);
    }
    else {
      return self::get_num_response($solr_arr);
    }
  }

  private function get_num_grouped($solr_arr) {
    return $solr_arr['grouped'][$this->collapsing_field]['ngroups'];
  } 

  private function get_num_response($solr_arr) {
    return $solr_arr['response']['numFound'];
  } 

  /** \brief Encapsules extraction of unit.id's from the solr result
   *
   */
  private function extract_unit_id_from_solr($solr_arr, &$search_ids) {
    static $u_err = 0;
    $search_ids = array();
    if ($this->collapsing_field) {
      $solr_groups = &$solr_arr['grouped'][$this->collapsing_field]['groups'];
      foreach ($solr_groups as &$gdoc) {
        if ($uid = $gdoc['doclist']['docs'][0]['unit.id']) {
          $search_ids[] = self::scalar_or_first_elem($uid);
        }
        elseif (++$u_err < 10) {
          verbose::log(FATAL, 'Missing unit.id in solr_result. Record no: ' . (count($search_ids) + $u_err));
        }
      }
    }
    else {
      $solr_docs = &$solr_arr['response']['docs'];
      foreach ($solr_docs as &$fdoc) {
        if ($uid = $fdoc['unit.id']) {
          $search_ids[] = self::scalar_or_first_elem($uid);
        }
        elseif (++$u_err < 10) {
          verbose::log(FATAL, 'Missing unit.id in solr_result. Record no: ' . (count($search_ids) + $u_err));
        }
      }
    }
  }

  /** \brief Return first element of array or the element for scalar vars
   *
   */
  private function scalar_or_first_elem($mixed) {
    if (is_array($mixed) || is_object($mixed)) {
      return reset($mixed);
    }
    return $mixed;
  }

  /** \brief decides which formats to include in result and how the should be build
   *
   */
  private function set_format($objectFormat, $open_format, $solr_format) {
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
      elseif ($solr_format[$of->_value]) {
        $ret[$of->_value] = array('user_selected' => TRUE, 'is_solr_format' => TRUE, 'format_name' => $solr_format[$of->_value]['format']);
        $ret['found_solr_format'] = TRUE;
      }
      else {
        $ret[$of->_value] = array('user_selected' => TRUE, 'is_solr_format' => FALSE);
      }
    }
    if ($ret['found_open_format'] || $ret['found_solr_format']) {
      if (empty($ret['dkabm']))
        $ret['dkabm'] = array('user_selected' => FALSE, 'is_open_format' => FALSE);
      if (empty($ret['marcxchange']))
        $ret['marcxchange'] = array('user_selected' => FALSE, 'is_open_format' => FALSE);
    }
    return $ret;
  }

  /** \brief Fetch holding from extern web service
   *
   */
  private function get_holdings($pid) {
    static $hold_ws_url;
    static $dom;
    if (empty($hold_ws_url)) {
      $hold_ws_url = $this->config->get_value('holdings_db', 'setup');
    }
    $this->watch->start('holdings');
    $hold_url = sprintf($hold_ws_url, $pid);
    $holds = $this->curl->get($hold_url);
    $this->watch->stop('holdings');
    $curl_err = $this->curl->get_status();
    if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
      verbose::log(FATAL, 'holdings_db http-error: ' . $curl_err['http_code'] . ' from: ' . $hold_url);
      $holds = array('have' => 0, 'lend' => 0);
    }
    else {
      if (empty($dom)) {
        $dom = new DomDocument();
      }
      $dom->preserveWhiteSpace = false;
      if (@ $dom->loadXML($holds)) {
        $holds = array('have' => $dom->getElementsByTagName('librariesHave')->item(0)->nodeValue,
                       'lend' => $dom->getElementsByTagName('librariesLend')->item(0)->nodeValue);
      }
    }
    return $holds;
  }

  /** \brief Pick tags from solr result and create format
   *
   */
  private function format_solr(&$collections, $solr, &$work_ids, $fpid_sort_keys = array()) {
    $solr_display_ns = $this->xmlns['ds'];
    $this->watch->start('format_solr');
    foreach ($this->format as $format_name => $format_arr) {
      if ($format_arr['is_solr_format']) {
        $format_tags = explode(',', $format_arr['format_name']);
        foreach ($collections as $idx => &$c) {
          $rec_no = $c->_value->collection->_value->resultPosition->_value;
          foreach ($work_ids[$rec_no] as $mani_no => $unit_no) {
            if (is_array($solr[0]['response']['docs'])) {
              $fpid = $c->_value->collection->_value->object[$mani_no]->_value->identifier->_value;
              foreach ($solr[0]['response']['docs'] as $solr_doc) {
                $doc_units = is_array($solr_doc['unit.id']) ? $solr_doc['unit.id'] : array($solr_doc['unit.id']);
                if (is_array($doc_units) && in_array($unit_no, $doc_units)) {
                  foreach ($format_tags as $format_tag) {
                    if ($solr_doc[$format_tag] || $format_tag == 'fedora.identifier') {
                      if (strpos($format_tag, '.')) {
                        list($tag_NS, $tag_value) = explode('.', $format_tag);
                      }
                      else {
                        $tag_value = $format_tag;
                      }
                      if ($format_tag == 'fedora.identifier') {
                        $mani->_value->$tag_value->_namespace = $solr_display_ns;
                        $mani->_value->$tag_value->_value = $fpid;
                      }
                      else {
                        if (is_array($solr_doc[$format_tag])) {
                          if (TRUE) {
                            $mani->_value->$tag_value->_namespace = $solr_display_ns;
                            $mani->_value->$tag_value->_value = self::normalize_chars($solr_doc[$format_tag][0]);
                          }
                          else {
                            foreach ($solr_doc[$format_tag] as $solr_tag) {
                              $help->_namespace = $solr_display_ns;
                              $help->_value = self::normalize_chars($solr_tag);
                              $mani->_value->{$tag_value}[] = $help;
                              unset($help);
                            }
                          }
                        }
                        else {
                          $mani->_value->$tag_value->_namespace = $solr_display_ns;
                          $mani->_value->$tag_value->_value = self::normalize_chars($solr_doc[$format_tag]);
                        }
                      }
                    }
                  }
                  break;
                }
              }
            }
            if ($mani) {   // should contain data, but for some odd reason it can be empty. Some bug in the solr-indexes?
              $mani->_namespace = $solr_display_ns;
              $sort_key = $fpid_sort_keys[$fpid] . sprintf('%04d', $mani_no);
		      $manifestation->manifestation[$sort_key] = $mani;
            }
            unset($mani);
          }
// need to loop thru objects to put data correct
          if (is_array($manifestation->manifestation)) {
            ksort($manifestation->manifestation);
          }
          $c->_value->formattedCollection->_value->$format_name->_namespace = $solr_display_ns;
          $c->_value->formattedCollection->_value->$format_name->_value = $manifestation;
          unset($manifestation);
        }
      }
    }
    $this->watch->stop('format_solr');
  }

  /** \brief Setup call to OpenFormat and execute the format request
   * If ws_open_format_uri is set, the format request is send to that server otherwise
   * openformat is included using the [format] section from config
   *
   */
  private function format_records(&$collections) {
    static $formatRecords;
    $this->watch->start('format');
    foreach ($this->format as $format_name => $format_arr) {
      if ($format_arr['is_open_format']) {
        if ($open_format_uri = $this->config->get_value('ws_open_format_uri', 'setup')) {
          $f_obj->formatRequest->_namespace = $this->xmlns['of'];
          $f_obj->formatRequest->_value->originalData = $collections;
  // need to set correct namespace
          foreach ($f_obj->formatRequest->_value->originalData as $i => &$oD) {
            $save_ns[$i] = $oD->_namespace;
            $oD->_namespace = $this->xmlns['of'];
          }
          $f_obj->formatRequest->_value->outputFormat->_namespace = $this->xmlns['of'];
          $f_obj->formatRequest->_value->outputFormat->_value = $format_arr['format_name'];
          $f_obj->formatRequest->_value->outputType->_namespace = $this->xmlns['of'];
          $f_obj->formatRequest->_value->outputType->_value = 'php';
          $f_obj->formatRequest->_value->trackingId->_value = $this->tracking_id;
          $f_xml = $this->objconvert->obj2soap($f_obj);
          $this->curl->set_post($f_xml);
          $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: text/xml; charset=UTF-8'));
          $f_result = $this->curl->get($open_format_uri);
          $this->curl->set_option(CURLOPT_POST, 0, 0);
          //$fr_obj = unserialize($f_result);
          $fr_obj = $this->objconvert->set_obj_namespace(unserialize($f_result), $this->xmlns['of']);
  // need to restore correct namespace
          foreach ($f_obj->formatRequest->_value->originalData as $i => &$oD) {
            $oD->_namespace = $save_ns[$i];
          }
          if (!$fr_obj) {
            $curl_err = $this->curl->get_status();
            verbose::log(FATAL, 'openFormat http-error: ' . $curl_err['http_code'] . ' from: ' . $open_format_uri);
          }
          else {
            $struct = key($fr_obj->formatResponse->_value);
            // if ($struct == 'error') ... 
            foreach ($collections as $idx => &$c) {
              $c->_value->formattedCollection->_value->{$struct} = $fr_obj->formatResponse->_value->{$struct}[$idx];
            }
          }
        }
        else {
          require_once('OLS_class_lib/format_class.php');
          if (empty($formatRecords)) {
            $formatRecords = new FormatRecords($this->config->get_section('format'), $this->xmlns['of'], $this->objconvert, $this->xmlconvert, $this->watch);
          }
          $param->outputFormat->_value = $format_arr['format_name'];
          $param->outputFormat->_namespace = $this->xmlns['of'];
          $param->originalData = $collections;
  // need to set correct namespace
          foreach ($param->originalData as $i => &$oD) {
            $save_ns[$i] = $oD->_namespace;
            $oD->_namespace = $this->xmlns['of'];
          }
          $f_result = $formatRecords->format($param->originalData, $param);
          $fr_obj = $this->objconvert->set_obj_namespace($f_result, $this->xmlns['os']);
  // need to restore correct namespace
          foreach ($param->originalData as $i => &$oD) {
            $oD->_namespace = $save_ns[$i];
          }
          if (!$fr_obj) {
            $curl_err = $formatRecords->get_status();
            verbose::log(FATAL, 'openFormat http-error: ' . $curl_err[0]['http_code'] . ' - check [format] settings in ini-file');
          }
          else {
            $struct = key($fr_obj[0]);
            foreach ($collections as $idx => &$c) {
              $c->_value->formattedCollection->_value->{$struct} = $fr_obj[$idx]->{$struct};
            }
          }
        }
      }
    }
    $this->watch->stop('format');
  }

  /** \brief Remove private/internal subfields from the marcxchange record
   * If all subfields in a field are removed, the field is removed as well
   * Controlled by the repository filter structure set in the services ini-file
   * @param $collections (array)
   * @param $filters (array) 
   *
   */
  private function filter_marcxchange_records(&$collections, $filters) {
    if (is_array($filters)) {
      foreach ($collections as $idx => &$c) {
        foreach ($c->_value->collection->_value->object as &$o) {
          if ($o->_value->collection) {
            @ $mrec = &$o->_value->collection->_value->record->_value;
            foreach ($mrec->datafield as $idf => &$df) {
              foreach ($filters as $tag => $filter) {
                if (preg_match('/' . $tag . '/', $df->_attributes->tag->_value)) {
                  if (is_array($df->_value->subfield)) {
                    foreach ($df->_value->subfield as $isf => &$sf) {
                      if (preg_match('/' . $filter . '/', $sf->_attributes->code->_value)) {
                        unset($mrec->datafield[$idf]->_value->subfield[$isf]);
                      }
                    }
                    if (!count($df->_value->subfield)) {  // removed all subfield
                      unset($mrec->datafield[$idf]);
                    }
                  }
                  elseif (preg_match('/' . $filter . '/', $df->_value->subfield->_attributes->code->_value)) {
                    unset($mrec->datafield[$idf]);
                  }
                }
              }
            }
          }
        }
      }
    }
  }

  /** \brief Remove not asked for formats from result
   * @param $collections (array)
   *
   */
  private function remove_unselected_formats(&$collections) {
    foreach ($collections as $idx => &$c) {
      foreach ($c->_value->collection->_value->object as &$o) {
        if (!$this->format['dkabm']['user_selected'])
          unset($o->_value->record);
        if (!$this->format['marcxchange']['user_selected'])
          unset($o->_value->collection);
      }
    }
  }

  /** \brief Check whether an object i deleted or not
   * @param $fpid (string)
   *
   */
  private function deleted_object($fpid) {
    static $dom;
    $state = '';
    if ($obj_url = $this->repository['fedora_get_object_profile']) {
      self::get_fedora($obj_url, $fpid, $obj_rec);
      if ($obj_rec) {
        if (empty($dom))
          $dom = new DomDocument();
        $dom->preserveWhiteSpace = false;
        if (@ $dom->loadXML($obj_rec))
          $state = $dom->getElementsByTagName('objState')->item(0)->nodeValue;
      }
    }
    return $state == 'D';
  }

  /** \brief Fetch a raw record from fedora
   *
   */
  private function get_fedora_raw($fpid, &$fedora_rec, $datastream_id = 'commonData') {
    return self::get_fedora(str_replace('commonData', $datastream_id, $this->repository['fedora_get_raw']), $fpid, $fedora_rec);
  }

  /** \brief Fetch a rels_addi record from fedora
   *
   */
  private function get_fedora_rels_addi($fpid, &$fedora_rel) {
    if ($this->repository['fedora_get_rels_addi']) {
      return self::get_fedora($this->repository['fedora_get_rels_addi'], $fpid, $fedora_rel, FALSE);
    }
    else {
      return FALSE;
    }
  }

  /** \brief Fetch a rels_hierarchy record from fedora
   *
   */
  private function get_fedora_rels_hierarchy($fpid, &$fedora_rel) {
    return self::get_fedora($this->repository['fedora_get_rels_hierarchy'], $fpid, $fedora_rel);
  }

  /** \brief Fetch datastreams for a record from fedora
   *
   */
  private function get_fedora_datastreams($fpid, &$fedora_obj) {
    return self::get_fedora($this->repository['fedora_get_datastreams'], $fpid, $fedora_obj);
  }

  /** \brief Setup call to fedora and execute it
   *
   */
  private function get_fedora($uri, $fpid, &$rec, $mandatory=TRUE) {
    $record_uri =  sprintf($uri, $fpid);
    verbose::log(TRACE, 'get_fedora: ' . $record_uri);
    if (DEBUG_ON) echo 'Fetch record: ' . $record_uri . "\n";
    if ($this->cache && ($rec = $this->cache->get($record_uri))) {
      $this->number_of_fedora_cached++;
    }
    else {
      $this->number_of_fedora_calls++;
      $this->curl->set_authentication('fedoraAdmin', 'fedoraAdmin');
      $this->watch->start('fedora');
      $rec = self::normalize_chars($this->curl->get($record_uri));
      $this->watch->stop('fedora');
      $curl_err = $this->curl->get_status();
      if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
        $rec = '';
        if ($mandatory) {
          if ($curl_err['http_code'] == 404) {
            return 'record_not_found';
          }
          verbose::log(FATAL, 'Fedora http-error: ' . $curl_err['http_code'] . ' from: ' . $record_uri);
          return 'Error: Cannot fetch record: ' . $fpid . ' - http-error: ' . $curl_err['http_code'];
        }
      }
      if ($this->cache) $this->cache->set($record_uri, $rec);
    }
    // else verbose::log(TRACE, 'Fedora cache hit for ' . $fpid);
    return;
  }

  /** \brief Build Solr filter_query parm
   *
   */
  private function set_solr_filter($profile) {
    $ret = '';
    foreach ($profile as $p) {
      if (self::xs_boolean($p['sourceSearchable'])) {
        $ret .= ($ret ? ' OR ' : '') . 'rec.collectionIdentifier:' . $p['sourceIdentifier'];
      }
    }
    return $ret;
  }

  /** \brief Check an external relation against the search_profile
   *
   */
  private function check_valid_external_relation($collection, $relation, $profile) {
    self::set_valid_relations_and_sources($profile);
    $valid = isset($this->valid_relation[$collection][$relation]);
    if (DEBUG_ON) {
      echo "from: $collection relation: $relation - " . ($valid ? '' : 'no ') . "go\n";
    }
    return $valid;
  }

  /** \brief Check an internal relation against the search_profile
   *
   */
  private function check_valid_internal_relation($unit_id, $relation, $profile) {
    self::set_valid_relations_and_sources($profile);
    self::get_fedora_rels_hierarchy($unit_id, $rels_hierarchy);
    $pid = self::fetch_primary_bib_object($rels_hierarchy);
    foreach (self::find_record_sources_and_group_by_relation($pid, $relation) as $to_record_source) {
      $valid = isset($this->valid_relation[$to_record_source][$relation]);
      if (DEBUG_ON) {
        echo "pid: $pid to: $to_record_source relation: $relation - " . ($valid ? '' : 'no ') . "go\n";
      }
      if ($valid) {
        return $to_record_source;
      }
    }

    return FALSE;
  }

  /** \brief find sources from pid and the local datastreams of the object
   *  group the pid using the relation_group_source_tab 
   *
   */
  private function find_record_sources_and_group_by_relation($pid, $relation) {
    static $group_source_tab;
    if (!isset($group_source_tab)) {
      $group_source_tab = $this->config->get_value('relation_group_source_tab', 'setup');
    }
    $sources = self::fetch_valid_sources_from_stream($pid);
    $record_source = self::record_source_from_pid($pid);
    list($agency, $collection) = self::split_record_source($record_source);
    if ($group_source = $group_source_tab[$relation][self::get_agency_type($agency)][$collection]) {
      $sources[] = $group_source;
    }
    else {
      $sources[] = $record_source;
    }
    return $sources;
  }

  /** \brief finds the local datastreams for a given object
   *
   */
  private function fetch_valid_sources_from_stream($pid) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = false;
    }
    self::get_fedora_datastreams($pid, $ds_xml);
    $ret = array();
    if (@ $dom->loadXML($ds_xml)) {
      foreach ($dom->getElementsByTagName('datastream') as $tag) {
        list($localData, $stream) = explode('.', $tag->getAttribute('dsid'), 2);
        if (!empty($stream) && ($localData == 'localData')) {
          $ret[] = $stream;
        }
      }
      if (DEBUG_ON) {
        echo 'datastreams: ' . implode('; ', $ret) . PHP_EOL;
      }
    }
    return $ret;
  }

  /** \brief sets valid relations from the search profile
   *
   */
  private function set_valid_relations_and_sources($profile) {
    if (empty($this->valid_relation)) {
      foreach ($profile as $src) {
        $this->valid_source[$src['sourceIdentifier']] = TRUE;
        if ($src['relation']) {
          foreach ($src['relation'] as $rel) {
            if ($rel['rdfLabel'])
              $this->valid_relation[$src['sourceIdentifier']][$rel['rdfLabel']] = TRUE;
            if ($rel['rdfInverse'])
              $this->valid_relation[$src['sourceIdentifier']][$rel['rdfInverse']] = TRUE;
          }
        }
      }

      if (DEBUG_ON) {
        print_r($profile);
        echo "rels:\n"; print_r($this->valid_relation); echo "source:\n"; print_r($this->valid_source);
      }
    }
  }

  /** \brief Fetch agency types from OpenAgency, cache the result, and return agency type for $agency
   *
   */
  private function get_agency_type($agency) {
    static $agency_type_tab;
    if (!isset($agency_type_tab)) {
      require_once 'OLS_class_lib/agency_type_class.php';
      $cache = self::get_agency_cache_info();
      $agency_types = new agency_type($this->config->get_value('agency_types', 'setup'), 
                                         $cache['host'], $cache['port'], $cache['expire']);
    }
    $agency_type = $agency_types->get_agency_type($agency);
    if ($agency_types->get_branch_type($agency) <> 'D')
      return $agency_type;

    return FALSE;
  }

  /** \brief Extract source part of an ID
   *
   */
  private function record_source_from_pid($id) {
    list($ret, $dummy) = explode(':', $id, 2);
    return $ret;
  }

  /** \brief Split a record source
   *
   */
  private function split_record_source($record_source) {
    return explode('-', $record_source, 2);
  }

  /** \brief Fetch a profile $profile_name for agency $agency
   *
   */
  private function fetch_profile_from_agency($agency, $profile_name) {
    require_once 'OLS_class_lib/search_profile_class.php';
    $cache = self::get_agency_cache_info();
    $profiles = new search_profiles($this->config->get_value('agency_search_profile', 'setup'), 
                                    $cache['host'], $cache['port'], $cache['expire']);
    $profile = $profiles->get_profile($agency, $profile_name, $this->search_profile_version);
    if (is_array($profile)) {
      return $profile;
    }
    else {
      return FALSE;
    }
  }

  /** \brief Get info for OpenAgency cache style/setup
   *
   */
  private function get_agency_cache_info() {
    if (!($ret['host'] = $this->config->get_value('agency_cache_host', 'setup')))
      $ret['host'] = $this->config->get_value('cache_host', 'setup');
    if (!($ret['port'] = $this->config->get_value('agency_cache_port', 'setup')))
      $ret['port'] = $this->config->get_value('cache_port', 'setup');
    if (!($ret['expire'] = $this->config->get_value('agency_cache_expire', 'setup')))
      $ret['expire'] = $this->config->get_value('cache_expire', 'setup');
    return $ret;
  }

  /** \brief Build bq (BoostQuery) as field:content^weight
   *
   */
  private static function boostUrl($boost) {
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
    $solr_urls[0] = self::create_solr_url($q, $start, $rows, $filter, $sort, $rank, $facets, $boost, $debug, $this->collapsing_field);
    return self::do_solr($solr_urls, $solr_arr);
  }

  /** \brief fetch hit count for each register in a given list
   *
   * @param $eq array - the edismax part of the parsed user query
   * @param $guesses array - registers to get frequence for
   * @param $filters array - filter query/queries
   *
   * $return array - hitcount for each register
   */
  private function get_register_freqency($eq, $registers, $filters) {
    $q = implode(' and ', $eq['q']);
    $filter = implode('&fq=', $filters);
    foreach ($eq['fq'] as $fq) {
      $filter .= '&fq=' . rawurlencode($fq);
    }
    foreach ($registers as $reg_name => $reg_value) {
      $solr_urls[]['url'] = $this->repository['solr'] .  
                            '?q=' . $reg_value . '%3A(' . urlencode($q) .  ')&fq=' . $filter .  '&start=1&rows=0&wt=phps';
      $ret[$reg_name] = 0;
    }
    $err = self::do_solr($solr_urls, $solr_arr);
    $n = 0;
    foreach ($registers as $reg_name => $reg_value) {
      $ret[$reg_name] = self::get_num_response($solr_arr[$n++]);
    }
    return $ret;
  }

  /** \brief build a solr url from a variety of parameters (and an url for debugging)
   *
   */
  private function create_solr_url($eq, $start, $rows, $filter, $sort='', $rank='', $facets='', $boost='', $debug=FALSE, $collapsing=FALSE) {
    if ($collapsing) {
      $collaps_pars = '&group=true&group.ngroups=true&group.facet=true&group.field=' . $collapsing;
    }
    $q = implode(' and ', $eq['q']);
    foreach ($eq['fq'] as $fq) {
      $filter .= '&fq=' . rawurlencode($fq);
    }
    $url = $this->repository['solr'] .
                    '?q=' . urlencode($q) .
                    '&fq=' . $filter .
                    '&start=' . $start .  $sort . $rank . $boost . $facets .  $collaps_pars .
                    '&defType=edismax';
    $debug_url = $url . '&fl=fedoraPid,unit.id&rows=1&debugQuery=on';
    $url .= '&fl=unit.id&wt=phps&rows=' . $rows . ($debug ? '&debugQuery=on' : '');

    return array('url' => $url, 'debug' => $debug_url);
  }

  /** \brief send one or more requests to Solr
   *
   */
  private function do_solr($urls, &$solr_arr) {
    foreach ($urls as $no => $url) {
      verbose::log(TRACE, 'Query: ' . $url['url']);
      verbose::log(DEBUG, 'Query: ' . $url['debug']);
      $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: text/plain; charset=utf-8'), $no);
      $this->curl->set_url($url['url'], $no);
    }
    $solr_results = $this->curl->get();
    $this->curl->close();
    if (empty($solr_results))
      return 'Internal problem: No answer from Solr';
    if (count($urls) > 1) {
      foreach ($solr_results as &$solr_result) {
        if (!$solr_arr[] = unserialize($solr_result)) {
          return 'Internal problem: Cannot decode Solr result';
        }
      }
    }
    elseif (!$solr_arr = unserialize($solr_results)) {
      return 'Internal problem: Cannot decode Solr result';
    }
    elseif ($err = $solr_arr['error']) {
      verbose::log(FATAL, 'Solr result in error: (' . $err['code'] . ') ' . $err['msg']);
      return 'Internal problem: Solr result contains error';
    }
  }

  /** \brief Parse a rels-ext record and extract the unit id
   *
   */
  private function parse_rels_for_unit_id($rels_hierarchy) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = false;
    }
    if (@ $dom->loadXML($rels_hierarchy)) {
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
  private function parse_rels_for_work_id($rels_hierarchy) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    $dom->preserveWhiteSpace = false;
    if (@ $dom->loadXML($rels_hierarchy))
      $imo = $dom->getElementsByTagName('isPrimaryUnitObjectFor');
      if (is_object($imo) && $imo->item(0))
        return($imo->item(0)->nodeValue);
      else {
        $imo = $dom->getElementsByTagName('isMemberOfWork');
        if (is_object($imo) && $imo->item(0))
          return($imo->item(0)->nodeValue);
      }

    return FALSE;
  }

  /** \brief Fetch id for primaryBibObject
   *
   */
  private function fetch_primary_bib_object($u_rel) {
    $arr = self::parse_unit_for_object_ids($u_rel);
    return $arr[0];
  }

  /** \brief Parse a unit object and return the id of the users object ($this->agency_catalog_source)
   *         or the id of the primaryBibObject
   *
   * @param $u_rel (string) xml of the unit object
   * @return (array) of object_id and number of members in the unit
   */
  private function parse_unit_for_object_ids($u_rel) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = false;
    }
    $oid = $length = FALSE;
    if (@ $dom->loadXML($u_rel)) {
      $hpbo = $dom->getElementsByTagName('hasPrimaryBibObject');
      if ($hpbo->item(0)) {
        $oid = $hpbo->item(0)->nodeValue;
      }
      $hmou = $dom->getElementsByTagName('hasMemberOfUnit');
      $length = $hmou->length;
      foreach ($hmou as $mou) {
        if ($this->agency_catalog_source == self::record_source_from_pid($mou->nodeValue)) {
          $oid = $mou->nodeValue;
          break;
        }
      }
    }
    return(array($oid, $length));
  }

  /** \brief Parse a work relation and return array of ids
   *
   * @param $w_rel (string) xml of the work object
   * @param $uid (string) id of the unit pointing to the work object
   * @return (array) of id's found in the work object. The first element is always set to $uid
   */
  private function parse_work_for_object_ids($w_rel, $uid) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = false;
    }
    if (@ $dom->loadXML($w_rel)) {
      $res = array();
      $res[] = $uid;
      //$hpuo = $dom->getElementsByTagName('hasPrimaryUnitObject');
      //if ($hpuo->item(0))
        //$res[] = $puo = $hpuo->item(0)->nodeValue;
      $r_list = $dom->getElementsByTagName('hasMemberOfWork');
      foreach ($r_list as $r) {
        if ($r->nodeValue <> $uid) $res[] = $r->nodeValue;
      }
      return $res;
    }
  }

  /** \brief Parse a fedora object and extract record and relations
   *
   * @param $fedora_obj      - the bibliographic record from fedora
   * @param $fedora_addi_obj - corresponding relation object
   * @param $rels_type       - level for returning relations
   * @param $rec_id          - record id of the record
   * @param $filter          - agency filter
   * @param $debug_info      -
   */
  private function parse_fedora_object(&$fedora_obj, $fedora_addi_obj, $rels_type, $rec_id, $primary_id, $filter, $holdings_count, $debug_info='') {
    static $fedora_dom;
    if (empty($fedora_dom)) {
      $fedora_dom = new DomDocument();
      $fedora_dom->preserveWhiteSpace = false;
    }
    if (@ !$fedora_dom->loadXML($fedora_obj)) {
      verbose::log(FATAL, 'Cannot load recid ' . $rec_id . ' into DomXml');
      return;
    }

    $rec = self::extract_record($fedora_dom, $rec_id);

    if (in_array($rels_type, array('type', 'uri', 'full'))) {
      self::get_relations_from_datastream_domobj($relations, $fedora_dom, $rels_type);
      self::get_relations_from_addi_stream($relations, $fedora_addi_obj, $rels_type, $filter);
    }

    $ret = $rec;
    $ret->identifier->_value = $rec_id;
    if ($primary_id) {
      $ret->primaryObjectIdentifier->_value = $primary_id;
    }
    if ($cd = self::get_creation_date($fedora_dom)) {
      $ret->creationDate->_value = $cd;
    }
// hack
    if (empty($ret->creationDate->_value) && (strpos($rec_id, 'tsart:') || strpos($rec_id, 'avis:'))) {
      unset($holdings_count);
    }
    if (is_array($holdings_count)) {
      $ret->holdingsCount->_value = $holdings_count['have'];
      $ret->lendingLibraries->_value = $holdings_count['lend'];
    }
    if ($relations) $ret->relations->_value = $relations;
    $ret->formatsAvailable->_value = self::scan_for_formats($fedora_dom);
    if ($debug_info) $ret->queryResultExplanation->_value = $debug_info;
    //if (DEBUG_ON) var_dump($ret);

    //print_r($ret);
    //exit;

    return $ret;
  }

  /** \brief Check if a record is searchable
   *
   */
  private function is_searchable($unit_id, $filter_q) {
// do not check for searchability, since the relation is found in the search_profile, it's ok to use it
    return TRUE;
    if (empty($filter_q)) return TRUE;

    self::get_solr_array('unit.id:' . str_replace(':', '\:', $unit_id), 1, 0, '', '', '', rawurlencode($filter_q), '', '', $solr_arr);
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

    if (($p = &$dom->getElementsByTagName('container')->item(0)) ||
        ($p = &$dom->getElementsByTagName('localData')->item(0))) {
      foreach ($p->childNodes as $tag) {
        if ($x = &$form_table[$tag->tagName])
          $ret->format[]->_value = $x;
      }
    }

    return $ret;
  }

  /** \brief Handle relations in commonData streams
   * @param relations (object) return parameter, the relations found
   * @param rec_id (string) commonData record id
   * @param rels_type (string) type, uri or full
   *
   */
  private function get_relations_from_commonData_stream(&$relations, $rec_id, $rels_type) {
    static $stream_dom;
    self::get_fedora_raw($rec_id, $fedora_streams);
    if (empty($stream_dom)) {
      $stream_dom = new DomDocument();
    }
    if (@ !$stream_dom->loadXML($fedora_streams)) {
      verbose::log(DEBUG, 'Cannot load STREAMS for ' . $rec_id . ' into DomXml');
    } else {
      self::get_relations_from_datastream_domobj($relations, $stream_dom, $rels_type);
    }
  }

  /** \brief Handle relations located in commonData/localData streams in dom representation
   * @param relations (object) return parameter, the relations found
   * @param stream_dom (domDocument) commonData or localData stream
   * @param rels_type (string) type, uri or full
   *
   */
  private function get_relations_from_datastream_domobj(&$relations, &$stream_dom, $rels_type) {
    $dub_check = array();
    foreach ($stream_dom->getElementsByTagName('link') as $link) {
      $url = $link->getelementsByTagName('url')->item(0)->nodeValue;
      if (empty($dup_check[$url])) {
        $this_relation = $link->getelementsByTagName('relationType')->item(0)->nodeValue;
        unset($lci);
        $relation_ok = FALSE;
        foreach ($link->getelementsByTagName('collectionIdentifier') as $collection) {
          $relation_ok = $relation_ok || 
                            self::check_valid_external_relation($collection->nodeValue, $this_relation, $this->search_profile);
          $lci[]->_value = $collection->nodeValue;
        }
        if ($relation_ok) {
          if (!$relation->relationType->_value = $this_relation) {   // ????? WHY - is relationType sometimes empty?
            $relation->relationType->_value = $link->getelementsByTagName('access')->item(0)->nodeValue;
          }
          if ($rels_type == 'uri' || $rels_type == 'full') {
            $relation->relationUri->_value = $url;
            if ($nv = $link->getelementsByTagName('accessType')->item(0)->nodeValue) {
              $relation->linkObject->_value->accessType->_value = $nv;
            }
            if ($nv = $link->getelementsByTagName('access')->item(0)->nodeValue) {
              $relation->linkObject->_value->access->_value = $nv;
            }
            $relation->linkObject->_value->linkTo->_value = $link->getelementsByTagName('linkTo')->item(0)->nodeValue;
            if ($lci) {
              $relation->linkObject->_value->linkCollectionIdentifier = $lci;
            }
          }
          $dup_check[$url] = TRUE;
          $relations->relation[]->_value = $relation;
          unset($relation);
        }
      }
    }
  }

  /** \brief Handle relations comming from addi streams
   *
   */
  private function get_relations_from_addi_stream(&$relations, $fedora_addi_obj, $rels_type, $filter) {
    static $rels_dom;
    if (empty($rels_dom)) {
      $rels_dom = new DomDocument();
    }
    @ $rels_dom->loadXML($fedora_addi_obj);
    if ($rels_dom->getElementsByTagName('Description')->item(0)) {
      $relation_count = array();
      foreach ($rels_dom->getElementsByTagName('Description')->item(0)->childNodes as $tag) {
        if ($tag->nodeType == XML_ELEMENT_NODE) {
          if ($rel_prefix = array_search($tag->getAttribute('xmlns'), $this->xmlns))
            $this_relation = $rel_prefix . ':' . $tag->localName;
          else
            $this_relation = $tag->localName;
          if ($relation_count[$this_relation]++ < MAX_IDENTICAL_RELATIONS &&
              $rel_source = self::check_valid_internal_relation($tag->nodeValue, $this_relation, $this->search_profile)) {
            self::get_fedora_rels_hierarchy($tag->nodeValue, $rels_sys);
            $rel_uri = self::fetch_primary_bib_object($rels_sys);
            self::get_fedora_raw($rel_uri, $related_obj);
            if (@ !$rels_dom->loadXML($related_obj)) {
              verbose::log(FATAL, 'Cannot load ' . $rel_uri . ' object from commonData into DomXml');
              $rels_dom = NULL;
            }
            $collection_id = self::get_element_from_admin_data($rels_dom, 'collectionIdentifier');
            if (empty($this->valid_relation[$collection_id])) {  // handling of local data streams
              if (DEBUG_ON) { 
                echo 'Datastream(s): ' . implode(',', self::fetch_valid_sources_from_stream($rel_uri)) . PHP_EOL;
              }
              foreach (self::fetch_valid_sources_from_stream($rel_uri) as $source) {
                if ($this->valid_relation[$source]) {
                  if (DEBUG_ON) { 
                    echo '--- use: ' . $source . PHP_EOL;
                  }
                   
                  $collection_id = $source;
                  self::get_fedora_raw($rel_uri, $related_obj, self::set_data_stream_name($collection_id));
                  if (@ !$rels_dom->loadXML($related_obj)) {
                    verbose::log(FATAL, 'Cannot load ' . $rel_uri . ' object from ' . $source . ' into DomXml');
                    $rels_dom = NULL;
                  }
                  break;
                }
              }
            }
            if (isset($this->valid_relation[$collection_id]) && self::is_searchable($tag->nodeValue, $filter)) {
              $relation->relationType->_value = $this_relation;
              if ($rels_type == 'uri' || $rels_type == 'full') {
                $relation->relationUri->_value = $rel_uri;
              }
              if (is_object($rels_dom) && ($rels_type == 'full')) {
                $rel_obj = &$relation->relationObject->_value->object->_value;
                $rel_obj = self::extract_record($rels_dom, $tag->nodeValue);
                $rel_obj->identifier->_value = $rel_uri;
                if ($cd = self::get_creation_date($rels_dom)) {
                  $rel_obj->creationDate->_value = $cd;
                }
                self::get_relations_from_commonData_stream($ext_relations, $rel_uri, $rels_type);
                if ($ext_relations) {
                  $rel_obj->relations->_value = $ext_relations;
                  unset($ext_relations);
                }
                $rel_obj->formatsAvailable->_value = self::scan_for_formats($rels_dom);
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
  }

  /** \brief gets a given element from the adminData part
   *
   */
  private function get_element_from_admin_data(&$dom, $tag_name) {
    if ($ads = $dom->getElementsByTagName('adminData')->item(0)) {
      if ($cis = $ads->getElementsByTagName($tag_name)->item(0)) {
         return($cis->nodeValue);
      }
    }
    return NULL;
  }

  /** \brief Extract record and namespace for it
   *
   */
  private function extract_record(&$dom, $rec_id) {
    foreach ($this->format as $format_name => $format_arr) {
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
                  $o->_value = trim($tag->nodeValue);
                  if ($tag->localName && !($tag->localName == 'subject' && $tag->nodeValue == 'undefined'))
                    $rec->{$tag->localName}[] = $o;
                  unset($o);
                }
//              }
            }
          }
          else {
            verbose::log(FATAL, 'No dkabm record found in ' . $rec_id);
          }
          break;
  
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

  /** \brief Handle non-standardized characters - one day maybe, this code can be deleted
   *
   */
  private function normalize_chars($s) {
    $from[] = "\xEA\x9C\xB2"; $to[] = 'Aa';
    $from[] = "\xEA\x9C\xB3"; $to[] = 'aa';
    $from[] = "\XEF\x83\xBC"; $to[] = "\xCC\x88";   // U+F0FC -> U+0308
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
  private function parse_for_facets(&$solr_arr) {
    if (is_array($solr_arr['facet_counts']['facet_fields'])) {
      foreach ($solr_arr['facet_counts']['facet_fields'] as $facet_name => $facet_field) {
        $facet->facetName->_value = $facet_name;
        foreach ($facet_field as $term => $freq) {
          if (isset($term) && isset($freq)) {
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

  /** \brief Helper function to set debug info
   *  
   */
  private function set_debug_info($solr_debug, $rank_freq_debug = '', $best_match_debug = '') {
    $ret->rawQueryString->_value = $solr_debug['rawquerystring'];
    $ret->queryString->_value = $solr_debug['querystring'];
    $ret->parsedQuery->_value = $solr_debug['parsedquery'];
    $ret->parsedQueryString->_value = $solr_debug['parsedquery_toString'];
    if ($best_match_debug) {
      $ret->bestMatch->_value = $best_match_debug;
    }
    if ($rank_freq_debug) {
      $ret->rankFrequency->_value = $rank_freq_debug;
    }
    return $ret;
  }

}

/*
 * MAIN
 */

if (!defined('PHPUNIT_RUNNING')) {
  $ws=new openSearch();

  $ws->handle_request();
}
