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
  protected $agency;
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
  protected $agency_type = '';
  protected $filter_agency = '';
  protected $format = '';
  protected $which_rec_id = '';
  protected $collapsing_field = FALSE;  // if used, defined in ini-file
  protected $separate_field_query_style = TRUE; // seach as field:(a OR b) ie FALSE or (field:a OR field:b) ie TRUE
  protected $valid_relation = array(); 
  protected $searchable_source = array(); 
  protected $collection_contained_in = array(); 
  protected $rank_frequence_debug;
  protected $collection_alias = array();
  protected $agency_priority_list = array();  // prioritised list af agencies for the actual agency
  protected $unit_fallback = array();     // if fedora is updated and solr is not, this is used to find some record from the old unit
  protected $feature_sw = array();


  public function __construct() {
    webServiceServer::__construct('opensearch.ini');

    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, self::value_or_default($this->config->get_value('curl_timeout', 'setup'), 5));
    define('AGENCY_TIMEOUT', self::value_or_default($this->config->get_value('agency_timeout', 'setup'), 10));

    define(DEBUG_ON, $this->debug);
    $this->tracking_id = verbose::$tracking_id;
    define('MAX_IDENTICAL_RELATIONS', self::value_or_default($this->config->get_value('max_identical_relation_names', 'setup'), 20));
    define('MAX_OBJECTS_IN_WORK', 100);
    define('AND_OP', ' AND ');
    define('OR_OP', ' OR ');
    define('RR_MARC_001_A', 'marc.001a');
    define('RR_MARC_001_B', 'marc.001b');
  }

  /** \brief Entry search: Handles the request and set up the response
   *
   */

  public function search($param) {
    // set some defines
    if (!$this->aaa->has_right('opensearch', 500)) {
      $ret_error->searchResponse->_value->error->_value = 'authentication_error';
      return $ret_error;
    }

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

    $this->agency = $param->agency->_value;
    $this->filter_agency = self::set_solr_filter($this->search_profile);
    $this->holdings_include = self::set_holdings_include($this->search_profile, 'parentDocId');
    self::set_valid_relations_and_sources($this->search_profile);

    $this->feature_sw = $this->config->get_value('feature_switch', 'setup');

    if ($ufc = $this->config->get_value('collapsing_field', 'setup')) {
      $this->collapsing_field = $ufc;
    }
    $use_work_collection = ($param->collectionType->_value <> 'manifestation');
    if ($use_work_collection) {
      define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_collections', 'setup'), 50));
    }
    else {
      define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_manifestations', 'setup'), 200));
    }

    $sort = array();
    $rank_types = $this->config->get_value('rank', 'setup');
    if (!self::parse_for_ranking($param, $rank, $rank_types)) {
      if ($unsupported = self::parse_for_sorting($param, $sort, $sort_types)) {
        return $ret_error;
      }
    }
    $boost_q = self::boostUrl($param->userDefinedBoost);

    $this->format = self::set_format($param->objectFormat, $this->config->get_value('open_format', 'setup'), $this->config->get_value('solr_format', 'setup'));

    if ($unsupported) return $ret_error;

    $ret_error->searchResponse->_value->error->_value = &$error;
    $start = $param->start->_value;
    $step_value = min($param->stepValue->_value, MAX_STEP_VALUE);
    if (empty($start) && $step_value) {
      $start = 1;
    }
    if ($param->queryLanguage->_value) {
      $this->query_language = $param->queryLanguage->_value;
    }
    $debug_query = $this->xs_boolean($param->queryDebug->_value);
    $this->agency_catalog_source = $this->agency . '-katalog';
    $this->agency_type = self::get_agency_type($this->agency);


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

    if ($this->repository['postgress'] || $this->repository['rawrepo']) {
      $this->watch->start('postgress');
      $this->cql2solr = new SolrQuery($this->repository, $this->config, $this->query_language);
      $this->watch->start('cql');
      $solr_query = $this->cql2solr->parse($param->query->_value);
      $this->watch->stop('cql');
      if ($solr_query['error']) {
        $error = self::cql2solr_error_to_string($solr_query['error']);
        return $ret_error;
      }
      verbose::log(TRACE, 'CQL to SOLR: ' . $param->query->_value . ' -> ' . preg_replace('/\s+/', ' ', print_r($solr_query, TRUE)));
      $q = implode(AND_OP, $solr_query['edismax']['q']);
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
      $solr_urls[0]['debug'] = str_replace('wt=phps', 'wt=xml', $solr_urls[0]['url']);
      if ($err = self::do_solr($solr_urls, $solr_arr)) {
        $error = $err;
        return $ret_error;
      }
      $s11_agency = self::value_or_default($this->config->get_value('s11_agency', 'setup'), array());
      if ($this->repository['rawrepo']) {
        $collections = self::get_records_from_rawrepo($this->repository['rawrepo'], $solr_arr['response'], in_array($this->agency, $s11_agency));
      }
      else {
        $collections = self::get_records_from_postgress($this->repository['postgress'], $solr_arr['response'], in_array($this->agency, $s11_agency));
      }
      $this->watch->stop('postgress');
      if (is_scalar($collections)) {
        $error = $collections;
        return $ret_error;
      }
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
    *  Approach \n
    *  a) Do the solr search and fetch enough unit-ids in result \n
    *  b) Fetch a unit-ids work-object unless the record has been found
    *     in an earlier handled work-objects \n
    *  c) Collect unit-ids in this work-object \n
    *  d) repeat b. and c. until the requested number of objects is found \n
    *  e) if allObject is not set, do a new search combining the users search
    *     with an or'ed list of the unit-ids in the active objects and
    *     remove the unit-ids not found in the result \n
    *  f) Read full records from fedora for objects in result or fetch display-fields
    *     from solr, depending on the selected format \n
    *
    *  if $use_work_collection is FALSE skip b) to e)
    */

    $use_work_collection |= $sort_types[$sort[0]] == 'random';
    $key_work_struct = md5($param->query->_value . $this->repository_name . $this->filter_agency .
                           $use_work_collection .  implode('', $sort) . $rank . $boost_q . $this->config->get_inifile_hash());

    $this->cql2solr = new SolrQuery($this->repository, $this->config, $this->query_language, $this->holdings_include);
    $this->watch->start('cql');
    $solr_query = $this->cql2solr->parse($param->query->_value);
    $this->watch->stop('cql');
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
      if ($new_rank = self::guess_rank($solr_query, $rank_types, $filter_q)) {
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

    $facet_q = self::set_solr_facet_parameters($param->facets->_value);

    $rows = $step_value ? (($start + $step_value + 100) * 2) : 0;

    verbose::log(TRACE, 'CQL to SOLR: ' . $param->query->_value . ' -> ' . preg_replace('/\s+/', ' ', print_r($solr_query, TRUE)));

    // do the query
    $this->watch->start('Solr');
    if ($sort[0] == 'random') {
      if ($err = self::get_solr_array($solr_query['edismax'], 0, 0, '', '', $facet_q, $filter_q, '', $debug_query, $solr_arr))
        $error = $err;
      else {
        $numFound = self::get_num_found($solr_arr);
      }
    }
    else {
      if ($err = self::get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, '', $filter_q, $boost_q, $debug_query, $solr_arr))
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
    //$facets = self::parse_for_facets($solr_arr); - moved to other solr-call

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
          verbose::log(TRACE, __CLASS__ . '::'. __FUNCTION__ . ' - work_struct cache miss');
        }
      }

      $w_no = 0;

      if (DEBUG_ON) print_r($search_ids);

      if (empty($step_value)) {
        $more = ($numFound >= $start);
      }
      else {
        for ($s_idx = 0; isset($search_ids[$s_idx]); $s_idx++) {
          $uid = &$search_ids[$s_idx];
          if (!isset($search_ids[$s_idx+1]) && count($search_ids) < $numFound) {
            $this->watch->start('Solr_add');
            verbose::log(WARNING, 'To few search_ids fetched from solr. Query: ' . $solr_query['edismax']['q']);
            $rows *= 2;
            if ($err = self::get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, '', $filter_q, $boost_q, $debug_query, $solr_arr)) {
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

              // test empty rels_sys $record_rels_hierarchy = self::empty_rels_hierarchy();
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
          if ($w_no >= $start)
            $work_ids[$w_no] = $uid_array;
        }
      }
      verbose::log(TRACE, 'SOLR stat: used ' . $s_idx . ' of ' . count($search_ids) . ' rows. start: ' . $start . ' step: ' . $step_value);
    }

    if (count($work_ids) < $step_value && count($search_ids) < $numFound) {
      verbose::log(WARNING, 'To few search_ids found in solr. Query: ' . $solr_query['edismax']['q']);
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
      // ignore search profile filter and fetch info from the primary object for display and sort complexkey
      $display_solr_arr = self::do_add_queries_and_fetch_solr_data_fields($add_queries, 'unit.isPrimaryObject=true', self::xs_boolean($param->allObjects->_value), '');
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
    if (DEBUG_ON) echo 'collection_identifier: ' . print_r($collection_identifier, TRUE) . "\n";

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
    $this->agency_priority_list = self::fetch_agency_show_priority();
    if ($debug_query) {
      $explain_keys = array_keys($solr_arr['debug']['explain']);
    }
    foreach ($work_ids as &$work) {
      $objects = array();
      if (count($work) >= MAX_OBJECTS_IN_WORK) {
        verbose::log(WARNING, 'Fedora work-record containing: ' . reset($work) . ' contains ' . count($work) . ' units. Cut work to first ' . MAX_OBJECTS_IN_WORK . ' units');
        array_splice($work, MAX_OBJECTS_IN_WORK);
      }
      foreach ($work as $unit_id) {
        self::get_fedora_rels_addi($unit_id, $fedora_addi_relation);
        self::get_fedora_rels_hierarchy($unit_id, $unit_rels_hierarchy);
// test empty rels_sys $unit_rels_hierarchy = self::empty_rels_hierarchy();
//var_dump($unit_rels_hierarchy); die();
        list($unit_members, $fpid, $localdata_in_pid, $primary_oid) = self::parse_unit_for_best_agency($unit_rels_hierarchy, $unit_id, FALSE);
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
        list($pid, $datastream)  = self::create_fedora_pid_and_stream($fpid, $localdata_in_pid);
        if ($error = self::get_fedora_raw($pid, $fedora_result, $datastream)) {
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
            $explain = 'No explain available when using collapsing field';
          }
          else {
            foreach ($solr_arr['response']['docs'] as $solr_idx => $solr_rec) {
              if ($unit_id == $solr_rec['unit.id']) {
                $explain = $solr_arr['debug']['explain'][$explain_keys[$solr_idx]];
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
                                    $unit_members,
                                    $param->relationData->_value,
                                    $fpid,
                                    $primary_oid,
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
        $this->watch->stop('Solr_disp');
        self::format_solr($collections, $display_solr_arr, $work_ids, $fpid_sort_keys);
      }
      self::remove_unselected_formats($collections);
    }

// try to get a better hitCount by looking for primaryObjects only 
    if (is_null($param->collapseHitsThreshold->_value) || ($param->collapseHitsThreshold->_value == 'default')) {
      $nfcl = intval($this->config->get_value('num_found_collaps_limit', 'setup'));
    }
    else {
      $nfcl = intval($param->collapseHitsThreshold->_value);
    }
    if ($nfcl >= $numFound) {
      if ($nfcf = $this->config->get_value('num_found_collapsing_field', 'setup')) {
        $this->collapsing_field = $nfcf;
      }
    }
    $this->watch->start('Solr_hits_facets');
    if ($err = self::get_solr_array($solr_query['edismax'], 0, 0, '', $rank_q, $facet_q, $filter_q, '', $debug_query, $solr_arr)) {
      $this->watch->stop('Solr_hits_facets');
      $error = $err;
      return $ret_error;
    }
    else {
      $this->watch->stop('Solr_hits_facets');
      if (($n = self::get_num_found($solr_arr)) != $numFound) {
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
    self::set_sortUsed($result, $rank, $sort, $sort_types);
    $result->searchResult = $collections;
    $result->facetResult->_value = $facets;
    if ($debug_query && $debug_result) {
      $result->queryDebugResult->_value = $debug_result;
    }
    $result->statInfo->_value->fedoraRecordsCached->_value = $this->number_of_fedora_cached;
    $result->statInfo->_value->fedoraRecordsRead->_value = $this->number_of_fedora_calls;
    $result->statInfo->_value->time->_value = $this->watch->splittime('Total');
    $result->statInfo->_value->trackingId->_value = $this->tracking_id;

    verbose::log(STAT, sprintf($this->dump_timer, $this->soap_action) .  
                       ':: agency:' . $this->agency . 
                       ' profile:' . $param->profile->_value . 
                       ' ip:' . $_SERVER['REMOTE_ADDR'] .
                       ' ' . str_replace(PHP_EOL, '', $this->watch->dump()) . 
                       ' query:' . $param->query->_value . PHP_EOL);

    return $ret;
  }


  /** \brief Entry getObject: Get an object in a specific format
  *
  * param: agency: \n
  *        profile:\n
  *        identifier - fedora pid\n
  *        objectFormat - one of dkabm, docbook, marcxchange, opensearchobject\n
  *        includeHoldingsCount - boolean\n
  *        relationData - type, uri og full\n
  *        repository\n
  * 
  * @param object $param - the user request
  * @retval object - the answer to the request
  */
  public function getObject($param) {
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
    if ($this->agency = $param->agency->_value) {
      if ($param->profile->_value) {
        if (!($this->search_profile = self::fetch_profile_from_agency($this->agency, $param->profile->_value))) {
          $error = 'Error: Cannot fetch profile: ' . $param->profile->_value . ' for ' . $this->agency;
          return $ret_error;
        }
      }
      else
        $agencies = $this->config->get_value('agency', 'agency');
      $agencies[$this->agency] = self::set_solr_filter($this->search_profile);
      self::set_valid_relations_and_sources($this->search_profile);
      if (isset($agencies[$this->agency]))
        $this->filter_agency = $agencies[$this->agency];
      else {
        $error = 'Error: Unknown agency: ' . $this->agency;
        return $ret_error;
      }
    }
    if ($this->filter_agency) {
      $filter_q = rawurlencode($this->filter_agency);
    }

    $this->feature_sw = $this->config->get_value('feature_switch', 'setup');

    $this->agency_catalog_source = $this->agency . '-katalog';
    $this->agency_type = self::get_agency_type($this->agency);
    $this->agency_priority_list = self::fetch_agency_show_priority();
    $this->format = self::set_format($param->objectFormat, 
                               $this->config->get_value('open_format', 'setup'), 
                               $this->config->get_value('solr_format', 'setup'));
    $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                             $this->config->get_value('cache_port', 'setup'),
                             $this->config->get_value('cache_expire', 'setup'));

    $fpids = is_array($param->identifier) ? $param->identifier : ($param->identifier ? array($param->identifier) : array());
    $lpids = is_array($param->localIdentifier) ? $param->localIdentifier : ($param->localIdentifier ? array($param->localIdentifier) : array());

    define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_manifestations', 'setup'), 200));
    if (MAX_STEP_VALUE <= count($fpids) + count($lpids)) {
      $error = 'getObject can fetch up to ' . MAX_STEP_VALUE . ' records. ';
      return $ret_error;
    }

    if ($this->format['found_solr_format']) {
      foreach ($this->format as $f) {
        if ($f['is_solr_format']) {
          $add_fl .= ',' . $f['format_name'];
        }
      }
    }
    if ($this->repository['postgress'] || $this->repository['rawrepo']) {
      foreach ($fpids as $fpid) {
        list($owner_collection, $id) = explode(':', $fpid->_value);
        list($owner, $coll) = explode('-', $owner_collection);
        $docs['docs'][] = array(RR_MARC_001_A => $id, RR_MARC_001_B => $owner);
      }
      foreach ($lpids as $lid) {
        $docs['docs'][] = array(RR_MARC_001_A => $lid->_value, RR_MARC_001_B => $this->agency);
      }
      $s11_agency = self::value_or_default($this->config->get_value('s11_agency', 'setup'), array());
      if ($this->repository['rawrepo']) {
        $collections = self::get_records_from_rawrepo($this->repository['rawrepo'], $docs, in_array($this->agency, $s11_agency));
      }
      else {
        $collections = self::get_records_from_postgress($this->repository['postgress'], $docs, in_array($this->agency, $s11_agency));
      }
      if (is_scalar($collections)) {
        $error = $collections;
        return $ret_error;
      }
      $result = &$ret->searchResponse->_value->result->_value;
      $result->hitCount->_value = count($collections);
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
    foreach ($lpids as $lid) {
      $fpid->_value = $this->agency_catalog_source . ':' . $lid->_value;
      $fpids[] = $fpid;
      unset($fpid);
    }
    foreach ($fpids as $fpid) {
      $id_array[] = $fpid->_value;
      list($owner_collection, $id) = explode(':', $fpid->_value);
      list($owner, $coll) = explode('-', $owner_collection);
      if (self::agency_rule($owner, 'use_localdata_stream')) {
        $id_array[] = '870970-basis:' . $id;
        $localdata_object[$fpid->_value] = '870970-basis:' . $id;
      }
    }
    $this->cql2solr = new SolrQuery($this->repository, $this->config);
    $this->watch->start('cql');
    $chk_query = $this->cql2solr->parse('rec.id=(' . implode(OR_OP, $id_array) . ')');
    $this->watch->stop('cql');
    $solr_q = $this->repository['solr'] .
             '?wt=phps' .
              '&q=' . urlencode(implode(AND_OP, $chk_query['edismax']['q'])) .
              '&fq=' . $filter_q .
              '&start=0' .
              '&rows=500' .
              '&defType=edismax' .
              '&fl=rec.collectionIdentifier,fedoraPid,rec.id,unit.id,unit.isPrimaryObject' . $add_fl;
    verbose::log(TRACE, __FUNCTION__ . ':: Search for pids in Solr: ' . $solr_q);
    $solr_result = $this->curl->get($solr_q);
    $solr_2_arr[] = unserialize($solr_result);

    foreach ($fpids as $fpid_number => $fpid) {
      $no_direct_hit = $unit_id = $fedora_pid = $basis_pid = $basis_unit_id = '';
      $in_collection = FALSE;
      list($fpid_collection) = explode(':', $fpid->_value);
      foreach ($solr_2_arr as $s_2_a) {
        if ($s_2_a['response']['docs']) {
          foreach ($s_2_a['response']['docs'] as $fdoc) {
            $f_id =  self::scalar_or_first_elem($fdoc['fedoraPid']);
            if ($f_id == $fpid->_value) {
              $unit_id =  self::scalar_or_first_elem($fdoc['unit.id']);
              $fedora_pid = $f_id;
              $in_collection = $in_collection || in_array($fpid_collection, $fdoc['rec.collectionIdentifier']);
            }
            if ($f_id == $localdata_object[$fpid->_value]) {
              $basis_unit_id =  self::scalar_or_first_elem($fdoc['unit.id']);
              $basis_pid = $f_id;
              $in_collection = $in_collection || in_array($fpid_collection, $fdoc['rec.collectionIdentifier']);
            }
          }
        }
      }
      if (empty($fedora_pid) && $basis_pid) {
        $no_direct_hit = TRUE;
        $unit_id =  $basis_unit_id;
        $fedora_pid = $basis_pid;
      }

      if (!$unit_id) {  // should never happen, since the unit.id has to be present in SOLR
// TODO: This should break the flow and return an error. The records has to be searchable with the chosen search profile
//       Consider if this will break some systems since getObject once could get all objects in the repository.
        verbose::log(WARNING, 'getObject:: Cannot find unit for ' . $fpid->_value . ' in SOLR');
        self::get_fedora_rels_hierarchy($fpid->_value, $fedora_rels_hierarchy);
        $unit_id = self::parse_rels_for_unit_id($fedora_rels_hierarchy);
      }
      if (!$unit_id) {
        $rec_error = 'Error: unknown/missing/inaccessible record: ' . $fpid->_value;
      }
      else {
        self::get_fedora_rels_hierarchy($unit_id, $unit_rels_hierarchy);
        list($unit_members) = self::parse_unit_for_best_agency($unit_rels_hierarchy, $unit_id, FALSE);
        list($fpid_collection, $fpid_local) = explode(':', $fedora_pid);
        if (!$in_collection && $fedora_pid) {
          $fpid->_value = $fedora_pid;
        }
        list($fedora_pid, $datastream)  = self::create_fedora_pid_and_stream($fpid->_value, $fedora_pid);
        if (self::deleted_object($fedora_pid, $datastream)) {
          $rec_error = 'Error: deleted record: ' . $fpid->_value;
        }
        elseif ($error = self::get_fedora_raw($fedora_pid, $fedora_result, $datastream)) {
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
            $this->cql2solr = new SolrQuery($this->repository, $this->config);
            $no_of_holdings = self::get_holdings($fpid->_value);
          }
        }
      }
//var_dump($fedora_rels_hierarchy);
//var_dump($unit_id);
//var_dump($fedora_addi_relation);
//die();
      $o->collection->_value->resultPosition->_value = $fpid_number + 1;
      $o->collection->_value->numberOfObjects->_value = 1;
      if ($rec_error) {
        $help->_value->error->_value = $rec_error;
        $help->_value->identifier->_value = $fpid->_value;
        $o->collection->_value->object[] = $help;
        unset($help);
        unset($rec_error);
      } 
      else {
        $o->collection->_value->object[]->_value =
          self::parse_fedora_object($fedora_result,
                                    $fedora_addi_relation,
                                    $unit_members,
                                    $param->relationData->_value,
                                    $fpid->_value,
                                    $best_pid,
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

    $result = &$ret->searchResponse->_value->result->_value;
    $result->hitCount->_value = count($collections);
    $result->collectionCount->_value = count($collections);
    $result->more->_value = 'false';
    $result->searchResult = $collections;
    $result->facetResult->_value = '';
    $result->statInfo->_value->fedoraRecordsCached->_value = $this->number_of_fedora_cached;
    $result->statInfo->_value->fedoraRecordsRead->_value = $this->number_of_fedora_calls;
    $result->statInfo->_value->time->_value = $this->watch->splittime('Total');
    $result->statInfo->_value->trackingId->_value = $this->tracking_id;

    verbose::log(STAT, sprintf($this->dump_timer, $this->soap_action) .  
                       ':: agency:' . $param->agency->_value . 
                       ' profile:' . $param->profile->_value . ' ' . $this->watch->dump());
    return $ret;
  }

  /** \brief Entry info: collect info
  *
  * @param object $param - the user request
  * @retval object - the answer to the request
  */
  public function info($param) {
    $result = &$ret->infoResponse->_value;
    $result->infoGeneral->_value->defaultRepository->_value = $this->config->get_value('default_repository', 'setup');
    $result->infoRepositories = self::get_repository_info();
    $result->infoObjectFormats = self::get_object_format_info();
    $result->infoSearchProfile = self::get_search_profile_info($param->agency->_value, $param->profile->_value);
    $result->infoSorts = self::get_sort_info();
    $result->infoNameSpaces = self::get_namespace_info();
    verbose::log(STAT, sprintf($this->dump_timer, $this->soap_action) .  
                       ':: agency:' . $param->agency->_value . 
                       ' profile:' . $param->profile->_value . ' ' . $this->watch->dump());
    return $ret;
  }
  /*******************************************************************************/

  /** \brief for testing to return empty rels hierarchy records
   * 
   * @retval object - xml string
   */
  private function empty_rels_hierarchy() {
    return '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="info:fedora/unit:16765541"></rdf:Description></rdf:RDF>';
  }

  /** \brief Get information about search profile (info operation)
   * 
   * @param string $agency 
   * @param string $profile 
   * @retval object - the user profile
   */
  private function get_search_profile_info($agency, $profile) {
    if ($s_profile = self::fetch_profile_from_agency($agency, $profile)) {
      foreach ($s_profile as $p) {
        $coll->searchCollectionName->_value = $p['sourceName'];
        $coll->searchCollectionIdentifier->_value = $p['sourceIdentifier'];
        if ($p['relation'])
          foreach ($p['relation'] as $relation) {
            if ($r = $relation['rdfLabel']) {
              $all_relations[$r] = $r;
              $rels[]->_value = $r;
            }
            if ($r = $relation['rdfInverse']) {
              $all_relations[$r] = $r;
              $rels[]->_value = $r;
            }
          }
        if ($rels) {
          $coll->relationType = $rels;
          unset($rels);
        }
        $ret->_value->searchCollection[]->_value = $coll;
        if (is_array($all_relations)) {
          ksort($all_relations);
          foreach ($all_relations as $rel) {
            $rels->relationType[]->_value = $rel;
          }
          $ret->_value->relationTypes->_value = $rels;
          unset($rels);
        }
        unset($coll);
      }
    }
    return $ret;
  }

  /** \brief Get information about object formats from config (info operation)
   * 
   * @retval object 
   */
  private function get_object_format_info() {
    foreach ($this->config->get_value('scan_format_table', 'setup') as $name => $value) {
      $ret->_value->objectFormat[]->_value = $value;
    }
    foreach ($this->config->get_value('open_format', 'setup') as $name => $value) {
      $ret->_value->objectFormat[]->_value = $name;
    }
    return $ret;
  }

  /** \brief Get information about repositories from config (info operation)
   * 
   * @retval object 
   */
  private function get_repository_info() {
    $dom = new DomDocument();
    $repositories = $this->config->get_value('repository', 'setup');
    foreach ($repositories as $name => $value) {
      if ($name != 'defaults') {
        $r->repository->_value = $name;
        self::set_repositories($name, FALSE);
        $r->cqlIndexDoc->_value = $this->repository['cql_file'];
        if ($this->repository['cql_settings'] && @ $dom->loadXML($this->repository['cql_settings'])) {
          foreach ($dom->getElementsByTagName('indexInfo') as $index_info) {
            foreach ($index_info->getElementsByTagName('index') as $index) {
              foreach ($index->getElementsByTagName('map') as $map) {
                if ($map->getAttribute('hidden') !== '1') {
                  foreach ($map->getElementsByTagName('name') as $name) {
                    $idx = self::set_name_and_slop($name);
                  }
                  foreach ($map->getElementsByTagName('alias') as $alias) {
                    $idx->indexAlias[]->_value = self::set_name_and_slop($alias);
                  }
                  $r->cqlIndex[]->_value = $idx;
                  unset($idx);
                }
              }
            }
          }
        }
        $ret->_value->infoRepository[]->_value = $r;
        unset($r);
      }
    }
    return $ret;
  }

  /** \brief Get info from dom node (info operation)
   * 
   * @param domNode $node
   * @retval object 
   */
  private function set_name_and_slop($node) {
    $prefix = $node->getAttribute('set');
    $reg->indexName->_value = $prefix . ($prefix ? '.' : '') . $node->nodeValue;
    if ($slop = $node->getAttribute('slop')) {
      $reg->indexSlop->_value = $slop;
    }
    return $reg;
  }

  /** \brief Get information about namespaces from config (info operation)
   * 
   * @retval object 
   */
  private function get_namespace_info() {
    foreach ($this->config->get_value('xmlns', 'setup') as $prefix => $namespace) {
      $ns->prefix->_value = $prefix;
      $ns->uri->_value = $namespace;
      $nss->_value->infoNameSpace[]->_value = $ns;
      unset($ns);
    }
    return $nss;
  }

  /** \brief Get information about sorting and ranking from config (info operation)
   * 
   * @retval object 
   */
  private function get_sort_info() {
    foreach ($this->config->get_value('rank', 'setup') as $name => $val) {
      if ($help = self::collect_rank_boost($val['word_boost'])) {
        $boost->word = $help;
      }
      if ($help = self::collect_rank_boost($val['phrase_boost'])) {
        $boost->phrase = $help;
      }
      if ($boost) {
        $rank->sort->_value = $name;
        $rank->internalType->_value = 'rank';
        $rank->rankDetails->_value->tie->_value = $val['tie'];
        $rank->rankDetails->_value = $boost;
        $ret->_value->infoSort[]->_value = $rank;
        unset($boost);
        unset($rank);
      }
    }
    foreach ($this->config->get_value('sort', 'setup') as $name => $val) {
        $sort->sort->_value = $name;
        if (is_array($val)) {
          $sort->internalType->_value = 'complexSort';
          foreach ($val as $simpleSort) {
            $simple[]->_value = $simpleSort;
          }
          $sortDetails->sort = $simple;
          unset($simple);
        }
        else {
          $sort->internalType->_value = ($val == 'random' ? 'random' : 'basicSort');
          $sortDetails->sort->_value = $val;
        }
        $sort->sortDetails->_value = $sortDetails;
        $ret->_value->infoSort[]->_value = $sort;
        unset($sort);
        unset($sortDetails);
    }
    return $ret;
  }

  /** \brief return one rank entry (info operation)
   * 
   * @param array $rank 
   * @retval object 
   */
  private function collect_rank_boost($rank) {
    if (is_array($rank)) {
      foreach ($rank as $reg => $weight) {
        $rw->fieldName->_value = $reg;
        $rw->weight->_value = $weight;
        $iaw->_value->fieldNameAndWeight[]->_value = $rw; 
        unset($rw);
      }
    }
    return $iaw;
  }

  /** \brief sets sortUsed if rank or sort is used
   * 
   * @param object $ret - modified
   * @param string $rank
   * @param string $sort
   * @param array $sort_types
   */
  private function set_sortUsed(&$ret, $rank, $sort, $sort_types) {
    if (isset($rank)) {
      if (substr($rank, 0, 9) != 'user_rank') {
        $ret->sortUsed->_value = $rank;
      }
    }
    elseif (!empty($sort)) {
      if ($key = array_search($sort, $sort_types)) {
        $ret->sortUsed->_value = $key;
      }
      else {
        foreach ($sort as $s) {
          $ret->sortUsed[]->_value = $s;
        }
      }
    }
  }

  /** \brief Set the parameters to solr facets
   *
   * @param object $facets - the facet paramaters from the request
   * @retval string - facet part of solr url
   * TODO: change xsd to include facetOffset
   */
  private function set_solr_facet_parameters($facets) {
    $max_threads = self::value_or_default($this->config->get_value('max_facet_threads', 'setup'), 50);
    $ret = '';
    if ($facets->facetName) {
      $facet_min = 1;
      if (isset($facets->facetMinCount->_value)) {
        $facet_min = $facets->facetMinCount->_value;
      }
      $ret .= '&facet=true&facet.threads=' . min(count($facets->facetName), $max_threads) . '&facet.limit=' . $facets->numberOfTerms->_value .  '&facet.mincount=' . $facet_min;
      if ($facet_sort = $facets->facetSort->_value) {
        $ret .= '&facet.sort=' . $facet_sort;
      }
      if ($facet_offset = $facets->facetOffset->_value) {
        $ret .= '&facet.offset=' . $facet_offset;
      }
      if (is_array($facets->facetName)) {
        foreach ($facets->facetName as $facet_name) {
          $ret .= '&facet.field=' . $facet_name->_value;
        }
      }
      elseif (is_scalar($facets->facetName->_value)) {
        $ret .= '&facet.field=' . $facets->facetName->_value;
      }
    }
    $this->watch->sums['facets'] = count($facets->facetName) . sprintf('.%03d 0', $facets->numberOfTerms->_value);
    return $ret;
  }

  /** \brief fetch a cql-file from solr and display it
   *
   * @retval string - xml doc or error
   */
  protected function showCqlFile() {
    $repositories = $this->config->get_value('repository', 'setup');
    $repos = self::value_or_default($_GET['repository'], $this->config->get_value('default_repository', 'setup'));
    $this->repository = $repositories[$repos];
    if (!$cql = $_GET['cql']) {
      $cql = $repository['cql_file'];
    }
    if ($cql && ($file = self::get_solr_file($cql))) {
      header('Content-Type: application/xml; charset=utf-8');
      echo $file;
    }
    else {
      header('HTTP/1.0 404 Not Found');
      echo 'Cannot locate the cql-file: ' . $cql . '<br /><br />Use info operation to check name and repostirory';
    }
  }

  /** \brief Compares registers in cql_file with solr, using the luke request handler:
   *   http://wiki.apache.org/solr/LukeRequestHandler
   *
   * @retval string - html doc
   */
  protected function diffCqlFileWithSolr() {
    if ($error = self::set_repositories($_REQUEST['repository'])) {
      die('Error setting repository: ' . $error);
    }
    $luke_url = $this->repository['solr'];
    if (empty($luke_url)) {
      die('Cannot find url to solr for repository');
    }
    $luke = $this->config->get_value('solr_luke', 'setup');
    foreach ($luke as $from => $to) {
      $luke_url = str_replace($from, $to, $luke_url);
    }
    $luke_result = json_decode($this->curl->get($luke_url));
    if (!$luke_result) {
      die('Cannot fetch register info from solr: ' . $luke_url);
    }
    $luke_fields = &$luke_result->fields;
    $dom = new DomDocument();
    $dom->loadXML($this->repository['cql_settings']) || die('Cannot read cql_file: ' . $this->repository['cql_file']);

    foreach ($dom->getElementsByTagName('indexInfo') as $info_item) {
      foreach ($info_item->getElementsByTagName('index') as $index_item) {
        if ($map_item = $index_item->getElementsByTagName('map')->item(0)) {
          if ($name_item = $map_item->getElementsByTagName('name')->item(0)) {
            if (!$name_item->hasAttribute('searchHandler') && ($name_item->getAttribute('set') !== 'cql')) {
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
    }

    echo '<html><body><h1>Found in ' . $this->repository['cql_file'] . ' but not in Solr for repository ' . $this->repository_name . '</h1>';
    foreach ($cql_regs as $cr)
      echo $cr . '</br>';
    echo '</br><h1>Found in Solr but not in ' . $this->repository['cql_file'] . ' for repository ' . $this->repository_name . '</h1>';
    foreach ($luke_fields as $lf => $obj)
      echo $lf . '</br>';
    
    die('</body></html>');
  }

  /** \brief Reads records from Raw Record Postgress Database
   *   
   * @param string $rr_service    Address fo raw_repo service end point
   * @param array $solr_response    Response from a solr search in php object
   * @param boolean $s11_records_allowed    restricted record
   * 
   * @retval mixed  array of collections or error string
   */
  private function get_records_from_rawrepo($rr_service, $solr_response, $s11_records_allowed) {
    if (empty($solr_response['docs'])) {
      return;
    }
    $p_mask = '<?xml version="1.0" encoding="UTF-8"?' . '>' . PHP_EOL . '<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body><fetchRequest xmlns="http://oss.dbc.dk/ns/rawreposervice"><records>' . PHP_EOL . '%s</records></fetchRequest></S:Body></S:Envelope>';
    $r_mask = '<record><bibliographicRecordId>%s</bibliographicRecordId><agencyId>%s</agencyId><mode>MERGED</mode><allowDeleted>true</allowDeleted><includeAgencyPrivate>true</includeAgencyPrivate></record>';
    $ret = array();
    $rec_pos = $solr_response['start'];
    foreach ($solr_response['docs'] as $solr_doc) {
      $post .= sprintf($r_mask, self::scalar_or_first_elem($solr_doc[RR_MARC_001_A]), self::scalar_or_first_elem($solr_doc[RR_MARC_001_B])) . PHP_EOL;
    }
    $this->curl->set_post(sprintf($p_mask, $post), 0); // use post here because query can be very long
    $this->curl->set_option(CURLOPT_HTTPHEADER, array('Accept:application/xml;', 'Content-Type: text/xml; charset=utf-8'), 0);
    $result = $this->curl->get($rr_service, 0);
    $this->curl->set_option(CURLOPT_POST, 0, 0);
    $dom = new DomDocument();
    @ $dom->loadXml($result);
    if ($records = &$dom->getElementsByTagName('records')->item(0)) {
      foreach ($solr_response['docs'] as $solr_doc) {
        $found_record = FALSE;
        $solr_id = self::scalar_or_first_elem($solr_doc[RR_MARC_001_A]);
        $solr_agency = self::scalar_or_first_elem($solr_doc[RR_MARC_001_B]);
        foreach ($records->getElementsByTagName('record') as $record) {
          $id = $record->getElementsByTagName('bibliographicRecordId')->item(0)->nodeValue;
          $agency = $record->getElementsByTagName('agencyId')->item(0)->nodeValue;
          if (($solr_id == $id) && ($solr_agency == $agency)) {
            $found_record = TRUE;
            if (!$data = base64_decode($record->getElementsByTagName('data')->item(0)->nodeValue)) {
              verbose::log(FATAL, 'Internal problem: Cannot decode record ' . $solr_id . ':' . $solr_agency . ' in rawrepo');
            }
            break;
          }
        }
        if (!$found_record) {
          verbose::log(ERROR, 'Internal problem: Cannot find record ' . $solr_id . ':' . $solr_agency . ' in rawrepo');
        }
        if (empty($data)) {
          $data = sprintf($this->config->get_value('missing_marc_record', 'setup'), $solr_id, $solr_agency, 'Cannot read record');
        }
        @ $dom->loadXml($data);
        $marc_obj = $this->xmlconvert->xml2obj($dom, $this->xmlns['marcx']);
        $restricted_record = FALSE;
        if (!$s11_records_allowed) {
          foreach ($marc_obj->record->_value->datafield as $idf => &$df) {
            if ($df->_attributes->tag->_value == 's11') {
              $restricted_record = TRUE;
              break 1;
            }
          }
        }
        if ($restricted_record) {
          verbose::log(WARNING, 'Skipping restricted record ' . $solr_id . ':' . $solr_agency . ' in rawrepo');
          @ $dom->loadXml(sprintf($this->config->get_value('missing_marc_record', 'setup'), $solr_id, $solr_agency, 'Restricted record'));
          $marc_obj = $this->xmlconvert->xml2obj($dom, $this->xmlns['marcx']);
        }
        self::filter_marcxchange($solr_agency, $marc_obj, $this->repository['filter']);
        $rec_pos++;
        $ret[$rec_pos]->_value->collection->_value->resultPosition->_value = $rec_pos;
        $ret[$rec_pos]->_value->collection->_value->numberOfObjects->_value = 1;
        $ret[$rec_pos]->_value->collection->_value->object[0]->_value->collection->_value = $marc_obj;
        $ret[$rec_pos]->_value->collection->_value->object[0]->_value->collection->_namespace = $this->xmlns['marcx'];
      }
    }
    return $ret;
  }

  /** \brief Reads records from Raw Record Postgress Database
   *   
   * OBSOLETE from datawell 3.5 - use get_records_from_rawrepo instead
   * @param string $rr_service    Address fo raw_repo service end point
   * @param array $solr_response    Response from a solr search in php object
   * @param boolean $s11_records_allowed    restricted record
   * 
   * @retval mixed  array of collections or error string
   */
  private function get_records_from_postgress($pg_db, $solr_response, $s11_records_allowed) {
    die('obsolete function - correct the inifile to use raw repo service instead');
  }

  /** \brief Change cql_error to string
   *
   * @param array $solr_error
   * @retval string
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
   * @param array $work_ids
   * @retval array - of SOLR search string
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
          $add_queries[$block_idx] .= (empty($add_queries[$block_idx]) ? '' : OR_OP) . $this->which_rec_id . ':' . $id;
        }
        else {
          $add_queries[$block_idx] .= (empty($add_queries[$block_idx]) ? '' : OR_OP) . $id;
        }
        $no_bool++;
        $no_of_rows++;
      }
    }
    return $add_queries;
  }

  /** \brief Create solr array with records valid for the search-profile and parameters. 
   *         If solr_formats is asked for, build list of fields to ask for
   *
   * @param array $add_queries
   * @param string $query 
   * @param boolean $all_objects 
   * @param string $filter_q 
   * @retval mixed - error string or SOLR array
   */
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
   * @param array $add_queries
   * @param string $query 
   * @param boolean $all_objects 
   * @param string $filter_q 
   * @param string $add_field_list - list of extra fields to return, like display_*
   * @retval mixed - error string or SOLR array
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
   * @param string $repository 
   * @param boolean $cql_file_mandatory 
   * @retval mixed - error or NULL
   */
  private function set_repositories($repository, $cql_file_mandatory = TRUE) {
    $repositories = $this->config->get_value('repository', 'setup');
    if (!$this->repository_name = $repository) {
      $this->repository_name = $this->config->get_value('default_repository', 'setup');
    }
    if ($this->repository = $repositories[$this->repository_name]) {
      foreach ($repositories['defaults'] as $key => $url_par) {
        if (empty($this->repository[$key])) {
          $this->repository[$key] = (substr($key, 0, 7) == 'fedora_') ? $this->repository['fedora'] . $url_par : $url_par;
        }
      }
      if ($cql_file_mandatory && empty($this->repository['cql_file'])) {
        verbose::log(FATAL, 'cql_file not defined for repository: ' .  $this->repository_name);
        return 'Error: cql_file not defined for repository: '  . $this->repository_name;
      }
      if ($this->repository['cql_file']) {
        if (!$this->repository['cql_settings'] = self::get_solr_file($this->repository['cql_file'])) {
          if (!$this->repository['cql_settings'] = @ file_get_contents($this->repository['cql_file'])) {
            verbose::log(FATAL, 'Cannot get cql_file (' . $this->repository['cql_file'] . ') from local directory. Repository: ' .  $this->repository_name);
            return 'Error: Cannot find cql_file for repository: '  . $this->repository_name;
          }
          verbose::log(ERROR, 'Cannot get cql_file (' . $this->repository['cql_file'] . ') from SOLR - use local version. Repository: ' .  $this->repository_name);
        }
      }
      if (empty($this->repository['filter'])) {
        $this->repository['filter'] = array();
      }
    }
    else {
      return 'Error: Unknown repository: ' . $this->repository_name;
    }
  }

  /** \brief fetch a file from the solr file directory
   *
   * @param string $name 
   * @retval string (xml)
   */
  private function get_solr_file($name) {
    static $solr_file_cache;
    $file_url = $this->repository['solr'];
    $file = $this->config->get_value('solr_file', 'setup');
    foreach ($file as $from => $to) {
      $file_url = str_replace($from, $to, $file_url);
    }
    $solr_file_url = sprintf($file_url, $name);
    if (empty($solr_file_cache)) {
      $cache = self::get_cache_info('solr_file');
      $solr_file_cache = new cache($cache['host'], $cache['port'], $cache['expire']);
    }
    if (!$xml = $solr_file_cache->get($solr_file_url)) {
      $xml = $this->curl->get($solr_file_url);
      if ($this->curl->get_status('http_code') != 200) {
        return FALSE;
      }
      $solr_file_cache->set($solr_file_url, $xml);
    }
    return $xml;
  }

  /** \brief Set fedora pid to corrects objct and modify datastream according to storage scheme
   *
   * @param string $pid 
   * @param string $localdata_in_pid 
   * @retval array - of object_id, datastream
   */
  private function create_fedora_pid_and_stream($pid, $localdata_in_pid) {
    if (empty($localdata_in_pid) || ($pid == $localdata_in_pid)) {
      return array($pid, 'commonData');
    }
    return array($localdata_in_pid, 'localData.' . self::record_source_from_pid($pid));
  }

  /** \brief return data stream name depending on collection identifier
   *  - if $collection_id (rec.collectionIdentifier) startes with 7 - dataStream: localData.$collection_id
   *  - else dataStream: commonData
   *
   * @param string $collection_id 
   * @retval string
   */
  private function set_data_stream_name($collection_id) {
    list($agency, $collection) = self::split_record_source($record_source);
    if ($collection_id && self::agency_rule($agency, 'use_localdata_stream')) {
      $data_stream = 'localData.' . $collection_id;
    }
    else {
      $data_stream = 'commonData';
    }
    if (DEBUG_ON) {
      echo 'dataStream: ' . $collection_id . ' ' . $data_stream . PHP_EOL;
    }
    return $data_stream;
  }

  /** \brief parse input for rank parameters
   *
   * @param object $param -       The request
   * @param string $rank -        Name of rank used by request
   * @param array $rank_types -   Settings for the given rank
   * @retval boolean - TRUE if a ranking is found
   */
  private function parse_for_ranking($param, &$rank, &$rank_types) {
    if ($rr = $param->userDefinedRanking) {
      $rank = 'user_rank';
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
   * @param object $param -       The request
   * @param string $sort -        Name of sort used by request
   * @param array $sort_types -   Settings for the given sort
   * @retval mixed - error or NULL
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
        if (is_array($sort_types[$s->_value])) {
          foreach ($sort_types[$s->_value] as $item) {
            if (!isset($sort_types[$item])) {
              return 'Error in service setup: ' . $item . ' specified in ' . $s->_value . ' is not defined';
            }
            $sort[] = $item;
          }
        }
        else {
          $sort[] = $s->_value;
        }
      }
    }
  }

  /** \brief Selects a ranking scheme depending on some register frequency lookups
   *
   * @param array $solr_query - the parsed user query
   * @param array $ranks - list of defined rankings
   * @param string $user_filter - filter query as set by users profile
   *
   * @retval string - the ranking scheme with highest number of hits
   *
   */
  private function guess_rank($solr_query, $ranks, $user_filter) {
    $guess = self::set_guesses($ranks, $user_filter);
    $freqs = self::get_register_freqency($solr_query['edismax'], $guess);
    $max = -1;
    foreach ($guess as $idx => $g) {
      $freq = $freqs[$idx] * $g['weight'];
      $this->rank_frequence_debug->$g['register']->_value = $freq . ' (' . $freqs[$idx] . '*' . $g['weight'] . ')';
      $debug_str .= $g['scheme'] . ': ' . $freq . ' (' . $freqs[$idx] . '*' . $g['weight'] . ') ';
      if ($freq > $max) {
        $ret = $g['scheme'];
        $max = $freq;
      }
    }
    verbose::log(DEBUG, 'Rank frequency set to ' . $ret . '. ' . $debug_str);
    return $ret;

  }

  /** \brief Set the guess-structure for the registers to search
   *
   * @param array $ranks - list of defined rankings
   * @param string $user_filter - filter query as set by users profile
   *
   * @retval array - list of registers to search and the ranking to use
   *
   */
  private function set_guesses($ranks, $user_filter) {
    static $filters = array();
    $guess = array();
    $settings = $this->config->get_value('rank_frequency', 'setup');
    foreach ($settings as $r_idx => $setting) {
      if ($setting['register'] && $ranks[$setting['scheme']]) {
        foreach (array('agency', 'register', 'scheme', 'weight', 'filter', 'profile') as $par) {
          $guess[$r_idx][$par] = self::get_val_or_default($settings, $r_idx, $par);
        }
      }
    }
    $filters['user_profile'] = $user_filter;
    foreach ($guess as $idx => $g) {
      if (empty($filters[$g['profile']])) {
        if (! $filters[$g['profile']] = self::set_solr_filter(self::fetch_profile_from_agency($g['agency'], $g['profile']))) {
          $filters[$g['profile']] = $user_filter;
        }
      }
      $guess[$idx]['filter'] = array(rawurlencode($g['filter']), $filters[$g['profile']]);
    }
    return $guess;
  }

  /** \brief return specific value if set, otherwise the default
   *
   * @param array $struct - the structure to inspect
   * @param string $r_idx - the specific index
   * @param string $par - the parameter to return
   * @retval string 
   */
  private function get_val_or_default($struct, $r_idx, $par) {
    return $struct[$r_idx][$par] ? $struct[$r_idx][$par] : $struct[$par];
  }

  /** \brief Encapsules how to get the data from the first element
   *
   * @param array $solr_arr
   * @param string $element
   * @retval mixed
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
   * @param array $solr_arr
   * @retval integer
   */
  private function get_num_found($solr_arr) {
    if ($this->collapsing_field) {
      return self::get_num_grouped($solr_arr);
    }
    else {
      return self::get_num_response($solr_arr);
    }
  }

  /** \brief extract grouped from SOLR structure
   *
   * @param array $solr_arr
   * @retval integer
   */
  private function get_num_grouped($solr_arr) {
    return $solr_arr['grouped'][$this->collapsing_field]['ngroups'];
  } 

  /** \brief extract numFound from SOLR structure
   *
   * @param array $solr_arr
   * @retval integer
   */
  private function get_num_response($solr_arr) {
    return $solr_arr['response']['numFound'];
  } 

  /** \brief Encapsules extraction of unit.id's from the solr result
   *       - stores unit-id and the corresponding fedorePid in unit_fallback
   *
   * @param array $solr_arr
   * @param array $search_ids - contains the result
   */
  private function extract_unit_id_from_solr($solr_arr, &$search_ids) {
    static $u_err = 0;
    $search_ids = array();
    if ($this->collapsing_field) {
      $solr_groups = &$solr_arr['grouped'][$this->collapsing_field]['groups'];
      foreach ($solr_groups as &$gdoc) {
        if ($uid = $gdoc['doclist']['docs'][0]['unit.id']) {
          $search_ids[] = self::scalar_or_first_elem($uid);
          $this->unit_fallback[self::scalar_or_first_elem($uid)][] = self::scalar_or_first_elem($gdoc['doclist']['docs'][0]['fedoraPid']);
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
          $this->unit_fallback[self::scalar_or_first_elem($uid)][] = self::scalar_or_first_elem($fdoc['fedoraPid']);
        }
        elseif (++$u_err < 10) {
          verbose::log(FATAL, 'Missing unit.id in solr_result. Record no: ' . (count($search_ids) + $u_err));
        }
      }
    }
  }

  /** \brief Return first element of array or the element for scalar vars
   *
   * @param mixed $mixed
   * @retval mixed
   */
  private function scalar_or_first_elem($mixed) {
    if (is_array($mixed) || is_object($mixed)) {
      return reset($mixed);
    }
    return $mixed;
  }

  /** \brief decides which formats to include in result and how the should be build
   *
   * @param mixed $objectFormat
   * @param array $open_format
   * @param array $solr_format
   * @retval array 
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
   * @param string $pid
   * @retval array - of 'have' and 'lend'
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
    verbose::log(DEBUG, 'get_holdings:(' . $this->watch->splittime('holdings') . '): ' . $hold_url);
    $curl_err = $this->curl->get_status();
    if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
      verbose::log(FATAL, 'holdings_db http-error: ' . $curl_err['http_code'] . ' from: ' . $hold_url);
      $holds = array('have' => 0, 'lend' => 0);
    }
    else {
      if (empty($dom)) {
        $dom = new DomDocument();
      }
      $dom->preserveWhiteSpace = FALSE;
      if (@ $dom->loadXML($holds)) {
        $holds = array('have' => $dom->getElementsByTagName('librariesHave')->item(0)->nodeValue,
                       'lend' => $dom->getElementsByTagName('librariesLend')->item(0)->nodeValue);
      }
    }
    return $holds;
  }

  /** \brief Pick tags from solr result and create format
   *
   * @param array $collections- the structure is modified
   * @param array $solr
   * @param array $work_ids
   * @param array $pid_sort_keys
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
                          if ($this->feature_sw[$format_tag] == 'array') {   // more than one for this
                            foreach ($solr_doc[$format_tag] as $solr_tag) {
                              $help->_namespace = $solr_display_ns;
                              $help->_value = self::normalize_chars($solr_tag);
                              $mani->_value->{$tag_value}[] = $help;
                              unset($help);
                            }
                          }
                          else {
                            $mani->_value->$tag_value->_namespace = $solr_display_ns;
                            $mani->_value->$tag_value->_value = self::normalize_chars($solr_doc[$format_tag][0]);
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
   * @param array $collections- the structure is modified
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
   *
   * @param array $record_source- the source of the record, owner or collectionIdentifier
   * @param array $collection- the structure is modified
   * @param array $filter_settings - from the repository
   */
  private function filter_marcxchange($record_source, &$collection, $filter_settings) {
    foreach ($filter_settings as $rs_idx => $filters) {
      if (($marc_filters = $filters['marcxchange']) && preg_match('/' . $rs_idx . '/', $record_source)) {
        @ $mrec = &$collection->record->_value;
        foreach ($mrec->datafield as $idf => &$df) {
          foreach ($marc_filters as $tag => $filter) {
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

  /** \ brief Remove private/internal sections from a docbook record
   *
   * @param array $record_source- the source of the record, owner or collectionIdentifier
   * @param array $article- the structure is modified
   * @param array $filter_settings - from the reposotory
   */
  private function filter_docbook($record_source, &$article, $filter_settings) {
    foreach ($filter_settings as $rs_idx => $filters) {
      if (($docbook_filters = $filters['docbook']) && preg_match('/' . $rs_idx . '/', $record_source)) {
        foreach ($docbook_filters as $section_path => $match) {
          list($part, $item) = explode('/', $section_path);
          foreach ($article->$part as $idx => $section) {
            if ($section->_value->$item->_value == $match) {
              unset($article->{$part}[$idx]);
            }
          }
        }
      }
    }
  }

  /** \brief Remove not asked for formats from result
   *
   * @param array $collections - the structure is modified
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
   *
   * @param string $fpid - the pid to fetch
   * @param string $datastream_id - 
   * @retval boolean 
   */
  private function deleted_object($fpid, $datastream_id = 'commonData') {
    static $dom;
    $state = '';
    if ($obj_url = $this->repository['fedora_get_object_profile']) {
      self::get_fedora($obj_url, $fpid, $obj_rec);
      if ($obj_rec) {
        if (empty($dom))
          $dom = new DomDocument();
        $dom->preserveWhiteSpace = FALSE;
        if (@ $dom->loadXML($obj_rec))
          $state = $dom->getElementsByTagName('objState')->item(0)->nodeValue;
      }
    }
    return $state == 'D';
  }

  /** \brief Fetch a raw record from fedora
   *
   * @param string $fpid - the pid to fetch
   * @param string $fedora_xml - the record is returned
   * @param string $datastream_id - 
   * @retval mixed - error or NULL
   */
  private function get_fedora_raw($fpid, &$fedora_xml, $datastream_id = 'commonData') {
    $uri = $this->repository['fedora_get_raw'];
    if ($datastream_id && ($datastream_id <> 'commonData')) {
      $uri = str_replace('commonData', $datastream_id, $uri);
    }
    return self::get_fedora($uri, $fpid, $fedora_xml);
  }

  /** \brief Fetch a rels_addi record from fedora
   *
   * @param string $fpid - the pid to fetch
   * @param string $fedora_addi_xml - the record is returned
   * @retval mixed - error, FALSE or NULL
   */
  private function get_fedora_rels_addi($fpid, &$fedora_addi_xml) {
    if ($this->repository['fedora_get_rels_addi']) {
      return self::get_fedora($this->repository['fedora_get_rels_addi'], $fpid, $fedora_addi_xml, FALSE);
    }
    else {
      return FALSE;
    }
  }

  /** \brief Fetch a rels_hierarchy record from fedora
   *
   * @param string $fpid - the pid to fetch
   * @param string $fedora_hierarchy_xml - the record is returned
   * @retval mixed - error or NULL
   */
  private function get_fedora_rels_hierarchy($fpid, &$fedora_hierarchy_xml) {
    return self::get_fedora($this->repository['fedora_get_rels_hierarchy'], $fpid, $fedora_hierarchy_xml);
  }

  /** \brief Fetch datastreams for a record from fedora
   *
   * @param string $fpid - the pid to fetch
   * @param string $fedora_xml - the record is returned
   * @retval mixed - error or NULL
   */
  private function get_fedora_datastreams($fpid, &$fedora_xml) {
    return self::get_fedora($this->repository['fedora_get_datastreams'], $fpid, $fedora_xml);
  }

  /** \brief Setup call to fedora and execute it. The record is cached.
   *
   * @param string $uri - the fedora uri
   * @param string $fpid - the pid to fetch
   * @param string $rec - the record is returned
   * @param boolean $mandatory - how to handle a missing record/error
   * @retval mixed - error or NULL
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

  /** \brief Build search to include collections without holdings
   *
   * @param array $profile - the users search profile
   * @param string $index - the index to match collection identifiers against
   * @retval string - the SOLR filter query that represent the profile
   */
  private function set_holdings_include($profile, $index) {
    $colls = array();
    if (is_array($profile)) {
      foreach ($profile as $p) {
        if (self::xs_boolean($p['sourceSearchable'])) {
          $colls[] = $p['sourceIdentifier'];
        }
      }
    }
    return $index . ':(' . implode(' OR ', $colls) . ')';
  }

  /** \brief Build Solr filter_query parm
   *
   * @param array $profile - the users search profile
   * @param string $index - the index to filter (search) for collection identifiers
   * @retval string - the SOLR filter query that represent the profile
   */
  private function set_solr_filter($profile, $index = 'rec.collectionIdentifier') {
    $collection_query = $this->repository['collection_query'];
    $ret = array();
    if (is_array($profile)) {
      $this->collection_alias = self::set_collection_alias($profile);
      foreach ($profile as $p) {
        if (self::xs_boolean($p['sourceSearchable'])) {
          if ($filter_query = $collection_query[$p['sourceIdentifier']]) {
            $ret[] = '(' . $index . ':' . $p['sourceIdentifier'] . AND_OP . $filter_query . ')';
          }
          else {
            $ret[] = $index . ':' . $p['sourceIdentifier'];
          }
        }
      }
    }
    return implode(OR_OP, $ret);
  }

  /** \brief Set list of collection alias' depending on the user search profile
   * - in repository: ['collection_alias']['870876-anmeld'] = '870976-allanmeld';
   * 
   * @param array $profile - the users search profile
   * @retval array - collection alias'
   */
  private function set_collection_alias($profile) {
    $collection_alias = array();
    $alias = is_array($this->repository['collection_alias']) ? array_flip($this->repository['collection_alias']) : array();
    foreach ($profile as $p) {
      if (self::xs_boolean($p['sourceSearchable'])) {
        $si = $p['sourceIdentifier'];
        if (empty($alias[$si])) {
          $collection_alias[$si] = $si;
        }
        elseif (empty($collection_alias[$alias[$si]])) {
          $collection_alias[$alias[$si]] = $si;
        }
      }
    }
    return $collection_alias;
  }


  /** \brief Check an external relation against the search_profile
   *
   * @param string $collection - 
   * @param string $relation - 
   * @param array $profile - 
   * @retval boolean 
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
   * @param string $unit_id - 
   * @param string $relation - 
   * @param array $profile - 
   * @retval mixed - name of source or FALSE
   */
  private function check_valid_internal_relation($unit_id, $relation, $profile) {
    self::set_valid_relations_and_sources($profile);
    self::get_fedora_rels_hierarchy($unit_id, $rels_hierarchy);
    $pid = self::fetch_best_bib_object($rels_hierarchy, $unit_id);
    foreach (self::find_record_sources_and_group_by_relation($pid, $relation) as $to_record_source) {
      $valid = isset($this->valid_relation[$to_record_source][$relation]);
      if (DEBUG_ON) {
        echo "unit: $unit_id pid: $pid to: $to_record_source relation: $relation - " . ($valid ? '' : 'no ') . "go\n";
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
   * @param string $pid - 
   * @param string $relation - the relation to group by
   * @retval array - list of record sources
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
   * @param string $pid - 
   * @retval array - list of local datastreams found in the object
   */
  private function fetch_valid_sources_from_stream($pid) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = FALSE;
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
   * @param array $profile - 
   * @retval array - of valid relations for the search profile
   */
// TODO - valid_relation should also reflect collection_contained_in 
//        and then check in the collection is found in admin data in fedora object
  private function set_valid_relations_and_sources($profile) {
    if (empty($this->valid_relation) && is_array($profile)) {
      foreach ($profile as $src) {
        $this->searchable_source[$src['sourceIdentifier']] = self::xs_boolean($src['sourceSearchable']);
        if ($src['sourceContainedIn']) {
          $this->collection_contained_in[$src['sourceIdentifier']] = $src['sourceContainedIn'];
        }
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
        echo "rels:\n"; print_r($this->valid_relation); echo "source:\n"; print_r($this->searchable_source);
      }
    }
  }

  /** \brief return agency priority
   * 
   * @param string $agency 
   * @retval integer
   */
  private function agency_priority($agency) {
    if (!isset($this->agency_priority_list[$agency])) {
      $this->agency_priority_list[$agency] = count($this->agency_priority_list) + 1;
    }
    return $this->agency_priority_list[$agency];
  }

  /** \brief Fetch agency types from OpenAgency, cache the result, and return agency type for $agency
   *
   * @param string $agency -
   * @retval mixed - agency type (string) or FALSE
   */
  private function get_agency_type($agency) {
    static $agency_types;
    if (!isset($agency_types)) {
      require_once 'OLS_class_lib/agency_type_class.php';
      $cache = self::get_cache_info('agency');
      $agency_types = new agency_type(self::agency_uri('agency_types'), $cache['host'], $cache['port'], $cache['expire']);
    }
    $this->watch->start('agency_type');
    $agency_type = $agency_types->get_agency_type($agency);
    $this->watch->stop('agency_type');
    if ($agency_types->get_branch_type($agency) <> 'D') {
      return $agency_type;
    }

    return FALSE;
  }

  /** \brief Fetch priority list for agency
   *
   * @retval mixed - array of agencies
   */
  private function fetch_agency_show_priority() {
    static $agency_prio;
    if (!isset($agency_prio)) {
      require_once 'OLS_class_lib/show_priority_class.php';
      $cache = self::get_cache_info('agency');
      $agency_prio = new ShowPriority(self::agency_uri('agency_show_order'), $cache['host'], $cache['port'], $cache['expire']);
    }
    $this->watch->start('agency_prio');
    $agency_list = $agency_prio->get_priority($this->agency);
    $this->watch->stop('agency_prio');
    return ($agency_list ? $agency_list : array());
  }

  /** \brief Fetch a profile $profile_name for agency $agency
   *
   * @param string $agency -
   * @param string $profile_name - 
   * @retval mixed - profile (array) or FALSE
   */
  private function fetch_profile_from_agency($agency, $profile_name) {
    static $profiles;
    require_once 'OLS_class_lib/search_profile_class.php';
    $cache = self::get_cache_info('agency');
    if (empty($profiles)) {
      $profiles = new search_profiles(self::agency_uri('agency_search_profile'), $cache['host'], $cache['port'], $cache['expire']);
    }
    $this->watch->start('agency_profile');
    $profile = $profiles->get_profile($agency, $profile_name, $this->search_profile_version);
    $this->watch->stop('agency_profile');
    return (is_array($profile) ? $profile : FALSE);
  }

  /** \brief Fetch agency rules from OpenAgency, cache the result, and return agency rules for $agency
   *
   * @param string $agency -
   * @retval array - agency rules
   */
  private function agency_rule($agency, $name) {
    static $agency_rules = array();
    if (empty($agency_rules[$agency])) {
      static $open_agency;
      if (!isset($open_agency)) {
        require_once 'OLS_class_lib/open_agency_class.php';
        $cache = self::get_cache_info('agency');
        $open_agency = new OpenAgency(self::agency_uri('agency_rules'), $cache['host'], $cache['port'], $cache['expire']);
      }
      $this->watch->start('agency_rule');
      $rules = $open_agency->get_agency_rules($agency);
      $this->watch->stop('agency_rule');

      $agency_rules[$agency] = $rules;
    }
    return self::xs_boolean($agency_rules[$agency][$name]);
  }

  /** \brief Get info for OpenAgency, solr_file cache style/setup
   *
   * @retval array - cache information from config
   */
  private function get_cache_info($offset) {
    static $ret;
    if (empty($ret[$offset])) {
      $ret[$offset]['host'] = self::value_or_default($this->config->get_value($offset . '_cache_host', 'setup'),
                                                     $this->config->get_value('cache_host', 'setup'));
      $ret[$offset]['port'] = self::value_or_default($this->config->get_value($offset . '_cache_port', 'setup'),
                                                     $this->config->get_value('cache_port', 'setup'));
      $ret[$offset]['expire'] = self::value_or_default($this->config->get_value($offset . '_cache_expire', 'setup'),
                                                     $this->config->get_value('cache_expire', 'setup'));
    }
    return $ret[$offset];
  }

  /** \brief Fetch full agency url for a given operation
   *
   * @param string $agency_op - the agency operation
   * @retval string - agency url
   */
  private function agency_uri($agency_op) {
    $url = trim($this->config->get_value($agency_op, 'setup'));
    if (substr($url, 0, 4) != 'http') {
      $url = $this->config->get_value('open_agency', 'setup') . $url;
    }
    return $url;
  }

  /** \brief Extract source part of an ID 
   *
   * @param string $id - NNNNNN-xxxxxxx:nnnnnnn
   * @retval string - the record source (NNNNNN-xxxxxxx)
   */
  private function record_source_from_pid($id) {
    list($ret, $dummy) = explode(':', $id, 2);
    return $ret;
  }

  /** \brief Split a record source
   *
   * @param string $record_source - NNNNNN-xxxxxxx
   * @retval array 
   */
  private function split_record_source($record_source) {
    return explode('-', $record_source, 2);
  }

  /** \brief Split a pid in its elements
   *
   * @param string $pid - NNNNNN-xxxxxxx:nnnnnnn
   * @retval list of owner, collection and record-id
   */
  private function split_pid($pid) {
    list($owner_collection, $id) = explode(':', $pid, 2);
    list($owner, $coll) = explode('-', $owner_collection, 2);
    return array($owner, $coll, $id);
  }

  /** \brief Build bq (BoostQuery) as field:content^weight 
   *
   * @param mixed $boost - boost query
   * @retval string - SOLR boost string
   */
  private static function boostUrl($boost) {
    $ret = '';
    if ($boost) {
      $boosts = (is_array($boost) ? $boost : array($boost));
      foreach ($boosts as $bf) {
        if (empty($bf->_value->fieldValue->_value)) {
          if (!$weight = $bf->_value->weight->_value) {
            $weight = 1;
          }
          $ret .= '&bf=' .
                  urlencode('product(' . $bf->_value->fieldName->_value . ',' . $weight . ')');
        }
        else {
          $ret .= '&bq=' .
                  urlencode($bf->_value->fieldName->_value . ':"' .
                            str_replace('"', '', $bf->_value->fieldValue->_value) . '"^' .
                            $bf->_value->weight->_value);
        }
      }
    }
    return $ret;
  }

  /** \brief fetch a result from SOLR
   *
   * @param array $q - the extended solr query structure
   * @param integer $start - number of first (starting from 1) record
   * @param integer $rows - (maximum) number of records to return
   * @param string $sort - sorting scheme 
   * @param string $rank - ranking sceme
   * @param string $facets - facets-string
   * @param string $filter - the users search profile
   * @param string $boost - boost query (so far empty)
   * @param string $debug - include SOLR debug info
   * @param array $solr_arr - result from SOLR
   * @retval string - error if any, NULL otherwise
   */
  private function get_solr_array($q, $start, $rows, $sort, $rank, $facets, $filter, $boost, $debug, &$solr_arr) {
    $solr_urls[0] = self::create_solr_url($q, $start, $rows, $filter, $sort, $rank, $facets, $boost, $debug, $this->collapsing_field);
    return self::do_solr($solr_urls, $solr_arr);
  }

  /** \brief fetch hit count for each register in a given list
   *
   * @param array $eq - the edismax part of the parsed user query
   * @param array $guess - registers, filters, ... to get frequence for
   *
   * @reval array - hitcount for each register
   */
  private function get_register_freqency($eq, $guess) {
    $q = implode(OR_OP, $eq['q']);
    foreach ($eq['fq'] as $fq) {
      $filter .= '&fq=' . rawurlencode($fq);
    }
    foreach ($guess as $idx => $g) {
      $filter = implode('&fq=', $g['filter']);
      $solr_urls[]['url'] = $this->repository['solr'] .  
                            '?q=' . $g['register'] . '%3A(' . urlencode($q) .  ')&fq=' . $filter .  '&start=1&rows=0&wt=phps';
      $ret[$idx] = 0;
    }
    $err = self::do_solr($solr_urls, $solr_arr);
    $n = 0;
    foreach ($guess as $idx => $g) {
      $ret[$idx] = self::get_num_response($solr_arr[$n++]);
    }
    return $ret;
  }

  /** \brief build a solr url from a variety of parameters (and an url for debugging)
   *
   * @param array $eq - the extended solr query structure
   * @param integer $start - number of first (starting from 1) record
   * @param integer $rows - (maximum) number of records to return
   * @param string $filter - the users search profile
   * @param string $sort - sorting scheme 
   * @param string $rank - ranking sceme
   * @param string $facets - facets-string
   * @param string $boost - boost query (so far empty)
   * @param string $debug - include SOLR debug info
   * @param string $collapsing - field to collapse on or empty
   * @retval array - then SOLR url and url for debug purposes
   */
  private function create_solr_url($eq, $start, $rows, $filter, $sort='', $rank='', $facets='', $boost='', $debug=FALSE, $collapsing=FALSE) {
    if ($collapsing) {
      $collaps_pars = '&group=true&group.ngroups=true&group.facet=true&group.field=' . $collapsing;
    }
    $q = implode(AND_OP, $eq['q']);
    foreach ($eq['fq'] as $fq) {
      $filter .= '&fq=' . rawurlencode($fq);
    }
    $url = $this->repository['solr'] .
                    '?q=' . urlencode($q) .
                    '&fq=' . $filter .
                    '&start=' . $start .  $sort . $rank . $boost . $facets .  $collaps_pars .
                    '&defType=edismax';
    $debug_url = $url . '&fl=fedoraPid,unit.id&rows=1&debugQuery=on';
    $url .= '&fl=fedoraPid,unit.id&wt=phps&rows=' . $rows . ($debug ? '&debugQuery=on' : '');

    return array('url' => $url, 'debug' => $debug_url);
  }

  /** \brief send one or more requests to Solr
   *
   * @param array $urls - the url(s) to send to SOLR
   * @param array $solr_arr - result from SOLR
   * @retval string - error if any, NULL otherwise
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
      verbose::log(FATAL, 'Solr result in error: (' . $err['code'] . ') ' . preg_replace('/\s+/', ' ', $err['msg']));
      return 'Internal problem: Solr result contains error';
    }
  }

  /** \brief Parse a rels-ext record and extract the unit id
   *
   * @param string $rels_hierarchy - xml of the relation hierarchy object
   * @retval string - the corresponding unit id
   */
  private function parse_rels_for_unit_id($rels_hierarchy) {
    return self::parse_rels_hierarchy($rels_hierarchy, array('isPrimaryBibObjectFor', 'isMemberOfUnit'));
  }

  /** \brief Parse a rels-ext record and extract the work id
   *
   * @param string $rels_hierarchy - xml of the relation hierarchy object
   * @retval string - the corresponding work id
   */
  private function parse_rels_for_work_id($rels_hierarchy) {
    return self::parse_rels_hierarchy($rels_hierarchy, array('isPrimaryUnitObjectFor', 'isMemberOfWork'));
  }

  /** \brief Parse a rels-ext record and extract the first specified tag
   *
   * @param string $rels_hierarchy - xml of the relation hierarchy object
   * @param array $tags - the tags to look for and return
   * @retval string - the corresponding work id
   */
  private function parse_rels_hierarchy($rels_hierarchy, $tags) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = FALSE;
    }
    if (@ $dom->loadXML($rels_hierarchy)) {
      foreach ($tags as $tag) {
        $node = $dom->getElementsByTagName($tag);
        if (is_object($node) && $node->item(0)) {
          return($node->item(0)->nodeValue);
        }
      }
    }

    return FALSE;
  }

  /** \brief Fetch id for the users object ($this->agency_catalog_source)
   *         or the id of the primaryBibObject
   *
   * @param string $u_rel - xml of the unit object
   * @param boolean $fallback - use primaryObject if no member is acceptable
   * @retval string - "best" object_id from the unit
   */
  private function fetch_best_bib_object($u_rel, $unit_id, $fallback = TRUE) {
    $arr = self::parse_unit_for_best_agency($u_rel, $unit_id, $fallback);
    return $arr[1];
  }

  /** \brief Parse a unit object and return the id of the agency with best priority for the agency
   *
   * @param string $u_rel - xml of the unit object
   * @param boolean $fallback - use primaryObject if no member is acceptable
   * @retval array - of object_id, primary_object_id and array of unit members
   */
  private function parse_unit_for_best_agency($u_rel, $unit_id, $fallback = TRUE) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = FALSE;
    }
    $localdata_in_pid = $pid_870970_basis = $best_pid = FALSE;
    $in_870970_basis = $unit_members = array();
    if (@ $dom->loadXML($u_rel)) {
      $agency_type = self::get_agency_type($this->agency);
      $primary_pid = $dom->getElementsByTagName('hasPrimaryBibObject')->item(0)->nodeValue;
      $hmou = $dom->getElementsByTagName('hasMemberOfUnit');
      $priority_pids = array();
      foreach ($hmou as $mou) {
        $record_source = self::record_source_from_pid($mou->nodeValue);
        list($agency, $collection) = self::split_record_source($record_source);
        $prio = sprintf('%05s', self::agency_priority($agency));
        $priority_pids[$prio][] = $mou->nodeValue;
        if ($record_source == '870970-basis') {
          $pid_870970_basis = $mou->nodeValue;
          $id = explode(':', $mou->nodeValue);
          $local_streams = self::fetch_valid_sources_from_stream($mou->nodeValue);
          foreach ($local_streams as $stream) {
            if ($stream <> '870970-basis') {
              list($agency, $collection) = self::split_record_source($stream);
              $prio = sprintf('%05s', self::agency_priority($agency));
              $priority_pids[$prio][] = $stream . ':' . $id[1];
              $in_870970_basis[$stream . ':' . $id[1]] = '870970-basis:' . $id[1];
            }
          }
        }
      }
      ksort($priority_pids);
      if (empty($priority_pids) && $this->unit_fallback[$unit_id] && (!$fallback || !$primary_pid->length)) {
        $unit_fallback = TRUE;
        foreach ($this->unit_fallback[$unit_id] as $pid) {
          $priority_pids[] = array($pid);
        }
        verbose::log(ERROR, 'Empty unit: ' . $unit_id . ' - use pid from Solr instead');
      }
      foreach ($priority_pids as $pids_in_unit) {
        foreach ($pids_in_unit as $pid_in_unit) {
          $record_source = self::record_source_from_pid($pid_in_unit);
          list($agency, $collection) = self::split_record_source($record_source);
          if (self::is_valid_source($agency, $collection)) {
            if (!self::record_is_deleted($pid_in_unit, $in_870970_basis[$pid_in_unit])) {
              $unit_members[] = $pid_in_unit;
              //$unit_members[] = $pid_in_unit . (self::record_is_deleted($pid_in_unit, $in_870970_basis[$pid_in_unit]) ? 'D' : 'A');     // TEST
              //if (self::record_is_deleted($pid_in_unit, $in_870970_basis[$pid_in_unit])) die($pid_in_unit);          // TEST
              if (!$best_pid) {
                $best_pid = $pid_in_unit;
              }
            }
          }
        }
      }
      if ($unit_fallback && !$primary_pid) { 
        $primary_pid = $best_pid;
      }
      if ($fallback && !$best_pid) {   // this is the old style 
        $best_pid = $primary_pid;
        $unit_members[] = $best_pid;
      }
    }
    if ($in_870970_basis[$best_pid] && ($best_pid <> $pid_870970_basis)) {
      $localdata_in_pid = $in_870970_basis[$best_pid];
    }
    if (DEBUG_ON) {
      echo 'parse_unit_for_best_agency:: best_pid: ' . $best_pid . 
           ' primary_pid: ' . $primary_pid . 
           ' localdata_in_pid: ' . $localdata_in_pid . 
           ' unit_members: ' . str_replace(PHP_EOL, '', print_r($unit_members, TRUE)) . 
           PHP_EOL;
    }
    return(array($unit_members, $best_pid, $localdata_in_pid, $primary_pid));
  }

  /** \brief Read a record from fedora and tcheck if it is deleted
   *
   * @param string $pid - record pid
   * @param string $localdata_in_pid - in localData stream of this pid
   * @retval boolean - TRUE if record is deleted
   */
  private function record_is_deleted($pid, $localdata_in_pid) {
    if ($localdata_in_pid) {
      self::get_fedora_raw($localdata_in_pid, $fedora_result, 'localData.' . self::record_source_from_pid($pid));
      if (DEBUG_ON) {
        echo $pid . ' in ' . $localdata_in_pid . ' localData.' . self::record_source_from_pid($pid) . ' is ' . (empty($fedora_result) || strpos($fedora_result, '<recordStatus>delete</recordStatus>') ? '' : 'not ') . 'deleted' . PHP_EOL;
      }
    }
    else {
      self::get_fedora_raw($pid, $fedora_result);
      if (DEBUG_ON) {
        echo $pid . ' is ' . (empty($fedora_result) || strpos($fedora_result, '<recordStatus>delete</recordStatus>') ? '' : 'not ') . 'deleted' . PHP_EOL;
      }
    }
    //if (DEBUG_ON) { echo $fedora_result . PHP_EOL; }
    return empty($fedora_result) || strpos($fedora_result, '<recordStatus>delete</recordStatus>');
  }

  /** \brief check if a record source is contained in the search profile: searchable_source
   * - for agency_rule 'use_localdata_stream' records can be in 870970-basis localdata stream
   * - School libraries has to be in their own catalog or as part of 870970-skole
   * - Research libraries has to be in their own catalog or any reasearch library when 870970-forsk is in the search profile
   *   "part-of"- or "contains"- collections are handled by collection_contained_in structure obtained from openAgency
   * 
   * @param string $agency 
   * @param string $collection 
   * @retval boolean - TRUE is part of a source_grouping
   */
  private function is_valid_source($agency, $collection) {
    $agency_type = self::get_agency_type($agency);
    $coll_id = $agency . '-' . $collection;
    if (DEBUG_ON) {
      echo 'is_valid_source::' . 
           ' agency: ' . $agency .
           ' collection: ' . $collection .
           ' agency_type: ' . $agency_type . 
           ' agency_catalog: ' . $this->agency_catalog_source .
           ' type_catalog: ' . $this->agency_type .
           ' use_localdata_stream: ' . (self::agency_rule($agency, 'use_localdata_stream') ? 'TRUE' : 'FALSE') . 
           ' searchable: ' . ($this->searchable_source[$coll_id] ? 'TRUE' : 'FALSE') . 
           ' searchable_source: ' . ($this->searchable_source[$this->agency_catalog_source] ? 'TRUE' : 'FALSE') .
           ' collective_coll: ' . (self::is_collective_collection($coll_id) ? 'TRUE' : 'FALSE') . 
           ' contained_in_coll: ' . (self::is_contained_in_collection($coll_id) ? 'TRUE' : 'FALSE') . 
           PHP_EOL;
    }
    return (($this->searchable_source[$agency . '-' . $collection]) ||
            ($agency_type == 'Forskningsbibliotek' && $this->searchable_source['870970-forsk']) ||
            ($agency_type == 'Skolebibliotek' && $this->searchable_source['870970-skole']) ||
            (self::agency_rule($agency, 'use_localdata_stream') && $coll_id == '870970-basis' && $this->searchable_source[$this->agency_catalog_source]) ||
            (self::is_collective_collection($coll_id)) ||
            (self::is_contained_in_collection($coll_id))
    );
  }

  /** \brief Check if a pid has holdings
   *
   * @param string $pid - collection identifier
   * @retval integer - number of records found
   */
  private function has_holdings($pid) {
    if (empty($this->repository['holding'])) {
      return 1;
    }
    list($owner, $coll, $id)  = self::split_pid($pid);
    $solr_urls[0]['url'] = $this->repository['holding'] .
                  '?q=' . urlencode('holdingsitem.collectionId:"' . $owner . '-' . $id . '"') .
                  '&start=1&rows=0&defType=edismax&wt=phps';
      $solr_urls[0]['debug'] = str_replace('wt=phps', 'wt=xml', $solr_urls[0]['url']);
      if ($err = self::do_solr($solr_urls, $solr_arr)) {
        $error = $err;
        return 0;
      }
  }


  /** \brief Check if a collectionIdentifier contains a searchable "sub collection"
   *       - 150013-palle is not a datastream on its own, but is produced from 870970-basis
   *
   * @param string $collection_id - collection identifier
   * @retval boolean -
   */
  private function is_collective_collection($collection_id) {
    foreach ($this->collection_contained_in as $sub_collection => $container) {
      if ($collection_id == $container && $this->searchable_source[$sub_collection]) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /** \brief Check if a collectionIdentifier is part of a searchable "collective collection"
   *       - 870970-lokalbibl is a collective collecton for 159xxx-lokalbibl
   *
   * @param string $collection_id - collection identifier
   * @retval boolean -
   */
  private function is_contained_in_collection($collection_id) {
    if ($cont_id = $this->collection_contained_in[$collection_id]) {
      return $this->searchable_source[$cont_id];
    }
    return FALSE;
  }

  /** \brief Parse a work relation and return array of ids
   *
   * @param string $w_rel - xml of the work object
   * @param string $uid - id of the unit pointing to the work object
   * @retval array - of id's found in the work object. The first element is always set to $uid
   */
  private function parse_work_for_object_ids($w_rel, $uid) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = FALSE;
    }
    if (@ $dom->loadXML($w_rel)) {
      $res = array();
      $res[] = $uid;
      $r_list = $dom->getElementsByTagName('hasMemberOfWork');
      foreach ($r_list as $r) {
        if ($r->nodeValue <> $uid) $res[] = $r->nodeValue;
      }
      return $res;
    }
  }

  /** \brief Parse a fedora object and extract record and relations
   *
   * @param string $fedora_xml      - the bibliographic record from fedora
   * @param DOMDocument $fedora_addi_obj - corresponding relation object
   * @param array $unit_members     - list of unit members contained by the search profile
   * @param string $rels_type       - level for returning relations
   * @param string $rec_id          - record id of the record
   * @param string $filter          - agency filter
   * @param integer $holdings_count -
   * @param object $debug_info      -
   * @retval object - record object in a collection object
   */
  private function parse_fedora_object(&$fedora_xml, $fedora_addi_obj, $unit_members, $rels_type, $rec_id, $primary_id, $filter, $holdings_count, $debug_info='') {
    static $fedora_dom;
    if (empty($fedora_dom)) {
      $fedora_dom = new DomDocument();
      $fedora_dom->preserveWhiteSpace = FALSE;
    }
    if (@ !$fedora_dom->loadXML($fedora_xml)) {
      verbose::log(FATAL, 'Cannot load recid ' . $rec_id . ' into DomXml');
      return;
    }

    $rec = self::extract_record($fedora_dom, $rec_id);

    if (in_array($rels_type, array('type', 'uri', 'full'))) {
      self::get_relations_from_datastream_domobj($relations, $unit_members, $rels_type);
      self::get_relations_from_addi_stream($relations, $fedora_addi_obj, $rels_type, $filter);
    }

    $ret = $rec;
    $ret->identifier->_value = $rec_id;
    if ($primary_id) {
      $ret->primaryObjectIdentifier->_value = $primary_id;
    }
    if ($rs = self::get_record_status($fedora_dom)) {
      $ret->recordStatus->_value = $rs;
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
    if ($fa = self::scan_for_formats($fedora_dom)) {
      $ret->formatsAvailable->_value = $fa;
    }
    foreach ($unit_members as $um) {
        $u_member->identifier[]->_value = $um;
    }
    $ret->objectsAvailable->_value = $u_member;
    if ($debug_info) $ret->queryResultExplanation->_value = $debug_info;

    return $ret;
  }

  /** \brief Check if a record is searchable - currently obsolete
   *
   * @param string $unit_id - 
   * @param string $filter_q - the filter query (search profile)
   * @retval boolean - true if at least one record is found
   */
  private function is_searchable($unit_id, $filter_q) {
// do not check for searchability, since the relation is found in the search_profile, it's ok to use it
    return TRUE;
    if (empty($filter_q)) return TRUE;

    self::get_solr_array('unit.id:' . str_replace(':', '\:', $unit_id), 1, 0, '', '', '', rawurlencode($filter_q), '', '', $solr_arr);
    return $solr_arr['response']['numFound'];
  }

  /** \brief extract record status from fedora obj
   *
   * @param DOMDocument $dom - 
   * @retval string - record status
   */
  private function get_record_status(&$dom) {
    if ($p = &$dom->getElementsByTagName('adminData')->item(0)) {
      return $p->getElementsByTagName('recordStatus')->item(0)->nodeValue;
    }
  }

  /** \brief extract creation date from fedora obj
   *
   * @param DOMDocument $dom - 
   * @retval string - creation date
   */
  private function get_creation_date(&$dom) {
    if ($p = &$dom->getElementsByTagName('adminData')->item(0)) {
      return $p->getElementsByTagName('creationDate')->item(0)->nodeValue;
    }
  }

  /** \brief Check rec for available formats
   *
   * @param DOMDocument $dom - 
   * @retval object - available formats found
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

  /** \brief Handle relations located in commonData/localData streams in dom representation
   * @param object $relations - return parameter, the relations found
   * @param array $unit_members - list of the members in the unit contained by the search profile
   * @param string $rels_type - type, uri or full
   *
   */
  private function get_relations_from_datastream_domobj(&$relations, $unit_members, $rels_type) {
    static $stream_dom;
    if (empty($stream_dom)) {
      $stream_dom = new DomDocument();
    }
    $dub_check = array();
    foreach ($unit_members as $member) {
      self::get_fedora_raw($member, $fedora_streams);
      if (@ !$stream_dom->loadXML($fedora_streams)) {
        verbose::log(ERROR, 'Cannot load STREAMS for ' . $member . ' into DomXml');
      } 
      else {
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
    }
  }

  /** \brief Handle relations comming from addi streams
   *
   * @param object $relations - the structure to contain the relations found
   * @param string $fedora_addi_xml - an addi document from fedora
   * @param string $rels_type - level for returning relations (type, uri, full)
   * @param string $filter - agency filter
   */
  private function get_relations_from_addi_stream(&$relations, $fedora_addi_xml, $rels_type, $filter) {
    static $rels_dom;
    if (empty($rels_dom)) {
      $rels_dom = new DomDocument();
    }
    @ $rels_dom->loadXML($fedora_addi_xml);
    if ($rels_dom->getElementsByTagName('Description')->item(0)) {
      $relation_count = array();
      foreach ($rels_dom->getElementsByTagName('Description')->item(0)->childNodes as $tag) {
        if ($tag->nodeType == XML_ELEMENT_NODE) {
          if ($rel_prefix = array_search($tag->getAttribute('xmlns'), $this->xmlns))
            $this_relation = $rel_prefix . ':' . $tag->localName;
          else
            $this_relation = $tag->localName;
          if (($relation_count[$this_relation] < MAX_IDENTICAL_RELATIONS) &&
              ($rel_source = self::check_valid_internal_relation($tag->nodeValue, $this_relation, $this->search_profile))) {
            $relation_count[$this_relation]++;
            self::get_fedora_rels_hierarchy($tag->nodeValue, $rels_sys);
            list($rel_unit_members, $rel_oid, $localdata_in_pid, $primary_oid) = self::parse_unit_for_best_agency($rels_sys, $tag->nodeValue, FALSE);
            if ($rel_oid) {
              list($pid, $datastream)  = self::create_fedora_pid_and_stream($rel_oid, $localdata_in_pid);
              self::get_fedora_raw($pid, $related_obj, $datastream);
              if (@ !$rels_dom->loadXML($related_obj)) {
                verbose::log(FATAL, 'Cannot load ' . $pid . ' object from commonData into DomXml');
                $rels_dom = NULL;
              }
              $collection_id = self::get_element_from_admin_data($rels_dom, 'collectionIdentifier');
              if (empty($this->valid_relation[$collection_id])) {  // handling of local data streams
                if (DEBUG_ON) { 
                  echo 'Datastream(s): ' . implode(',', self::fetch_valid_sources_from_stream($pid)) . PHP_EOL;
                }
                foreach (self::fetch_valid_sources_from_stream($pid) as $source) {
                  if ($this->valid_relation[$source]) {
                    $collection_id = $source;
                    if (DEBUG_ON) { 
                      echo '--- use: ' . $source . ' rel_oid: ' . $rel_oid . ' stream: ' . self::set_data_stream_name($collection_id) . PHP_EOL;
                    }
                    self::get_fedora_raw($rel_oid, $related_obj, self::set_data_stream_name($collection_id));
                    if (@ !$rels_dom->loadXML($related_obj)) {
                      verbose::log(FATAL, 'Cannot load ' . $rel_oid . ' object from ' . $source . ' into DomXml');
                      $rels_dom = NULL;
                    }
                    break;
                  }
                }
              }
              if (isset($this->valid_relation[$collection_id]) && self::is_searchable($tag->nodeValue, $filter)) {
                $relation->relationType->_value = $this_relation;
                if ($rels_type == 'uri' || $rels_type == 'full') {
                  $relation->relationUri->_value = $rel_oid;
                }
                if (is_object($rels_dom) && ($rels_type == 'full')) {
                  $rel_obj = &$relation->relationObject->_value->object->_value;
                  $rel_obj = self::extract_record($rels_dom, $tag->nodeValue);
                  $rel_obj->identifier->_value = $rel_oid;
                  if ($cd = self::get_creation_date($rels_dom)) {
                    $rel_obj->creationDate->_value = $cd;
                  }
                  self::get_relations_from_datastream_domobj($ext_relations, $rel_unit_members, $rels_type);
                  if ($ext_relations) {
                    $rel_obj->relations->_value = $ext_relations;
                    unset($ext_relations);
                  }
                  if ($fa = self::scan_for_formats($rels_dom)) {
                    $rel_obj->formatsAvailable->_value = $fa;
                  }
                }
                if ($rels_type == 'type' || $relation->relationUri->_value) {
                  $relations->relation[]->_value = $relation;
                }
                unset($relation);
              }
            }
          }
        }
      }  // foreach ...
    }
  }

  /** \brief gets a given element from the adminData part
   *
   * @param DOMDocument $dom
   * @param string $tag_name
   * @retval string 
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
   *         which parts is set by the user (or defaults)
   *
   * @param DOMDocument $dom - the container for the bibliographic record(s)
   * @param string $rec_id - only used for log-line(s)
   * @retval object - the bibliographic object(s)
   */
  private function extract_record(&$dom, $rec_id) {
    $record_source = self::record_source_from_pid($rec_id);
    if ($alias = $this->collection_alias[$record_source]) {
      $record_source = $alias;
    }
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
            $ret->collection->_namespace = $this->xmlns['marcx'];
            if (is_array($this->repository['filter'])) {
              self::filter_marcxchange($record_source, $ret->collection->_value, $this->repository['filter']);
            }
          }
          break;
  
        case 'docbook':
          $record = &$dom->getElementsByTagNameNS($this->xmlns['docbook'], 'article');
          if ($record->item(0)) {
            $ret->article->_value = $this->xmlconvert->xml2obj($record->item(0));
            $ret->article->_namespace = $record->item(0)->lookupNamespaceURI('docbook');
            if (is_array($this->repository['filter'])) {
              self::filter_docbook($record_source, $ret->article->_value, $this->repository['filter']);
            }
          }
          break;
        case 'opensearchobject':
          $record = &$dom->getElementsByTagNameNS($this->xmlns['oso'], 'object');
          if ($record->item(0)) {
            $ret->object->_value = $this->xmlconvert->xml2obj($record->item(0));
            $ret->object->_namespace = $record->item(0)->lookupNamespaceURI('oso');
          }
          break;
      }
    }
    return $ret;
  }

  /** \brief Handle non-standardized characters - one day maybe, this code can be deleted
   *
   * @param string $s
   * @retval string 
   */
  private function normalize_chars($s) {
    $from[] = "\xEA\x9C\xB2"; $to[] = 'Aa';
    $from[] = "\xEA\x9C\xB3"; $to[] = 'aa';
    $from[] = "\XEF\x83\xBC"; $to[] = "\xCC\x88";   // U+F0FC -> U+0308
    return str_replace($from, $to, $s);
  }

  /** \brief Parse solr facets and build reply
   *
   * @param array $solr_arr - result from SOLR
   * array('facet_queries' => ..., 'facet_fields' => ..., 'facet_dates' => ...)
   *
   * @retval object
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

  /** \brief - 
   * @param misc $value
   * @param misc $default
   * @retval misc $value if TRUE, $default otherwise
   */
  private function value_or_default($value, $default) {
    return ($value ? $value : $default);
  }

  /** \brief - xs:boolean to php bolean
   * @param string $str
   * @retval boolean - return true if xs:boolean is so
   */
  private function xs_boolean($str) {
    return (strtolower($str) == 'true' || $str == 1);
  }

  /** \brief Helper function to set debug info
   *  
   * @param array $solr_debug - debuginfo from SOLR
   * @param object $rank_freq_debug - info about frequencies from ranking
   * @param object $best_match_debug - info about best match parameters
   * @retval object 
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
