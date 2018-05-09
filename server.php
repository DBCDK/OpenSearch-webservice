<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_NOTICE);

//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);
//-----------------------------------------------------------------------------
/**
 *
 * This file is part of Open Library System.
 * Copyright (c) 2009, Dansk Bibliotekscenter a/s,
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
require_once 'OLS_class_lib/open_agency_v2_class.php';

//-----------------------------------------------------------------------------
/**
 * Class OpenSearch
 */
class OpenSearch extends webServiceServer {
  protected $open_agency;
  protected $agency;
  protected $show_agency;
  protected $cql2solr;
  protected $curl;
  protected $cache;
  protected $search_profile;
  protected $profile;
  protected $repository_name;
  protected $repository; // array containing solr and record_repo uri's
  protected $query_language = 'cqleng';
  protected $number_of_record_repo_calls = 0;
  protected $number_of_record_repo_cached = 0;
  protected $agency_catalog_source = '';
  protected $agency_type = '';
  protected $filter_agency = '';
  protected $format = '';
  protected $which_rec_id = '';
  protected $separate_field_query_style = TRUE; // seach as field:(a OR b) ie FALSE or (field:a OR field:b) ie TRUE
  protected $valid_relation = [];
  protected $searchable_source = [];
  protected $searchable_forskningsbibliotek = FALSE;
  protected $search_filter_for_800000 = [];  // set when collection 800000-danbib or 800000-bibdk are searchable
  protected $search_profile_contains_800000 = FALSE;
  protected $collection_contained_in = [];
  protected $rank_frequence_debug;
  protected $collection_alias = [];
  protected $agency_priority_list = [];  // prioritised list af agencies for the actual agency
  protected $unit_fallback = [];     // if record_repo is updated and solr is not, this is used to find some record from the old unit
  protected $feature_sw = [];
  protected $user_param;


  /**
   * openSearch constructor.
   */
  public function __construct() {
    webServiceServer::__construct('opensearch.ini');

    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, self::value_or_default($this->config->get_value('curl_timeout', 'setup'), 20));
    $this->open_agency = new OpenAgency($this->config->get_value('agency', 'setup'));

    define('FIELD_UNIT_ID', 'unit.id');
    define('FIELD_FEDORA_PID', 'fedoraPid');
    define('FIELD_WORK_ID', 'rec.workId');
    define('FIELD_REC_ID', 'rec.id');
    define('HOLDINGS_AGENCY_ID_FIELD', self::value_or_default($this->config->get_value('field_holdings_agency_id', 'setup'), 'rec.holdingsAgencyId'));
    define('HOLDINGS', ' holdings ');

    define('DEBUG_ON', $this->debug);
    define('MAX_IDENTICAL_RELATIONS', self::value_or_default($this->config->get_value('max_identical_relation_names', 'setup'), 20));
    define('MAX_OBJECTS_IN_WORK', 100);
    define('AND_OP', ' AND ');
    define('OR_OP', ' OR ');
    define('RR_MARC_001_A', 'marc.001a');
    define('RR_MARC_001_B', 'marc.001b');
    define('COLLECTION_INDEX', 'rec.collectionIdentifier');
  }

  /** \brief Entry search: Handles the request and set up the response
   *
   * @param $param
   * @return mixed
   */
  public function search($param) {
    $this->user_param = $param;
    $ret_error = new stdClass();
    // set some defines
    if (!$this->aaa->has_right('opensearch', 500)) {
      Object::set_value($ret_error->searchResponse->_value, 'error', 'authentication_error');
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
      Object::set_value($param, 'agency', '100200');
      Object::set_value($param, 'profile', 'test');
    }
    if (empty($param->agency->_value) && empty($param->profile)) {
      Object::set_value($param, 'agency', $this->config->get_value('agency_fallback', 'setup'));
      Object::set_value($param, 'profile', $this->config->get_value('profile_fallback', 'setup')); // FVS
    }
    if ($param->profile && !is_array($param->profile)) {
      $param->profile = array($param->profile);
    }
    if (empty($param->agency->_value)) {
      $unsupported = 'Error: No agency in request';
    }
    elseif (strtolower($param->sort->_value) == 'random') {
      $unsupported = 'Error: random sort is currently disabled';
    }
    elseif (empty($param->profile)) {
      $unsupported = 'Error: No profile in request';
    }
    elseif (!($this->search_profile = self::fetch_profile_from_agency($param->agency->_value, $param->profile))) {
      $unsupported = 'Error: Cannot fetch profile(s): ' . self::stringify_obj_array($param->profile) .
        ' for ' . $param->agency->_value;
    }
    if ($unsupported) return $ret_error;

    $this->agency = $param->agency->_value;
    $this->show_agency = self::value_or_default($param->showAgency->_value, $this->agency);
    $this->profile = $param->profile;
    $this->agency_catalog_source = $this->agency . '-katalog';
    $this->filter_agency = self::set_solr_filter($this->search_profile);
    $this->split_holdings_include = self::split_collections_for_holdingsitem($this->search_profile);
    self::set_valid_relations_and_sources($this->search_profile);
    self::set_search_filters_for_800000_collection($param->forceFilter->_value);

    $this->feature_sw = $this->config->get_value('feature_switch', 'setup');

    $use_work_collection = ($param->collectionType->_value <> 'manifestation');
    if ($use_work_collection) {
      define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_collections', 'setup'), 50));
    }
    else {
      define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_manifestations', 'setup'), 200));
    }

    $sort = [];
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
    $this->agency_type = self::get_agency_type($this->agency);

    if ($this->repository['rawrepo']) {
      $this->watch->start('rawrepo');
      $this->cql2solr = new SolrQuery($this->repository, $this->config, $this->query_language);
      $this->watch->start('cql');
      $solr_query = $this->cql2solr->parse($param->query->_value);
      $this->watch->stop('cql');
      if ($solr_query['error']) {
        $error = self::cql2solr_error_to_string($solr_query['error']);
        return $ret_error;
      }
      VerboseJson::log(TRACE, array('message' => 'CQL to SOLR', 'query' => $param->query->_value, 'parsed' => preg_replace('/\s+/', ' ', print_r($solr_query, TRUE))));
      $q = implode(AND_OP, $solr_query['edismax']['q']);
      if (!in_array($this->agency, self::value_or_default($this->config->get_value('all_rawrepo_agency', 'setup'), []))) {
        $filter = rawurlencode(RR_MARC_001_B . ':(870970 OR ' . $this->agency . ')');
      }
      foreach ($solr_query['edismax']['fq'] as $fq) {
        $filter .= '&fq=' . rawurlencode($fq);
      }
      $solr_urls[0]['url'] = $this->repository['solr'] .
        '?q=' . urlencode($q) .
        '&fq=' . $filter .
        '&start=' . ($start - 1) .
        '&rows=' . $step_value .
        '&defType=edismax&wt=phps&fl=' . ($debug_query ? '&debugQuery=on' : '');
      $solr_urls[0]['debug'] = str_replace('wt=phps', 'wt=xml', $solr_urls[0]['url']);
      if ($err = self::do_solr($solr_urls, $solr_arr)) {
        $error = $err;
        return $ret_error;
      }
      $s11_agency = self::value_or_default($this->config->get_value('s11_agency', 'setup'), []);
      $collections = self::get_records_from_rawrepo($this->repository['rawrepo'], $solr_arr['response'], in_array($this->agency, $s11_agency));
      $this->watch->stop('rawrepo');
      if (is_scalar($collections)) {
        $error = $collections;
        return $ret_error;
      }
      $result = &$ret->searchResponse->_value->result->_value;
      Object::set_value($result, 'hitCount', self::get_num_found($solr_arr));
      Object::set_value($result, 'collectionCount', count($collections));
      Object::set_value($result, 'more', (($start + $step_value) <= $result->hitCount->_value ? 'true' : 'false'));
      $result->searchResult = &$collections;
      Object::set_value($result->statInfo->_value, 'time', $this->watch->splittime('Total'));
      Object::set_value($result->statInfo->_value, 'trackingId', VerboseJson::$tracking_id);
      if ($debug_query) {
        Object::set_value($result, 'queryDebugResult', self::set_debug_info($solr_arr['debug']));
      }
      return $ret;
    }

    /**
     *  Approach \n
     *  a) Do the search and collect enough work-ids to satisfy start and stepValue \n
     *  b) Search for units and pids using work-ids defined by start and stepValue \n
     *  c) Fetch records from corepo and include -addi recs, if relations is requested \n
     *  d) If relatins is requested, collect their identifier and search these to filter against search profile and
     *     fetch the relation records needed \n
     *  e) Build result from buffers, include relations and formatted records if requested
     */

    $use_work_collection |= $sort_types[$sort[0]] == 'random';
    $key_work_struct = md5($param->query->_value . $this->repository_name . $this->filter_agency . self::xs_boolean($param->allObjects->_value) .
                           $use_work_collection . implode('', $sort) . $rank . $boost_q . $this->config->get_inifile_hash());

    $this->cql2solr = new SolrQuery($this->repository, $this->config, $this->query_language, $this->split_holdings_include);
    $this->watch->start('cql');
    if ($param->skipFilter->_value == '1')    // for test
      $solr_query = $this->cql2solr->parse($param->query->_value);
    else
      $solr_query = $this->cql2solr->parse($param->query->_value, $this->search_filter_for_800000);
    self::modify_query_and_filter_agency($solr_query);
//var_dump($solr_query); var_dump($this->split_holdings_include); var_dump($this->search_filter_for_800000); die();
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

    if (is_array($solr_query['edismax']['ranking'])) {
      if (!$rank_cql = $rank_types['rank_cql'][reset($solr_query['edismax']['ranking'])]) {
        $rank_cql = $rank_types['rank_cql']['default'];
      }
      if ($rank_cql) {
        $rank = $rank_cql;
      }
    }
    $sort_q = '';
    if ($this->query_language == 'bestMatch') {
      $sort_q .= '&mm=1';
      $solr_query['edismax'] = $solr_query['best_match'];
      foreach ($solr_query['best_match']['sort'] as $key => $val) {
        $sort_q .= '&' . $key . '=' . urlencode($val);
        Object::set_value($best_match_debug, $key, $val);
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
    $rank_q = '';
    if ($rank_types[$rank]) {
      $rank_qf = $this->cql2solr->make_boost($rank_types[$rank]['word_boost']);
      $rank_pf = $this->cql2solr->make_boost($rank_types[$rank]['phrase_boost']);
      $rank_tie = $rank_types[$rank]['tie'];
      $rank_q = '&qf=' . urlencode($rank_qf) . '&pf=' . urlencode($rank_pf) . '&tie=' . $rank_tie;
    }

    $facet_q = empty($param->facets) ? '' : self::set_solr_facet_parameters($param->facets->_value);

    // TODO rows should max to like 5000 and use cursorMark to page forward. cursorMark need a sort paramater to work
    $rows = $step_value ? (($start + $step_value + 100) * 2) + 100 : 0;

    VerboseJson::log(TRACE, array('message' => 'CQL to SOLR', 'query' => $param->query->_value, 'parsed' => preg_replace('/\s+/', ' ', print_r($solr_query, TRUE))));

    // do the query
    $this->watch->start('Solr_ids');
    if ($sort[0] == 'random') {
      if ($err = self::get_solr_array($solr_query['edismax'], 0, 0, '', '', $facet_q, $filter_q, '', $debug_query, $solr_arr))
        $error = $err;
      else {
        $numFound = self::get_num_found($solr_arr);
      }
    }
    else {
      if ($err = self::get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, $facet_q, $filter_q, $boost_q, $debug_query, $solr_arr))
        $error = $err;
      else {
        $numFound = self::get_num_found($solr_arr);
        if ($step_value && $numFound) {
          self::extract_ids_from_solr($solr_arr, $solr_work_ids);
          if (!count($solr_work_ids)) {
            $error = 'Internal error: Cannot extract any id\'s from solr';
          }
        }
      }
    }
    $this->watch->stop('Solr_ids');

    if ($error) return $ret_error;

    if ($debug_query) {
      $debug_result = self::set_debug_info($solr_arr['debug'], $this->rank_frequence_debug, $best_match_debug);
    }
    if ($solr_arr['debug']) {
      $solr_timing = $solr_arr['debug']['timing'];
    }

    $facets = '';
    if ($facet_q) {
      $facets = self::parse_for_facets($solr_arr);
    }

    $this->watch->start('Build_id');
    $work_ids = $used_search_fids = [];
    if ($sort[0] == 'random') {
      $rows = min($step_value, $numFound);
      $more = $step_value < $numFound;
      for ($w_idx = 0; $w_idx < $rows; $w_idx++) {
        do {
          $no = rand(0, $numFound - 1);
        } while (isset($used_search_fid[$no]));
        $used_search_fid[$no] = TRUE;
        self::get_solr_array($solr_query['edismax'], $no, 1, '', '', '', $filter_q, '', $debug_query, $solr_arr);
        $uid = self::get_first_solr_element($solr_arr, FIELD_UNIT_ID);
        $work_ids[] = [$uid];
      }
    }
    else {
      $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                               $this->config->get_value('cache_port', 'setup'),
                               $this->config->get_value('cache_expire', 'setup'));
      $work_cache_struct = [];
      if (empty($_GET['skipCache'])) {
        if ($work_cache_struct = $this->cache->get($key_work_struct)) {
          VerboseJson::log(TRACE, 'Cache hit lines' . count($work_cache_struct));
        }
        else {
          $work_cache_struct = [];
          VerboseJson::log(TRACE, 'work_struct cache miss');
        }
      }

      if (DEBUG_ON) print_r($solr_work_ids);

      if (empty($step_value)) {
        $more = ($numFound >= $start);   // no need to find records, only hit count is returned and maybe facets
      }
      else {
        if ($err = self::build_work_struct_from_solr($work_cache_struct, $work_ids, $more, $solr_work_ids, $solr_query['edismax'], $start, $step_value, $rows, $sort_q, $rank_q, $filter_q, $boost_q, $use_work_collection, self::xs_boolean($param->allObjects->_value), $numFound, $debug_query)) {
          $error = $err;
          return $ret_error;
        }
      }
    }
    if ($this->cache && $this->cache->check()) {
      VerboseJson::log(TRACE, array('Cache set # work' => count($work_cache_struct)));
      $this->cache->set($key_work_struct, $work_cache_struct);
    }
    $this->watch->stop('Build_id');

    if (count($work_ids) < $step_value && count($solr_work_ids) < $numFound) {
      VerboseJson::log(WARNING, 'To few search_ids found in solr. Query' . implode(AND_OP, $solr_query['edismax']['q']));
    }

    if (DEBUG_ON) { echo PHP_EOL . 'work_ids:' . PHP_EOL; var_dump($work_ids); };

// fetch data to sort_keys and (if needed) sorl display format(s)
    $units_in_result = [];
    foreach ($work_ids as $work) {
      foreach ($work as $unit_id => $unit_pids) {
        $units_in_result[$unit_id] = '"' . $unit_id . '"';
      }
    }
    $add_queries = [FIELD_UNIT_ID . ':(' . implode(OR_OP, $units_in_result) . ')'];
    $this->watch->start('Solr_disp');
    $display_solr_arr = self::do_add_queries_and_fetch_solr_data_fields($add_queries, '*', self::xs_boolean($param->allObjects->_value), '');
    $this->watch->stop('Solr_disp');
    foreach ($display_solr_arr as $d_s_a) {
      foreach ($d_s_a['response']['docs'] as $solr_rec) {
        $unit_id = self::scalar_or_first_elem($solr_rec[FIELD_UNIT_ID]);
        if (count($solr_rec['rec.collectionIdentifier']) > $max_col[$unit_id]) {
          $unit_sort_keys[$unit_id] = $solr_rec['sort.complexKey'] . '  ' . $unit_id;
          $max_col[$unit_id] = count($solr_rec['rec.collectionIdentifier']);
        }
      }
    }
    if ($debug_query) {
      $explain_keys = array_keys($solr_arr['debug']['explain']);
      foreach ($solr_arr['response']['docs'] as $solr_idx => $solr_rec) {
        $unit_id = self::scalar_or_first_elem($solr_rec[FIELD_UNIT_ID]);
        if ($units_in_result[$unit_id]) {
          $explain[$unit_id] = $solr_arr['debug']['explain'][$explain_keys[$solr_idx]];
        }
      }
    }
//var_dump($add_queries); var_dump($display_solr_arr); var_dump($unit_sort_keys); die();

    // work_ids now contains the work-records and the fedoraPids they consist of
    // now fetch the records for each work/collection
    $collections = [];
    $rec_no = max(1, $start);
    $use_sort_complex_key = in_array($this->agency, self::value_or_default($this->config->get_value('use_sort_complex_key', 'setup'), []));
    $this->agency_priority_list = self::fetch_agency_show_priority();

    // fetch all addi and hierarchi records for all units in work_ids
    foreach ($work_ids as $idx => $work) {
      if (count($work) >= MAX_OBJECTS_IN_WORK) {
        VerboseJson::log(WARNING, 'record_repo work-record containing' . reset($work) . ' contains ' . count($work) . ' units. Cut work to first ' . MAX_OBJECTS_IN_WORK . ' units');
        array_splice($work_ids[$idx], MAX_OBJECTS_IN_WORK);
      }
    }

    // find and read best record in unit's. Fetch addi records if relations is part of the request
    list($raw_res, $primary_pids, $unit_info, $relation_units) = self::read_records_and_extract_data($work_ids, $param);

    // modify order of pids to reflect show priority, as returned from corepo
    foreach ($unit_info as $info_uid => $info) {
      if (count($info[0] > 1)) {
        foreach ($work_ids as $w => $work) {
          foreach ($work as $unit_id => $pids) {
            if ($info_uid == $unit_id) {
              $work_ids[$w][$unit_id] = $info[0];
            }
          }
        }
      }
    }

    // If relations is asked for, build a search to find available records using "full" search profile
    list($rel_res, $rel_unit_pids) = self::fetch_valid_relation_records($relation_units);

    // collect holdings if needed or directly requested
    $holdings_res = self::collect_holdings($work_ids, $param->includeHoldingsCount, $use_sort_complex_key, $unit_sort_keys);

    $record_repo_dom = new DomDocument();
    $record_repo_dom->preserveWhiteSpace = FALSE;
    $missing_record = $this->config->get_value('missing_record', 'setup');
    foreach ($work_ids as $work) {
      $objects = [];
      foreach ($work as $unit_id => $pids) {
        $rec_id = reset($pids);
        $sort_holdings = ' ';
        if ($holdings_res[$unit_id] && $use_sort_complex_key && (strpos($unit_sort_keys[$unit_id], HOLDINGS) !== FALSE)) {
          $sort_holdings = sprintf(' %04d ', 9999 - intval($holdings_res[$unit_id]['lend']));
        }
        $sort_key = str_replace(HOLDINGS, $sort_holdings, $unit_sort_keys[$unit_id]);
        if (@ !$record_repo_dom->loadXML($raw_res[$unit_id])) {
          VerboseJson::log(FATAL, 'Cannot load recid ' . $rec_id . ' into DomXml');
          if ($missing_record) {
            $record_repo_dom->loadXML(sprintf($missing_record, $rec_id));
          }
        }
        $objects[$sort_key] = new stdClass();
        $objects[$sort_key]->_value = self::build_record_object($record_repo_dom,
                                                                $raw_res[$unit_id],
                                                                reset($pids),
                                                                $rel_res,
                                                                $relation_units[$unit_id],
                                                                $rel_unit_pids,
                                                                $primary_pids[$unit_id],
                                                                $holdings_res[$unit_id],
                                                                $param);
        foreach ($pids as $um) {
          Object::set_array_value($u_member, 'identifier', $um);
        }
        Object::set_value($objects[$sort_key]->_value, 'objectsAvailable', $u_member);
        unset($u_member);
        if ($explain[$unit_id]) {
          Object::set_value($objects[$sort_key]->_value, 'queryResultExplanation', $explain[$unit_id]);
        }
      }
      $o = new stdClass();
      Object::set_value($o->collection->_value, 'resultPosition', $rec_no++);
      Object::set_value($o->collection->_value, 'numberOfObjects', count($objects));
      if (count($objects) > 1) {
        ksort($objects);
      }
      $o->collection->_value->object = $objects;
      Object::set($collections[], '_value', $o);
      unset($o);
    }
    if (DEBUG_ON) {
      echo PHP_EOL . 'unit_sort_keys:' . PHP_EOL; var_dump($unit_sort_keys);
      //echo PHP_EOL . 'explain:' . PHP_EOL; var_dump($explain);
      echo PHP_EOL . 'holdings_res:' . PHP_EOL; var_dump($holdings_res);
      echo PHP_EOL . 'raw_res:' . PHP_EOL; var_dump($raw_res);
      echo PHP_EOL . 'relation_units (relations found in records):' . PHP_EOL; var_dump($relation_units);
      echo PHP_EOL . 'rel_unit_pids (relations in search profile):' . PHP_EOL; var_dump($rel_unit_pids);
      echo PHP_EOL . 'rel_res (relation records):' . PHP_EOL; var_dump($rel_res);
      echo PHP_EOL . 'work_ids:' . PHP_EOL; var_dump($work_ids);
      echo PHP_EOL . 'unit_info:' . PHP_EOL; var_dump($unit_info);
      echo PHP_EOL . 'relations:' . PHP_EOL; var_dump($relations);
      echo PHP_EOL . 'solr_arr:' . PHP_EOL; var_dump($solr_arr);
    }

    if (isset($param->collectionType) && ($param->collectionType->_value == 'work-1')) {
      foreach ($collections as &$c) {
        $collection_no = 0;
        foreach ($c->_value->collection->_value->object as &$o) {
          if ($collection_no++) {
            foreach ($o->_value as $tag => $val) {
              if (!in_array($tag, ['identifier', 'creationDate', 'formatsAvailable'])) {
                unset($o->_value->$tag);
              }
            }
          }
        }
      }
    }

    if ($step_value) {
      if (!empty($this->format['found_open_format'])) {
        self::format_records($collections);
      }
      if (!empty($this->format['found_solr_format'])) {
        self::format_solr($collections, $display_solr_arr);
      }
      self::remove_unselected_formats($collections);
    }

    if (isset($_REQUEST['work']) && ($_REQUEST['work'] == 'debug')) {
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
    Object::set_value($result, 'hitCount', $numFound);
    Object::set_value($result, 'collectionCount', count($collections));
    Object::set_value($result, 'more', ($more ? 'true' : 'false'));
    self::set_sortUsed($result, $rank, $sort, $sort_types);
    $result->searchResult = $collections;
    Object::set_value($result, 'facetResult', $facets);
    if ($debug_query && $debug_result) {
      Object::set_value($result, 'queryDebugResult', $debug_result);
    }
    if ($solr_timing) {
      VerboseJson::log(STAT, array('solrTiming ' => json_encode($solr_timing)));
    }
    Object::set_value($result->statInfo->_value, 'fedoraRecordsCached', $this->number_of_record_repo_cached);
    Object::set_value($result->statInfo->_value, 'fedoraRecordsRead', $this->number_of_record_repo_calls);
    Object::set_value($result->statInfo->_value, 'time', $this->watch->splittime('Total'));
    Object::set_value($result->statInfo->_value, 'trackingId', VerboseJson::$tracking_id);

    VerboseJson::log(STAT, array_merge(
                             array('agency' => $this->agency,
                                   'profile' => self::stringify_obj_array($param->profile),
                                   'repoRecs' => $this->number_of_record_repo_calls,
                                   'repoCache' => $this->number_of_record_repo_cached,
                                   'query' => $param->query->_value),
                             $this->watch->get_timers()));

    //var_dump($ret); die();
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
   * @return object - the answer to the request
   */
  public function getObject($param) {
    $this->user_param = $param;
    $ret_error = new stdClass();
    $ret_error->searchResponse->_value->error->_value = &$error;
    if (!$this->aaa->has_right('opensearch', 500)) {
      $error = 'authentication_error';
      return $ret_error;
    }
    if ($error = self::set_repositories($param->repository->_value)) {
      VerboseJson::log(FATAL, $error);
      return $ret_error;
    }
    if (empty($param->agency->_value) && empty($param->profile)) {
      Object::set_value($param, 'agency', $this->config->get_value('agency_fallback', 'setup'));
      Object::set_value($param, 'profile', $this->config->get_value('profile_fallback', 'setup'));
    }
    if (empty($param->agency->_value)) {
        $error = 'Error: no agency specified';
        return $ret_error;
    }
    if (empty($param->profile->_value) && !is_array($param->profile)) {
        $error = 'Error: no profile specified';
        return $ret_error;
    }
    if ($param->profile && !is_array($param->profile)) {
      $param->profile = array($param->profile);
    }
    if ($this->agency = $param->agency->_value) {
      $this->profile = $param->profile;
      if ($this->profile) {
        if (!($this->search_profile = self::fetch_profile_from_agency($this->agency, $this->profile))) {
          $error = 'Error: Cannot fetch profile(s): ' . self::stringify_obj_array($this->profile) . ' for ' . $this->agency;
          return $ret_error;
        }
      }
      if (!$this->filter_agency = self::set_solr_filter($this->search_profile, TRUE)) {
        $error = 'Error: Unknown agency: ' . $this->agency;
        return $ret_error;
      }
      self::set_valid_relations_and_sources($this->search_profile);
      self::set_search_filters_for_800000_collection();
    }
    $this->show_agency = self::value_or_default($param->showAgency->_value, $this->agency);
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

    $fpids = self::as_array($param->identifier);
    $lpids = self::as_array($param->localIdentifier);
    $alpids = self::as_array($param->agencyAndLocalIdentifier);

    define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_manifestations', 'setup'), 200));
    if (MAX_STEP_VALUE <= count($fpids) + count($lpids) + count($alpids)) {
      $error = 'getObject can fetch up to ' . MAX_STEP_VALUE . ' records. ';
      return $ret_error;
    }

    $add_fl = '';
    if ($this->format['found_solr_format']) {
      foreach ($this->format as $f) {
        if ($f['is_solr_format']) {
          $add_fl .= ',' . $f['format_name'];
        }
      }
    }
    foreach ($lpids as $lid) {
      $fpid = new stdClass();
      $fpid->_value = $this->agency_catalog_source . ':' . str_replace(' ', '', $lid->_value);
      $fpids[] = $fpid;
      unset($fpid);
    }
    if (!empty($alpids)) {
      $agency_to_collection = self::value_or_default($this->config->get_value('agency_to_collection', 'setup'), []);
      foreach ($alpids as $alid) {
        if (!$collection = $agency_to_collection[$alid->_value->agency->_value]) {
          $collection = 'katalog';
        }
        $fpid = new stdClass();
        $fpid->_value = $alid->_value->agency->_value . '-' . $collection . ':' . str_replace(' ', '', $alid->_value->localIdentifier->_value);
        $fpids[] = $fpid;
        unset($fpid);
      }
    }
    if ($this->repository['rawrepo']) {
      foreach ($fpids as $fpid) {
        list($owner_collection, $id) = explode(':', $fpid->_value);
        list($owner, $coll) = explode('-', $owner_collection);
        if (($owner == $this->agency)
          || ($owner == '870970')
          || in_array($this->agency, self::value_or_default($this->config->get_value('all_rawrepo_agency', 'setup'), []))
        ) {
          $docs['docs'][] = [RR_MARC_001_A => $id, RR_MARC_001_B => $owner];
        }
      }
      $s11_agency = self::value_or_default($this->config->get_value('s11_agency', 'setup'), []);
      $collections = self::get_records_from_rawrepo($this->repository['rawrepo'], $docs, in_array($this->agency, $s11_agency));
      if (is_scalar($collections)) {
        $error = $collections;
        return $ret_error;
      }
      $result = &$ret->searchResponse->_value->result->_value;
      Object::set_value($result, 'hitCount', count($collections));
      Object::set_value($result, 'collectionCount', count($collections));
      Object::set_value($result, 'more', 'false');
      $result->searchResult = &$collections;
      Object::set_value($result->statInfo->_value, 'time', $this->watch->splittime('Total'));
      Object::set_value($result->statInfo->_value, 'trackingId', VerboseJson::$tracking_id);
      if ($debug_query) {
        Object::set_value($debug_result, 'rawQueryString', $solr_arr['debug']['rawquerystring']);
        Object::set_value($debug_result, 'queryString', $solr_arr['debug']['querystring']);
        Object::set_value($debug_result, 'parsedQuery', $solr_arr['debug']['parsedquery']);
        Object::set_value($debug_result, 'parsedQueryString', $solr_arr['debug']['parsedquery_toString']);
        Object::set_value($result, 'queryDebugResult', $debug_result);
      }
      return $ret;

    }
    $fpids = self::handle_sequencing($fpids);
    foreach ($fpids as $fpid) {
      $id_array[] = $fpid->_value;
      list($owner_collection, $id) = explode(':', $fpid->_value);
      list($owner, $coll) = explode('-', $owner_collection);
// 870970-basis contain records for libraries with 'use_localdata_stream switch set
// BUT, similar actions should be made for school libraries and 300000-katalog, but no switch is currently made for this
      if (self::agency_rule($owner, 'use_localdata_stream')) {
        $id_array[] = '870970-basis:' . $id;
        $localdata_object[$fpid->_value] = '870970-basis:' . $id;
      }
    }
    $this->cql2solr = new SolrQuery($this->repository, $this->config);
    $this->watch->start('cql');
    $chk_query = $this->cql2solr->parse('rec.id=(' . implode(OR_OP, $id_array) . ')');
    $this->watch->stop('cql');
    $solr_q = 'wt=phps' .
      '&q=' . urlencode(implode(AND_OP, $chk_query['edismax']['q'])) .
      '&fq=' . $filter_q .
      '&start=0' .
      '&rows=500' .
      '&defType=edismax' .
      '&fl=rec.collectionIdentifier,' . FIELD_WORK_ID . ',' . FIELD_FEDORA_PID . ',rec.id,' . FIELD_UNIT_ID . ',unit.isPrimaryObject' .
      $add_fl . '&trackingId=' . VerboseJson::$tracking_id;
    VerboseJson::log(TRACE, 'Search for pids in Solr: ' . $this->repository['solr'] . str_replace('wt=phps', '?', $solr_q));
    $curl = new curl();
    $curl->set_option(CURLOPT_TIMEOUT, self::value_or_default($this->config->get_value('curl_timeout', 'setup'), 20));
    $curl->set_post($solr_q); // use post here because query can be very long. curl has current 8192 as max length get url
    $solr_result = $curl->get($this->repository['solr']);
    $curl->close();
    $solr_2_arr[] = unserialize($solr_result);

    $work_ids = array();
    foreach ($fpids as $fpid_number => $fpid) {
      $pid = $basis_pid = array();
      $work_ids[$fpid_number] = array('NotFound' => array($fpid->_value => $fpid->_value));
      $localdata_pid = $localdata_object[$fpid->_value];
      foreach ($solr_2_arr as $s_2_a) {
        if ($s_2_a['response']['docs']) {
          foreach ($s_2_a['response']['docs'] as $fdoc) {
            $rec_id = $fdoc[FIELD_REC_ID];
            $unit_id = self::scalar_or_first_elem($fdoc[FIELD_UNIT_ID]);
            if (in_array($fpid->_value, $rec_id)) {
              $work_ids[$fpid_number] = array($unit_id => array($fpid->_value => $fpid->_value));
              break 2;
            }
            elseif (in_array($localdata_pid, $rec_id)) {
              $work_ids[$fpid_number] = array($unit_id => array($localdata_pid => $localdata_pid));
            }
          }
        }
      }
    }

    // find and read best record in unit's. Fetch addi records if relations is part of the request
    list($raw_res, $primary_pids, $unit_info, $relation_units) = self::read_records_and_extract_data($work_ids, $param);

    // If relations is asked for, build a search to find available records using "full" search profile
    list($rel_res, $rel_unit_pids) = self::fetch_valid_relation_records($relation_units);

    // collect holdings if requested
    $holdings_res = self::collect_holdings($work_ids, $param->includeHoldingsCount);

    if (DEBUG_ON) {
      echo PHP_EOL . 'fpids:' . PHP_EOL; var_dump($fpids);
      echo PHP_EOL . 'holdings_res:' . PHP_EOL; var_dump($holdings_res);
      echo PHP_EOL . 'raw_res:' . PHP_EOL; var_dump($raw_res);
      echo PHP_EOL . 'relation_units (relations found in records):' . PHP_EOL; var_dump($relation_units);
      echo PHP_EOL . 'rel_unit_pids (relations in search profile):' . PHP_EOL; var_dump($rel_unit_pids);
      echo PHP_EOL . 'rel_res (relation records):' . PHP_EOL; var_dump($rel_res);
      echo PHP_EOL . 'work_ids:' . PHP_EOL; var_dump($work_ids);
    }

    $record_repo_dom = new DomDocument();
    $record_repo_dom->preserveWhiteSpace = FALSE;
    $missing_record = $this->config->get_value('missing_record', 'setup');
    foreach ($work_ids as $rec_no => &$work) {
      foreach ($work as $unit_id => $pids) {
        Object::set_value($o->collection->_value, 'resultPosition', $rec_no + 1);
        Object::set_value($o->collection->_value, 'numberOfObjects', 1);
        if (@ !$record_repo_dom->loadXML($raw_res[$unit_id])) {
          VerboseJson::log(FATAL, 'Cannot load recid ' . reset($pids) . ' into DomXml');
          if ($missing_record) {
            $record_repo_dom->loadXML(sprintf($missing_record, reset($pids)));
          }
        }
        Object::set($o->collection->_value->object[], '_value',
                    self::build_record_object($record_repo_dom,
                                              $raw_res[$unit_id],
                                              reset($pids),
                                              $rel_res,
                                              $relation_units[$unit_id],
                                              $rel_unit_pids,
                                              $primary_pids[$unit_id],
                                              $holdings_res[$unit_id],
                                              $param));
        Object::set($collections[], '_value', $o);
        unset($o);
      }
    }

    //var_dump($corepo_urls); var_dump($corepo_res); var_dump($fpids); var_dump($match); var_dump($solr_2_arr); die();

    if ($this->format['found_open_format']) {
      self::format_records($collections);
    }
    if ($this->format['found_solr_format']) {
      self::format_solr($collections, $solr_2_arr);
    }
    self::remove_unselected_formats($collections);

    $result = &$ret->searchResponse->_value->result->_value;
    Object::set_value($result, 'hitCount', count($collections));
    Object::set_value($result, 'collectionCount', count($collections));
    Object::set_value($result, 'more', 'false');
    $result->searchResult = $collections;
    Object::set_value($result, 'facetResult', '');
    Object::set_value($result->statInfo->_value, 'fedoraRecordsCached', $this->number_of_record_repo_cached);
    Object::set_value($result->statInfo->_value, 'fedoraRecordsRead', $this->number_of_record_repo_calls);
    Object::set_value($result->statInfo->_value, 'time', $this->watch->splittime('Total'));
    Object::set_value($result->statInfo->_value, 'trackingId', VerboseJson::$tracking_id);

    VerboseJson::log(STAT, array_merge(
                             array('agency' => $this->agency,
                                   'profile' => self::stringify_obj_array($param->profile),
                                   'repoRecs' => $this->number_of_record_repo_calls,
                                   'repoCache' => $this->number_of_record_repo_cached,
                                   'ids' => implode(',', $id_array)),
                             $this->watch->get_timers()));
    return $ret;
  }

  /** \brief Entry info: collect info
   *
   * @param object $param - the user request
   * @return object - the answer to the request
   */
  public function info($param) {
    $result = &$ret->infoResponse->_value;
    Object::set_value($result->infoGeneral->_value, 'defaultRepository', $this->config->get_value('default_repository', 'setup'));
    $result->infoRepositories = self::get_repository_info();
    $result->infoObjectFormats = self::get_object_format_info();
    $result->infoSearchProfile = self::get_search_profile_info($param->agency->_value, $param->profile);
    $result->infoSorts = self::get_sort_info();
    $result->infoNameSpaces = self::get_namespace_info();
    VerboseJson::log(STAT, array_merge(
                             array('agency' => $this->agency,
                                   'profile' => self::stringify_obj_array($param->profile)),
                             $this->watch->get_timers()));
    return $ret;
  }

  /*
   ************************************* private ******************************************
   */

  /*************************************************
   *********** input handling functions  ***********
   *************************************************/

  /** \brief parse input for rank parameters
   *
   * @param object $param -       The request
   * @param string $rank -        Name of rank used by request
   * @param array $rank_types -   Settings for the given rank
   * @return boolean - TRUE if a ranking is found
   */
  private function parse_for_ranking($param, &$rank, &$rank_types) {
    if ($rr = $param->userDefinedRanking) {
      $rank = 'user_rank';
      $rank_user['tie'] = $rr->_value->tieValue->_value;
      $rfs = (is_array($rr->_value->rankField) ? $rr->_value->rankField : [$rr->_value->rankField]);
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
   * @return mixed - error or NULL
   */
  private function parse_for_sorting($param, &$sort, &$sort_types) {
    if (!is_array($sort)) {
      $sort = [];
    }
    if (!empty($param->sort)) {
      $random = FALSE;
      $sorts = (is_array($param->sort) ? $param->sort : [$param->sort]);
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
    return null;
  }

  /** \brief decides which formats to include in result and how the should be build
   *
   * @param mixed $objectFormat
   * @param array $open_format
   * @param array $solr_format
   * @return array
   */
  private function set_format($objectFormat, $open_format, $solr_format) {
    if (is_array($objectFormat))
      $help = $objectFormat;
    elseif (empty($objectFormat->_value))
      Object::set($help[], '_value', 'dkabm');
    else
      $help[] = $objectFormat;
    foreach ($help as $of) {
      if ($open_format[$of->_value]) {
        $ret[$of->_value] = ['user_selected' => TRUE, 'is_open_format' => TRUE, 'format_name' => $open_format[$of->_value]['format'], 'uri' => $open_format[$of->_value]['uri']];
        $ret['found_open_format'] = TRUE;
      }
      elseif ($solr_format[$of->_value]) {
        $ret[$of->_value] = ['user_selected' => TRUE, 'is_solr_format' => TRUE, 'format_name' => $solr_format[$of->_value]['format']];
        $ret['found_solr_format'] = TRUE;
      }
      else {
        $ret[$of->_value] = ['user_selected' => TRUE, 'is_solr_format' => FALSE];
      }
    }
    if ($ret['found_open_format'] || $ret['found_solr_format']) {
      if (empty($ret['dkabm']))
        $ret['dkabm'] = ['user_selected' => FALSE, 'is_open_format' => FALSE];
      if (empty($ret['marcxchange']))
        $ret['marcxchange'] = ['user_selected' => FALSE, 'is_open_format' => FALSE];
    }
    return $ret;
  }

  /** \brief Build Solr filter_query parm
   *
   * @param array $profile - the users search profile
   * @param string $add_relation_sources - include sources using relations
   * @return string - the SOLR filter query that represent the profile
   */
  private function set_solr_filter($profile, $add_relation_sources = FALSE) {
    $collection_query = $this->repository['collection_query'];
    $ret = [];
    if (is_array($profile)) {
      $this->collection_alias = self::set_collection_alias($profile);
      foreach ($profile as $p) {
        if (self::xs_boolean($p['sourceSearchable']) || ($add_relation_sources && count($p['relation']))) {
          if ($filter_query = $collection_query[$p['sourceIdentifier']]) {
            $ret[] = '(' . COLLECTION_INDEX . ':' . $p['sourceIdentifier'] . AND_OP . $filter_query . ')';
          }
          else {
            $ret[] = COLLECTION_INDEX . ':' . $p['sourceIdentifier'];
          }
        }
      }
    }
    return implode(OR_OP, $ret);
  }

  /** \brief set search_filter_for_800000
   *
   * @param boolean $test_force_filter
   */
  private function set_search_filters_for_800000_collection($test_force_filter = false) {
    static $part_of_bib_dk = [];
    static $use_holding = [];
    $containing_80000 = $this->config->get_value('collections_containing_800000', 'setup');
    foreach ($this->searchable_source as $source => $searchable) {
      $searchable = $test_force_filter || $searchable;     // for testing purposes
      if (($source == '800000-bibdk') && $searchable) {
        if (empty($part_of_bib_dk)) $part_of_bib_dk = $this->open_agency->get_libraries_by_rule('part_of_bibliotek_dk', 1, 'Forskningsbibliotek');
        if (empty($use_holding)) $use_holding = $this->open_agency->get_libraries_by_rule('use_holdings_item', 1, 'Forskningsbibliotek');

      }
      if (($source == '800000-danbib') && $searchable) {
        if (empty($use_holding)) $use_holding = $this->open_agency->get_libraries_by_rule('use_holdings_item', 1, 'Forskningsbibliotek');
      }
      if (in_array($source, $containing_80000) && $searchable) {         // clear search filter since "broader" collection is selected
        $part_of_bib_dk = [];
        $use_holding = [];
        break;
      }
    }
    if ($part_of_bib_dk || $use_holding) {
      $this->search_profile_contains_800000 = TRUE;
      VerboseJson::log(DEBUG, 'Filter 800000 part_of_bib_dk:: ' . count($part_of_bib_dk) . ' use_holding: ' . count($use_holding));
      // TEST $this->search_filter_for_800000 = 'holdingsitem.agencyId=(' . implode(' OR ', array_slice($part_of_bib_dk + $use_holding, 0, 3)) . ')';
      $this->search_filter_for_800000 = 'holdingsitem.agencyId:(' . implode(' OR ', $part_of_bib_dk + $use_holding) . ')';
    }
  }

  /** \brief Build bq (BoostQuery) as field:content^weight
   *
   * @param mixed $boost - boost query
   * @return string - SOLR boost string
   */
  private static function boostUrl($boost) {
    $ret = '';
    if ($boost) {
      $boosts = (is_array($boost) ? $boost : [$boost]);
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

  /** \brief Build search to include collections without holdings
   *
   * @param array $profile - the users search profile
   * @param string $add_relation_sources - include sources using relations
   * @return string - the SOLR filter query that represent the profile
   */
  private function split_collections_for_holdingsitem($profile, $add_relation_sources = FALSE) {
    $collection_query = $this->repository['collection_query'];
    $filtered_collections = $normal_collections = [];
    if (is_array($profile)) {
      $this->collection_alias = self::set_collection_alias($profile);
      foreach ($profile as $p) {
        if (self::xs_boolean($p['sourceSearchable']) || ($add_relation_sources && count($p['relation']))) {
          if ($p['sourceIdentifier'] == $this->agency_catalog_source || $p['sourceIdentifier'] == '870970-basis') {
            $filtered_collections[] = $p['sourceIdentifier'];
          }
          else {
            if ($filter_query = $collection_query[$p['sourceIdentifier']]) {
              $normal_collections[] = '(' . COLLECTION_INDEX . ':' . $p['sourceIdentifier'] . AND_OP . $filter_query . ')';
            }
            else {
              $normal_collections[] = $p['sourceIdentifier'];
            }
          }
        }
      }
    }
    return
      ($normal_collections ? COLLECTION_INDEX . ':(' . implode(' OR ', $normal_collections) . ') OR ' : '') .
      ($filtered_collections ? '(' . COLLECTION_INDEX . ':(' . implode(' OR ', $filtered_collections) . ') AND %s)' : '%s');
  }

  /** \brief Set list of collection alias' depending on the user search profile
   * - in repository: ['collection_alias']['870876-anmeld'] = '870976-allanmeld';
   *
   * @param array $profile - the users search profile
   * @return array - collection alias'
   */
  private function set_collection_alias($profile) {
    $collection_alias = [];
    $alias = is_array($this->repository['collection_alias']) ? array_flip($this->repository['collection_alias']) : [];
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


  /** \brief Sets this->repository from user parameter or defaults to ini-file setup
   *
   * @param string $repository
   * @param boolean $cql_file_mandatory
   * @return mixed - error or NULL
   */
  private function set_repositories($repository, $cql_file_mandatory = TRUE) {
    $repositories = $this->config->get_value('repository', 'setup');
    if (!$this->repository_name = $repository) {
      $this->repository_name = $this->config->get_value('default_repository', 'setup');
    }
    if ($this->repository = $repositories[$this->repository_name]) {
      foreach ($repositories['defaults'] as $key => $url_par) {
        if (empty($this->repository[$key])) {
          $this->repository[$key] = self::expand_default_repository_setting($key) ? $this->repository['fedora'] . $url_par : $url_par;
        }
      }
      $handler_format = &$this->repository['handler_format'];
      if ($handler_format['use_holding_block_join'] && is_array($handler_format['holding_block_join'])) {
        $handler_format['holding'] = $handler_format['holding_block_join'];
      }
      if ($cql_file_mandatory && empty($this->repository['cql_file'])) {
        VerboseJson::log(FATAL, 'cql_file not defined for repository: ' . $this->repository_name);
        return 'Error: cql_file not defined for repository: ' . $this->repository_name;
      }
      if ($this->repository['cql_file']) {
        if (!$this->repository['cql_settings'] = self::get_solr_file($this->repository['cql_file'])) {
          if (!$this->repository['cql_settings'] = @ file_get_contents($this->repository['cql_file'])) {
            VerboseJson::log(FATAL, 'Cannot get cql_file (' . $this->repository['cql_file'] . ') from local directory. Repository: ' . $this->repository_name);
            return 'Error: Cannot find cql_file for repository: ' . $this->repository_name;
          }
          VerboseJson::log(ERROR, 'Cannot get cql_file (' . $this->repository['cql_file'] . ') from SOLR - use local version. Repository: ' . $this->repository_name);
        }
      }
      if (empty($this->repository['filter'])) {
        $this->repository['filter'] = [];
      }
    }
    else {
      return 'Error: Unknown repository: ' . $this->repository_name;
    }
    return null;
  }


  /**
   * @param $key
   * @return bool
   */
  private function expand_default_repository_setting($key) {
    return (in_array(substr($key, 0, 7), ['fedora_', 'corepo_']));
  }

  /** handle sequencing with , as opposed to repeating the 'identifier' tag
   *
   * @param $val_arr
   * @return array
   */
  private function handle_sequencing($val_arr) {
    $ret = [];
    foreach ($val_arr as $entry) {
      $list = explode(',', $entry->_value);
      foreach ($list as $pid) {
        Object::set($ret[], '_value', $pid);
      }
    }
    return $ret;
  }

  /**************************************************
   *********** output handling functions  ***********
   **************************************************/


  /** \brief sets sortUsed if rank or sort is used
   *
   * @param object $ret - modified
   * @param string $rank
   * @param array $sort
   * @param array $sort_types
   */
  private function set_sortUsed(&$ret, $rank, $sort, $sort_types) {
    if (isset($rank)) {
      if (substr($rank, 0, 9) != 'user_rank') {
        Object::set_value($ret, 'sortUsed', $rank);
      }
    }
    elseif (!empty($sort)) {
      if ($key = array_search($sort, $sort_types)) {
        Object::set_value($ret, 'sortUsed', $key);
      }
      else {
        foreach ($sort as $s) {
          Object::set_array_value($ret, 'sortUsed', $s);
        }
      }
    }
  }

  /** \brief Helper function to set debug info
   *
   * @param array $solr_debug - debuginfo from SOLR
   * @param mixed $rank_freq_debug - info about frequencies from ranking
   * @param mixed $best_match_debug - info about best match parameters
   * @return object
   */
  private function set_debug_info($solr_debug, $rank_freq_debug = '', $best_match_debug = '') {
    Object::set_value($ret, 'rawQueryString', $solr_debug['rawquerystring']);
    Object::set_value($ret, 'queryString', $solr_debug['querystring']);
    Object::set_value($ret, 'parsedQuery', $solr_debug['parsedquery']);
    Object::set_value($ret, 'parsedQueryString', $solr_debug['parsedquery_toString']);
    if ($best_match_debug) {
      Object::set_value($ret, 'bestMatch', $best_match_debug);
    }
    if ($rank_freq_debug) {
      Object::set_value($ret, 'rankFrequency', $rank_freq_debug);
    }
    return $ret;
  }

  /** \brief Pick tags from solr result and create format
   *
   * @param array $collections - the structure is modified
   * @param array $solr
   */
  private function format_solr(&$collections, $solr) {
    //var_dump($collections); var_dump($solr); die();
    $solr_display_ns = $this->xmlns['ds'];
    $this->watch->start('format_solr');
    foreach ($this->format as $format_name => $format_arr) {
      if ($format_arr['is_solr_format']) {
        $format_tags = explode(',', $format_arr['format_name']);
        foreach ($collections as $idx => &$c) {
          $format_pids = [];
          foreach ($c->_value->collection->_value->object as $o_key => $obj) {
            $pid = $obj->_value->identifier->_value;
            $best_idx = $this->find_best_solr_rec($solr[0]['response']['docs'], FIELD_REC_ID, $pid);
            $solr_doc = &$solr[0]['response']['docs'][$best_idx];
            foreach ($format_tags as $format_tag) {
              if ($solr_doc[$format_tag] || $format_tag == 'fedora.identifier') {
                if (strpos($format_tag, '.')) {
                  list($tag_NS, $tag_value) = explode('.', $format_tag);
                }
                else {
                  $tag_value = $format_tag;
                }
                if ($format_tag == 'fedora.identifier') {
                  Object::set_namespace($mani->_value, $tag_value, $solr_display_ns);
                  Object::set_value($mani->_value, $tag_value, $pid);
                }
                else {
                  if (is_array($solr_doc[$format_tag])) {
                    if ($this->feature_sw[$format_tag] == 'array') {   // more than one for this
                      foreach ($solr_doc[$format_tag] as $solr_tag) {
                        $help = new stdClass();
                        $help->_namespace = $solr_display_ns;
                        $help->_value = self::normalize_chars($solr_tag);
                        $mani->_value->{$tag_value}[] = $help;
                        unset($help);
                      }
                    }
                    else {
                      Object::set_namespace($mani->_value, $tag_value, $solr_display_ns);
                      Object::set_value($mani->_value, $tag_value, self::normalize_chars($solr_doc[$format_tag][0]));
                    }
                  }
                  else {
                    Object::set_namespace($mani->_value, $tag_value, $solr_display_ns);
                    Object::set_value($mani->_value, $tag_value, self::normalize_chars($solr_doc[$format_tag]));
                  }
                }
              }
            }
            if ($mani) {   // should contain data, but for some odd reason it can be empty. Some bug in the solr-indexes?
              $mani->_namespace = $solr_display_ns;
              $manifestation->manifestation[$o_key] = $mani;
            }
            unset($mani);
            $format_pids[$o_key] = $obj->_value->identifier->_value;
          }
// need to sort objects to put data correct
          if (is_array($manifestation->manifestation) && count($manifestation->manifestation) > 1) {
            ksort($manifestation->manifestation);
          }
          Object::set_namespace($c->_value->formattedCollection->_value, $format_name, $solr_display_ns);
          Object::set_value($c->_value->formattedCollection->_value, $format_name, $manifestation);
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
   * @param array $collections - the structure is modified
   */
  private function format_records(&$collections) {
    static $formatRecords;
    $this->watch->start('format');
    foreach ($this->format as $format_name => $format_arr) {
      if ($format_arr['is_open_format']) {
        if ($open_format_uri = $this->config->get_value('ws_open_format_uri', 'setup')) {
          Object::set_namespace($f_obj, 'formatRequest', $this->xmlns['of']);
          Object::set($f_obj->formatRequest->_value, 'originalData', $collections);
          // need to set correct namespace
          foreach ($f_obj->formatRequest->_value->originalData as $i => &$oD) {
            $save_ns[$i] = $oD->_namespace;
            $oD->_namespace = $this->xmlns['of'];
          }
          Object::set_namespace($f_obj->formatRequest->_value, 'outputFormat', $this->xmlns['of']);
          Object::set_value($f_obj->formatRequest->_value, 'outputFormat', $format_arr['format_name']);
          Object::set_namespace($f_obj->formatRequest->_value, 'outputType', $this->xmlns['of']);
          Object::set_value($f_obj->formatRequest->_value, 'outputType', 'php');
          Object::set_value($f_obj->formatRequest->_value, 'trackingId', VerboseJson::$tracking_id);
          $f_xml = $this->objconvert->obj2soap($f_obj);
          $this->curl->set_post($f_xml, 0);
          $this->curl->set_option(CURLOPT_HTTPHEADER, ['Content-Type: text/xml; charset=UTF-8'], 0);
          $f_result = $this->curl->get($format_arr['uri'] ? $format_arr['uri'] : $open_format_uri);
          $this->curl->set_option(CURLOPT_POST, 0, 0);
          $fr_obj = $this->objconvert->set_obj_namespace(unserialize($f_result), $this->xmlns['of']);
          // need to restore correct namespace
          foreach ($f_obj->formatRequest->_value->originalData as $i => &$oD) {
            $oD->_namespace = $save_ns[$i];
          }
          if (!$fr_obj) {
            $curl_err = $this->curl->get_status();
            VerboseJson::log(FATAL, 'openFormat http-error: ' . $curl_err['http_code'] . ' from: ' . $curl_err['url']);
          }
          else {
            $struct = key($fr_obj->formatResponse->_value);
            // if ($struct == 'error') ... 
            foreach ($collections as $idx => &$c) {
              Object::set($c->_value->formattedCollection->_value, $struct, $fr_obj->formatResponse->_value->{$struct}[$idx]);
            }
          }
          $this->curl->close();
        }
        else {
          require_once('OLS_class_lib/format_class.php');
          if (empty($formatRecords)) {
            $formatRecords = new FormatRecords($this->config->get_section('format'), $this->xmlns['of'], $this->objconvert, $this->xmlconvert, $this->watch);
          }
          Object::set_value($param, 'outputFormat', $format_arr['format_name']);
          Object::set_namespace($param, 'outputFormat', $this->xmlns['of']);
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
            VerboseJson::log(FATAL, 'openFormat http-error: ' . $curl_err[0]['http_code'] . ' - check [format] settings in ini-file');
          }
          else {
            $struct = key($fr_obj[0]);
            foreach ($collections as $idx => &$c) {
              Object::set($c->_value->formattedCollection->_value, $struct, $fr_obj[$idx]->{$struct});
            }
          }
        }
      }
    }
    $this->watch->stop('format');
  }

  /** \brief Remove not asked for formats from result
   *
   * @param array $collections - the structure is modified
   */
  private function remove_unselected_formats(&$collections) {
    foreach ($collections as $idx => &$c) {
      foreach ($c->_value->collection->_value->object as &$o) {
        if (isset($this->format['dkabm']) && !$this->format['dkabm']['user_selected'])
          unset($o->_value->record);
        if (isset($this->format['marcxchange']) && !$this->format['marcxchange']['user_selected'])
          unset($o->_value->collection);
      }
    }
  }

  /** \brief Remove private/internal subfields from the marcxchange record
   * If all subfields in a field are removed, the field is removed as well
   * Controlled by the repository filter structure set in the services ini-file
   *
   * @param array $record_source - the source of the record, owner or collectionIdentifier
   * @param array $collection - the structure is modified
   * @param array $filter_settings - from the repository
   */
  private function filter_marcxchange($record_source, &$collection, $filter_settings) {
    foreach ($filter_settings as $rs_idx => $filters) {
      if (($marc_filters = $filters['marcxchange']) && preg_match('/' . $rs_idx . '/', $record_source)) {
        @ $mrec = &$collection->record->_value;
        if ($mrec->datafield) {
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
  }

  /** \ brief Remove private/internal sections from a docbook record
   *
   * @param array $record_source - the source of the record, owner or collectionIdentifier
   * @param array $article - the structure is modified
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


  /**********************************************
   *********** Solr related functions ***********
   **********************************************/


  /**
   * @param $s_docs
   * @param $field
   * @param $match
   */
  private function find_best_solr_rec($s_docs, $field, $match) {
    $best_idx = 0;
    $max_coll = -1;
    foreach ($s_docs as $s_idx => $s_rec) {
      if (in_array($match, $s_rec[$field]) && ($max_coll < count($s_rec['rec.collectionIdenfier']))) {
        $max_coll = count($s_rec['rec.collectionIdenfier']);
        $best_idx = $s_idx;
      }
    }
    return $best_idx;
  }

  /** \brief Alter the query and agency filter if HOLDINGS_AGENCY_ID_FIELD is used in query
   *         - replace 870970-basis with holdings_agency part and (bug: 21233) add holdings_agency part to the agency_catalog source
   *
   * @param object $solr_query
   */
  private function modify_query_and_filter_agency(&$solr_query) {
    foreach (['q', 'fq'] as $solr_par) {
      if ($solr_query['edismax']) {
        foreach ($solr_query['edismax'][$solr_par] as $q_idx => $q) {
          if (strpos($q, HOLDINGS_AGENCY_ID_FIELD . ':') === 0) {
            if (count($solr_query['edismax'][$solr_par]) == 1) {
              $solr_query['edismax'][$solr_par][$q_idx] = '*';
            }
            else {
              unset($solr_query['edismax'][$solr_par][$q_idx]);
            }
            $this->filter_agency = str_replace('rec.collectionIdentifier:870970-basis', $q, $this->filter_agency);
            $collect_agency = 'rec.collectionIdentifier:' . $this->agency_catalog_source;
            $filtered_collect_agency = '(' . $collect_agency . AND_OP . $q . ')';
            if (strpos($this->filter_agency, $filtered_collect_agency) === FALSE) {
              $this->filter_agency = str_replace($collect_agency, $filtered_collect_agency, $this->filter_agency);
            }
          }
        }
      }
    }
  }

  /** \brief Set the parameters to solr facets
   *
   * @param object $facets - the facet paramaters from the request
   * @return string - facet part of solr url
   */
  private function set_solr_facet_parameters($facets) {
    $max_threads = self::value_or_default($this->config->get_value('max_facet_threads', 'setup'), 50);
    $ret = '';
    if ($facets->facetName) {
      $facet_min = 1;
      if (isset($facets->facetMinCount->_value)) {
        $facet_min = $facets->facetMinCount->_value;
      }
      $ret .= '&facet=true&facet.threads=' . min(count($facets->facetName), $max_threads) . '&facet.limit=' . $facets->numberOfTerms->_value . '&facet.mincount=' . $facet_min;
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
    return $ret;
  }

  /** \brief Change cql_error to string
   *
   * @param array $solr_error
   * @return string
   */
  private function cql2solr_error_to_string($solr_error) {
    $str = '';
    foreach (['no' => '|: ', 'description' => '', 'details' => ' (|)', 'pos' => ' at pos '] as $tag => $txt) {
      list($pre, $post) = explode('|', $txt);
      if ($solr_error[0][$tag]) {
        $str .= $pre . $solr_error[0][$tag] . $post;
      }
    }
    return $str;
  }

  /** \brief Create solr array with records valid for the search-profile and parameters.
   *         If solr_formats is asked for, build list of fields to ask for
   *
   * @param array $add_queries
   * @param string $query
   * @param boolean $all_objects
   * @param string $filter_q
   * @return mixed - error string or SOLR array
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
   * @return mixed - error string or SOLR array
   */
  private function do_add_queries($add_queries, $query, $all_objects, $filter_q, $add_field_list = '') {
    foreach ($add_queries as $add_idx => $add_query) {
      if ($this->separate_field_query_style) {
        $add_q = '(' . $add_query . ')';
      }
      else {
        $add_q = $this->which_rec_id . ':(' . $add_query . ')';
      }
      $chk_query = $this->cql2solr->parse($query);
      self::modify_query_and_filter_agency($chk_query);
      if ($all_objects) {
        $chk_query['edismax']['q'] = [$add_q];
      }
      else {
        if ($add_query) {
          $chk_query['edismax']['q'][] = $add_q;
        }
      }
      if ($chk_query['error']) {
        $error = $chk_query['error'];
        return $ret_error;
      }
      $q = $chk_query['edismax'];
      $solr_url = self::create_solr_url($q, 0, 999999, $filter_q);
      list($solr_host, $solr_parm) = explode('?', $solr_url['url'], 2);
      $solr_parm .= '&fl=rec.collectionIdentifier,unit.isPrimaryObject,' . FIELD_UNIT_ID . ',sort.complexKey' . $add_field_list;
      VerboseJson::log(DEBUG, 'Re-search: ' . $this->repository['solr'] . '?' . str_replace('&wt=phps', '', $solr_parm) . '&debugQuery=on');
      if (DEBUG_ON) {
        echo 'post_array: ' . $solr_url['url'] . PHP_EOL;
      }

      $curl = new curl();
      $curl->set_option(CURLOPT_TIMEOUT, self::value_or_default($this->config->get_value('curl_timeout', 'setup'), 20));
      $curl->set_post($solr_parm); // use post here because query can be very long
      $curl->set_option(CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded; charset=utf-8'], 0);
      $solr_result = $curl->get($solr_host);
      $curl->close();
      if (!($solr_arr[$add_idx] = unserialize($solr_result))) {
        VerboseJson::log(FATAL, 'Internal problem: Cannot decode Solr re-search');
        return 'Internal problem: Cannot decode Solr re-search';
      }
    }
    return $solr_arr;
  }

  /** \brief Encapsules how to get the data from the first element
   *
   * @param array $solr_arr
   * @param string $element
   * @return mixed
   */
  private function get_first_solr_element($solr_arr, $element) {
    $solr_docs = &$solr_arr['response']['docs'];
    return self::scalar_or_first_elem($solr_docs[0][$element]);
  }

  /** \brief Encapsules how to get hit count from the solr result
   *
   * @param array $solr_arr
   * @return integer
   */
  private function get_num_found($solr_arr) {
    return $solr_arr['response']['numFound'];
  }

  /** \brief Encapsules extraction of ids (unit.id or workid) solr result
   *       - stores id and the corresponding fedorePid in unit_fallback
   *
   * @param array $solr_arr
   * @param array $search_ids - contains the result
   */
  private function extract_ids_from_solr($solr_arr, &$search_ids) {
    $solr_fields = [FIELD_UNIT_ID, FIELD_WORK_ID];
    static $u_err = 0;
    $search_ids = [];
    $solr_docs = &$solr_arr['response']['docs'];
    foreach ($solr_docs as &$fdoc) {
      $ids = [];
      foreach ($solr_fields as $fld) {
        if ($id = $fdoc[$fld]) {
          $ids[$fld] = self::scalar_or_first_elem($id);
        }
        else {
          if (++$u_err < 10) {
            VerboseJson::log(FATAL, 'Missing ' . $fld . ' in solr_result. Record no: ' . (count($search_ids) + $u_err));
          }
          break 2;
        }
      }
      $search_ids[] = $ids;
    }
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
   * @return string - error if any, NULL otherwise
   */
  private function get_solr_array($q, $start, $rows, $sort, $rank, $facets, $filter, $boost, $debug, &$solr_arr) {
    $solr_urls[0] = self::create_solr_url($q, $start, $rows, $filter, $sort, $rank, $facets, $boost, $debug);
    return self::do_solr($solr_urls, $solr_arr);
  }

  /** \brief fetch hit count for each register in a given list
   *
   * @param array $eq - the edismax part of the parsed user query
   * @param array $guess - registers, filters, ... to get frequence for
   *
   * @return array - hitcount for each register
   */
  private function get_register_freqency($eq, $guess) {
    $q = implode(OR_OP, $eq['q']);
    foreach ($eq['fq'] as $fq) {
      $filter .= '&fq=' . rawurlencode($fq);
    }
    foreach ($guess as $idx => $g) {
      $filter = implode('&fq=', $g['filter']);
      $solr_urls[]['url'] = $this->repository['solr'] .
        '?q=' . $g['register'] . '%3A(' . urlencode($q) . ')&fq=' . $filter . '&start=1&rows=0&wt=phps';
      $ret[$idx] = 0;
    }
    $err = self::do_solr($solr_urls, $solr_arr);
    $n = 0;
    foreach ($guess as $idx => $g) {
      $ret[$idx] = self::get_num_found($solr_arr[$n++]);
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
   * @param boolean $debug - include SOLR debug info
   * @return array - then SOLR url and url for debug purposes
   */
  private function create_solr_url($eq, $start, $rows, $filter, $sort = '', $rank = '', $facets = '', $boost = '', $debug = FALSE) {
    $q = '(' . implode(')' . AND_OP . '(', $eq['q']) . ')';   // force parenthesis around each AND-node, to fix SOLR problem. BUG: 20957
    if (is_array($eq['handler_var'])) {
      $handler_var = '&' . implode('&', $eq['handler_var']);
      $filter = '';  // search profile collection filter is done via fq parm created with handler_var
    }
    if (is_array($eq['fq'])) {
      foreach ($eq['fq'] as $fq) {
        $filter .= '&fq=' . rawurlencode($fq);
      }
    }
    $url = $this->repository['solr'] .
      '?q=' . urlencode($q) .
      '&fq=' . $filter .
      '&start=' . $start . $sort . $rank . $boost . $facets . $handler_var .
      '&defType=edismax';
    $debug_url = $url . '&fl=' . FIELD_FEDORA_PID . ',' . FIELD_UNIT_ID . ',' . FIELD_WORK_ID . ',' . FIELD_REC_ID . '&rows=1&debugQuery=on';
    $url .= '&fl=' . FIELD_FEDORA_PID . ',' . FIELD_UNIT_ID . ',' . FIELD_WORK_ID . ',' . FIELD_REC_ID . '&wt=phps&rows=' . $rows . ($debug ? '&debugQuery=on' : '');

    return ['url' => $url, 'debug' => $debug_url];
  }

  /** \brief send one or more requests to Solr
   *
   * @param array $urls - the url(s) to send to SOLR
   * @param array $solr_arr - result from SOLR
   * @return string - error if any, NULL otherwise
   */
  private function do_solr($urls, &$solr_arr) {
    foreach ($urls as $no => $url) {
      $url['url'] .= '&trackingId=' . VerboseJson::$tracking_id;
      VerboseJson::log(TRACE, 'Query: ' . $url['url']);
      if ($url['debug']) VerboseJson::log(DEBUG, 'Query: ' . $url['debug']);
      $this->curl->set_option(CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded; charset=utf-8'], $no);
      $this->curl->set_url($url['url'], $no);
    }
    $this->watch->start('solr');
    $solr_results = $this->curl->get();
    $this->curl->close();
    $this->watch->stop('solr');
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
      VerboseJson::log(FATAL, 'Solr result in error: (' . $err['code'] . ') ' . preg_replace('/\s+/', ' ', $err['msg']));
      return 'Internal problem: Solr result contains error';
    }
    return null;
  }

  /** \brief Selects a ranking scheme depending on some register frequency lookups
   *
   * @param array $solr_query - the parsed user query
   * @param array $ranks - list of defined rankings
   * @param string $user_filter - filter query as set by users profile
   *
   * @return string - the ranking scheme with highest number of hits
   *
   */
  private function guess_rank($solr_query, $ranks, $user_filter) {
    $guess = self::set_guesses($ranks, $user_filter);
    $freqs = self::get_register_freqency($solr_query['edismax'], $guess);
    $max = -1;
    foreach ($guess as $idx => $g) {
      $freq = $freqs[$idx] * $g['weight'];
      Object::set_value($this->rank_frequence_debug, $g['register'], $freq . ' (' . $freqs[$idx] . '*' . $g['weight'] . ')');
      $debug_str .= $g['scheme'] . ': ' . $freq . ' (' . $freqs[$idx] . '*' . $g['weight'] . ') ';
      if ($freq > $max) {
        $ret = $g['scheme'];
        $max = $freq;
      }
    }
    VerboseJson::log(DEBUG, 'Rank frequency set to ' . $ret . '. ' . $debug_str);
    return $ret;

  }

  /** \brief Set the guess-structure for the registers to search
   *
   * @param array $ranks - list of defined rankings
   * @param string $user_filter - filter query as set by users profile
   *
   * @return array - list of registers to search and the ranking to use
   *
   */
  private function set_guesses($ranks, $user_filter) {
    static $filters = [];
    $guess = [];
    $settings = $this->config->get_value('rank_frequency', 'setup');
    foreach ($settings as $r_idx => $setting) {
      if (isset($setting['register']) && $ranks[$setting['scheme']]) {
        foreach (['agency', 'register', 'scheme', 'weight', 'filter', 'profile'] as $par) {
          $guess[$r_idx][$par] = self::get_val_or_default($settings, $r_idx, $par);
        }
      }
    }
    $filters['user_profile'] = $user_filter;
    foreach ($guess as $idx => $g) {
      if (empty($filters[$g['profile']])) {
        if (!$filters[$g['profile']] = self::set_solr_filter(self::fetch_profile_from_agency($g['agency'], $g['profile']))) {
          $filters[$g['profile']] = $user_filter;
        }
      }
      $guess[$idx]['filter'] = [rawurlencode($g['filter']), $filters[$g['profile']]];
    }
    return $guess;
  }

  /** \brief fetch a file from the solr file directory
   *
   * @param string $name
   * @return string (xml)
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
      $this->curl->close();
    }
    return $xml;
  }

  /** \brief Isolation of creation of work structure for caching
   *
   * Parameters to a solr-search should be isolated in a class object - refactor one day
   *
   * @param $work_cache_struct
   * @param $work_struct
   * @param $more
   * @param $work_ids
   * @param $edismax
   * @param $start
   * @param $step_value
   * @param $rows
   * @param $sort_q
   * @param $rank_q
   * @param $filter_q
   * @param $boost_q
   * @param $use_work_collection
   * @param $all_objects
   * @param $num_found
   * @param $debug_query
   * @return mixed string or null
   */
  private function build_work_struct_from_solr(&$work_cache_struct, &$work_struct, &$more, &$work_ids, $edismax, $start, $step_value, $rows, $sort_q, $rank_q, $filter_q, $boost_q, $use_work_collection, $all_objects, $num_found, $debug_query) {
    for ($w_idx = 0; isset($work_ids[$w_idx]); $w_idx++) {
      $struct_id = $work_ids[$w_idx][FIELD_WORK_ID] . ($use_work_collection ? '' : '-' . $work_ids[$w_idx][FIELD_UNIT_ID]);
      if ($work_cache_struct[$struct_id]) continue;
      $work_cache_struct[$struct_id] = [];
      if (count($work_cache_struct) >= ($start + $step_value)) {
        $more = TRUE;
        VerboseJson::log(TRACE, 'SOLR stat: used ' . $w_idx . ' of ' . count($work_ids) . ' rows. start: ' . $start . ' step: ' . $step_value);
        break;
      }
      if (!isset($work_ids[$w_idx + 1]) && count($work_ids) < $num_found) {
        $this->watch->start('Solr_add');
        VerboseJson::log(WARNING, 'To few search_ids fetched from solr. Query: ' . implode(AND_OP, $edismax['q']) . ' idx: ' . $w_idx);
        $rows *= 2;
        if ($err = self::get_solr_array($edismax, 0, $rows, $sort_q, $rank_q, '', $filter_q, $boost_q, $debug_query, $solr_arr)) {
          $this->watch->stop('Solr_add');
          return $err;
        }
        else {
          $this->watch->stop('Solr_add');
          self::extract_ids_from_solr($solr_arr, $work_ids);
        }
      }
    }
    $work_slice = array_slice($work_cache_struct, ($start - 1), $step_value);
    if ($step_value) {
      foreach ($work_slice as $w_id => $w_list) {
        list($w_id, $u_id) = explode('-', $w_id);
        if (empty($w_list)) {
          $search_w[] = '"' . ($use_work_collection ? $w_id : $u_id) . '"';
        }
      }
      if (is_array($search_w)) {
        if ($all_objects) {
          $edismax['q'] = [];
        }
        $edismax['q'][] = ($use_work_collection ? FIELD_WORK_ID : FIELD_UNIT_ID ) . ':(' . implode(OR_OP, $search_w) . ')';
        if ($err = self::get_solr_array($edismax, 0, 99999, '', '', '', $filter_q, '', $debug_query, $solr_arr)) {
          return $err;
        }
        foreach ($solr_arr['response']['docs'] as $fdoc) {
          $unit_id = $fdoc[FIELD_UNIT_ID];
          $work_id = $fdoc[FIELD_WORK_ID];
          $struct_id = $work_id . ($use_work_collection ? '' : '-' . $unit_id);
          foreach ($fdoc[FIELD_REC_ID] as $rec_id) {
            if (self::is_corepo_pid($rec_id)) {
              $work_cache_struct[$struct_id][$unit_id][$rec_id] = $rec_id;;
            }
          }
        }
      }
    }
    $work_struct = array_slice($work_cache_struct, ($start - 1), $step_value);
    // var_dump($edismax); var_dump($work_struct); var_dump($work_cache_struct); var_dump($work_ids); die();
    return null;
  }


  /**************************************
   *********** repo functions ***********
   **************************************/


  /**
   * @param $work_ids
   * @param $param
   * @return array
   */
  private function read_records_and_extract_data($work_ids, $param) {
    $raw_urls = [];
    foreach ($work_ids as $work) {
      foreach ($work as $unit_id => $pids) {
        if ($unit_id <> 'NotFound') {
          $raw_urls[$unit_id] = self::corepo_get_url($unit_id, $pids);
          if (in_array($param->relationData->_value, ['type', 'uri', 'full'])) {
            $raw_urls[$unit_id . '-addi'] = self::record_repo_url('fedora_get_rels_addi', $unit_id);
          }
        }
      }
    }
    $raw_res = self::read_record_repo_all_urls($raw_urls);
    // unpack records and find and retrieve relations if requested and collect unit's from relations (if needed)
    $relation_units = [];
    $primary_pids = [];
    $unit_info = [];
    foreach ($raw_res as $unit_key => $r_res) {
      if (strpos($unit_key, '-addi')) {
        list($unit_id) = explode('-', $unit_key);
        $pid_related_from = $unit_info[$unit_id][1];
        $valid_relations = $this->valid_relation[self::record_source_from_pid($pid_related_from)];
        $relation_units[$unit_id] = self::parse_addi_for_units_in_relations($unit_key, $r_res, $valid_relations);
      }
      else {
        $help = json_decode($r_res);
        $raw_res[$unit_key] = $help->dataStream;
        $primary_pids[$unit_key] = $help->primaryPid;
        $unit_info[$unit_key] = [$help->pids, $help->pids[0], $help->primaryPid];
      }
    }
    return array($raw_res, $primary_pids, $unit_info, $relation_units);
  }

  /**
   * @param string $unit_id
   * @param array $pids
   * @return string
   */
  private function corepo_get_url($unit_id, $pids) {
    return sprintf($this->repository['corepo_get'], $unit_id, implode(',', $pids), $this->show_agency);
  }

  /** \brief Create record_repo url from settings and given id
   *
   * @param string $type - type of record_repo operation
   * @param string $id - id of record_repo record to fetch
   * @param string $datastream_id - name of datastream to use
   * @return string
   */
  private function record_repo_url($type, $id, $datastream_id = '') {
    $uri = $datastream_id ? str_replace('commonData', $datastream_id, $this->repository[$type]) : $this->repository[$type];
    return sprintf($uri, $id);
  }

  /** \brief Fetch datastreams for a record from record_repo
   *
   * @param string $fpid - the pid to fetch
   * @param string $record_repo_xml - the record is returned
   * @return mixed - error or NULL
   */
  private function read_record_repo_datastreams($fpid, &$record_repo_xml) {
    return self::read_record_repo(self::record_repo_url('fedora_get_datastreams', $fpid), $record_repo_xml);
  }

  /** \brief Setup call to record repository and execute it. The record is cached.
   *
   * @param string $record_uri - the record_repo uri
   * @param string $rec - the record is returned
   * @param boolean $mandatory - how to handle a missing record/error
   * @return mixed - error or NULL
   */
  private function read_record_repo($record_uri, &$rec, $mandatory = TRUE) {
    static $curl;
    if (empty($curl)) {
      $curl = new curl();
      $curl->set_option(CURLOPT_TIMEOUT, self::value_or_default($this->config->get_value('curl_timeout', 'setup'), 20));
    }
    VerboseJson::log(TRACE, 'repo_read: ' . $record_uri);
    if (DEBUG_ON) echo __FUNCTION__ . ':: ' . $record_uri . "\n";
    if ($this->cache && ($rec = $this->cache->get($record_uri))) {
      $this->number_of_record_repo_cached++;
    }
    else {
      $this->number_of_record_repo_calls++;
      $curl->set_authentication('fedoraAdmin', 'fedoraAdmin');
      $this->watch->start('record_repo');
      $rec = self::normalize_chars($curl->get($record_uri));
      $this->watch->stop('record_repo');
      $curl_err = $curl->get_status();
      $curl->close();
      if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
        $rec = '';
        if ($mandatory) {
          if ($curl_err['http_code'] == 404) {
            return 'record_not_found';
          }
          VerboseJson::log(FATAL, 'record_repo http-error: ' . $curl_err['http_code'] . ' from: ' . $record_uri .
                            ' request ' . preg_replace('/\s+/', ' ', print_r($this->user_param, TRUE)));
          return 'Error: Cannot fetch record: ' . $record_uri . ' - http-error: ' . $curl_err['http_code'];
        }
      }
      if ($this->cache) $this->cache->set($record_uri, $rec);
    }
    // else VerboseJson::log(TRACE, 'record_repo cache hit for ' . $fpid);
    return null;
  }

  /** \brief Get multiple urls and return result in structure with the same indices
   *
   * @param array $urls -
   * @return array
   */
  private function read_record_repo_all_urls($urls) {
    static $curl;
    if (empty($curl)) {
      $curl = new curl();
      $curl->set_option(CURLOPT_TIMEOUT, self::value_or_default($this->config->get_value('curl_timeout', 'setup'), 20));
    }
    if (empty($urls)) $urls = [];
    $ret = [];
    $res_map = [];
    $no = 0;
    foreach ($urls as $key => $uri) {
      VerboseJson::log(TRACE, 'repo_read: ' . $uri);
      if (DEBUG_ON) echo __FUNCTION__ . ':: ' . $uri . "\n";
      if ($this->cache && ($ret[$key] = $this->cache->get($uri))) {
        $this->number_of_record_repo_cached++;
      }
      else {
        $this->number_of_record_repo_calls++;
        $res_map[$no] = $key;
        $curl->set_url($uri, $no);
        $no++;
      }
    }
    if (count($res_map)) {
      $this->watch->start('record_repo');
      $recs = $curl->get();
      $this->watch->stop('record_repo');
      $status = $curl->get_status();
      $curl->close();
      if (!is_array($recs)) {
        $recs = [$recs];
        $status = [$status];
      }
      foreach ($recs as $no => $rec) {
        if (isset($res_map[$no])) {
          if (!strpos($urls[$res_map[$no]], 'RELS-EXT') && (empty($rec) || $status[$no]['http_code'] > 299)) {
            VerboseJson::log(ERROR, 'record_repo http-error: ' . $status[$no]['http_code'] . ' from: ' . $urls[$res_map[$no]] .
                              ' record ' . preg_replace('/\s+/', ' ', $rec));
            if ($this->cache) $this->cache->set($urls[$res_map[$no]], $rec);
          }
          $ret[$res_map[$no]] = self::normalize_chars($rec);
          if (!empty($rec) && $this->cache) $this->cache->set($urls[$res_map[$no]], $ret[$res_map[$no]]);
        }
      }
    }
    return $ret;
  }

  /** \brief Reads records from Raw Record Postgress Database
   *
   * @param string $rr_service Address fo raw_repo service end point
   * @param array $solr_response Response from a solr search in php object
   * @param boolean $s11_records_allowed restricted record
   *
   * @return mixed  array of collections or error string
   */
  private function get_records_from_rawrepo($rr_service, $solr_response, $s11_records_allowed) {
    if (empty($solr_response['docs'])) {
      return null;
    }
    $p_mask = '<?xml version="1.0" encoding="UTF-8"?' . '>' . PHP_EOL . '<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body><fetchRequest xmlns="http://oss.dbc.dk/ns/rawreposervice"><records>' . PHP_EOL . '%s</records></fetchRequest></S:Body></S:Envelope>';
    $r_mask = '<record><bibliographicRecordId>%s</bibliographicRecordId><agencyId>%s</agencyId><mode>%s</mode><allowDeleted>true</allowDeleted><includeAgencyPrivate>true</includeAgencyPrivate></record>';
    $ret = [];
    $rec_pos = $solr_response['start'];
    foreach ($solr_response['docs'] as $solr_doc) {
      $bib = self::scalar_or_first_elem($solr_doc[RR_MARC_001_B]);
      $post .= sprintf($r_mask, self::scalar_or_first_elem($solr_doc[RR_MARC_001_A]), $bib, ($bib == '870970' ? 'MERGED' : 'RAW')) . PHP_EOL;
    }
    $this->curl->set_post(sprintf($p_mask, $post), 0); // use post here because query can be very long
    $this->curl->set_option(CURLOPT_HTTPHEADER, ['Accept:application/xml;', 'Content-Type: text/xml; charset=utf-8'], 0);
    $result = $this->curl->get($rr_service);
    $this->curl->set_option(CURLOPT_POST, 0, 0);
    $dom = new DomDocument();
    @ $dom->loadXml($result);
    if ($records = $dom->getElementsByTagName('records')->item(0)) {
      foreach ($solr_response['docs'] as $solr_doc) {
        $found_record = FALSE;
        $solr_id = self::scalar_or_first_elem($solr_doc[RR_MARC_001_A]);
        $solr_agency = self::scalar_or_first_elem($solr_doc[RR_MARC_001_B]);
        foreach ($records->getElementsByTagName('record') as $record) {
          $id = self::get_dom_element($record, 'bibliographicRecordId');
          $agency = self::get_dom_element($record, 'agencyId');
          if (($solr_id == $id) && ($solr_agency == $agency)) {
            $found_record = TRUE;
            if (!$data = base64_decode(self::get_dom_element($record, 'data'))) {
              VerboseJson::log(FATAL, 'Internal problem: Cannot decode record ' . $solr_id . ':' . $solr_agency . ' in rawrepo');
            }
            break;
          }
        }
        if (!$found_record) {
          VerboseJson::log(ERROR, 'Internal problem: Cannot find record ' . $solr_id . ':' . $solr_agency . ' in rawrepo');
        }
        if (empty($data)) {
          $data = sprintf($this->config->get_value('missing_marc_record', 'setup'), $solr_id, $solr_agency, 'Cannot read record');
        }
        @ $dom->loadXml($data);
        $marc_obj = $this->xmlconvert->xml2obj($dom, $this->xmlns['marcx']);
        $restricted_record = FALSE;
        if (!$s11_records_allowed && isset($marc_obj->record->_value->datafield)) {
          foreach ($marc_obj->record->_value->datafield as $idf => &$df) {
            if ($df->_attributes->tag->_value == 's11') {
              $restricted_record = TRUE;
              break 1;
            }
          }
        }
        if ($restricted_record) {
          VerboseJson::log(WARNING, 'Skipping restricted record ' . $solr_id . ':' . $solr_agency . ' in rawrepo');
          @ $dom->loadXml(sprintf($this->config->get_value('missing_marc_record', 'setup'), $solr_id, $solr_agency, 'Restricted record'));
          $marc_obj = $this->xmlconvert->xml2obj($dom, $this->xmlns['marcx']);
        }
        self::filter_marcxchange($solr_agency, $marc_obj, $this->repository['filter']);
        $rec_pos++;
        Object::set_value($ret[$rec_pos]->_value->collection->_value, 'resultPosition', $rec_pos);
        Object::set_value($ret[$rec_pos]->_value->collection->_value, 'numberOfObjects', 1);
        Object::set_value($ret[$rec_pos]->_value->collection->_value->object[0]->_value, 'collection', $marc_obj);
        Object::set_namespace($ret[$rec_pos]->_value->collection->_value->object[0]->_value, 'collection', $this->xmlns['marcx']);
      }
    }
    else {
      VerboseJson::log(ERROR, 'No record(s) found. http_code: ' . $this->curl->get_status('http_code') . ' post: ' . sprintf($p_mask, $post) . ' result: ' . $result);
    }
    $this->curl->close();
    return $ret;
  }


  /****************************************
   *********** agency functions ***********
   ****************************************/


  /** \brief Fetch agency types from OpenAgency, cache the result, and return agency type for $agency
   *
   * @param string $agency -
   * @return mixed - agency type (string) or FALSE
   */
  private function get_agency_type($agency) {
    $this->watch->start('agency_type');
    $agency_type = $this->open_agency->get_agency_type($agency);
    $this->watch->stop('agency_type');
    if ($this->open_agency->get_branch_type($agency) <> 'D') {
      return $agency_type;
    }

    return FALSE;
  }

  /** \brief Fetch priority list for agency
   *
   * @return mixed - array of agencies
   */
  private function fetch_agency_show_priority() {
    $this->watch->start('agency_prio');
    $agency_list = $this->open_agency->get_show_priority($this->agency);
    $this->watch->stop('agency_prio');
    return ($agency_list ? $agency_list : []);
  }

  /** \brief Fetch a profile $profile_name for agency $agency
   *
   * @param string $agency -
   * @param array $profiles
   * @return mixed - profile (array) or FALSE
   */
  private function fetch_profile_from_agency($agency, $profiles) {
    static $profile_map;
    if (!isset($profile_map)) {
      $profile_map = $this->config->get_value('profile_map', 'setup');
    }
    $this->watch->start('agency_profile');
    $ret = array();
    foreach ($profiles as $profile) {
      $pr_agency = self::value_or_default($profile_map[$profile->_value]['agency'], $agency);
      $pr_profile = self::value_or_default($profile_map[$profile->_value]['use_profile_name'], $profile->_value);
      $collections = $this->open_agency->get_search_profile($pr_agency, $pr_profile);
      if (!$collections) {
        $ret = FALSE;
        break;
      }
      else {
        if (empty($ret)) {
          $ret = $collections ;
        }
        else {
          self::merge_profiles($ret, $collections, $agency);
        }
      }
    }
    $this->watch->stop('agency_profile');
    return $ret;
  }

  /** Merge two search profiles, extending the first ($sum) with the additions found in the second ($add)
   *
   * @param $sum
   * @param $add
   * @param $agency
   */
  private function merge_profiles(&$sum, $add, $agency) {
    if (count($sum) <> count($add)) {
      VerboseJson::log(FATAL, 'Search profiles for ' . $agency . 'has different length?');
    }
    foreach ($sum as $idx => $sum_collection) {
      if (self::xs_boolean($add[$idx]['sourceSearchable'])) {
        $sum[$idx]['sourceSearchable'] = '1';
      }
      if ($add[$idx]['relation']) {
        foreach ($add[$idx]['relation'] as $add_rel) {
          $add_rel_to_sum = TRUE;
          if ($sum[$idx]['relation']) {
            foreach ($sum[$idx]['relation'] as $sum_rel) {
              if ($add_rel == $sum_rel) {
                $add_rel_to_sum = FALSE;
                break;
              }
            }
            if ($add_rel_to_sum) {
              $sum[$idx]['relation'][] = $add_rel;
            }
          }
        }
      }
    }
  }

  /** \brief Fetch agency rules from OpenAgency and return specific agency rule
   *
   * @param string $agency -
   * @param string $name -
   * @return boolean - agency rules
   */
  private function agency_rule($agency, $name) {
    static $agency_rules = [];
    if (empty($agency_rules[$agency])) {
      $this->watch->start('agency_rule');
      $agency_rules[$agency] = $this->open_agency->get_library_rules($agency);
      $this->watch->stop('agency_rule');
    }
    return self::xs_boolean($agency_rules[$agency][$name]);
  }


  /**************************************
   *********** misc functions ***********
   **************************************/

  /**
   * @param $record_repo_dom
   * @param $raw_res
   * @param $pid
   * @param $rel_res
   * @param $relation_unit
   * @param $rel_unit_pids
   * @param $primary_pid
   * @param $holdings_res
   * @param $param
   * @return object
   */
  private function build_record_object($record_repo_dom, $raw_res, $pid, $rel_res, $relation_unit, $rel_unit_pids, $primary_pid, $holdings_res, $param) {
    $obj = self::extract_record($record_repo_dom, $pid);

    Object::set_value($obj, 'identifier', $pid);
    Object::set_value($obj, 'primaryObjectIdentifier', $primary_pid);
    if ($rs = self::get_record_status($record_repo_dom)) {
      Object::set_value($obj, 'recordStatus', $rs);
    }
    if ($cd = self::get_creation_date($record_repo_dom)) {
      Object::set_value($obj, 'creationDate', $cd);
    }
    $drop_holding = empty($obj->creationDate->_value) && (strpos($pid, 'tsart:') || strpos($pid, 'avis:'));
    if (is_array($holdings_res) && (!$drop_holding)) {
      Object::set_value($obj, 'holdingsCount', $holdings_res['have']);
      Object::set_value($obj, 'lendingLibraries', $holdings_res['lend']);
    }
    if (in_array($param->relationData->_value, ['type', 'uri', 'full'])) {
      self::add_external_relations($relations, $raw_res, $param->relationData->_value, $pid);
      self::add_internal_relations($relations, $relation_unit, $rel_res, $param->relationData->_value, $rel_unit_pids);
    }
    if (isset($relations)) {
      Object::set_value($obj, 'relations', $relations);
    }
    if (DEBUG_ON) {
      echo PHP_EOL . 'relations(' . $pid . '):' . PHP_EOL; var_dump($relations);
    }
    if ($fa = self::scan_for_formats($record_repo_dom)) {
      Object::set_value($obj, 'formatsAvailable', $fa);
    }
    return $obj;
  }

  /**
   * search units in full profile and collect pids for each unit and create urls to read records with corepo_get
   * @param $relation_units
   * @return array
   */
  private function fetch_valid_relation_records($relation_units) {
    if ($relation_units) {
      $rel_query_ids = [];
      foreach ($relation_units as $unit_rels) {
        foreach ($unit_rels as $u_id => $rel) {
          $rel_query_ids[$u_id] = $u_id;
        }
      }
      $edismax['q'] = ['unit.id:("' . implode('" OR "', $rel_query_ids) . '")'];
      $filter_all_q = rawurlencode(self::set_solr_filter($this->search_profile, TRUE));
      $this->watch->start('Solr_rel');
      if ($err = self::get_solr_array($edismax, 0, 99999, '', '', '', $filter_all_q, '', $debug_query, $solr_arr)) {
        VerboseJson::log(FATAL, 'Solr error searching relations: ' . $err . ' - query: ' . $edismax['q']);
      }
      // FVS - type skal ikke læse unit'en,
      //       uri skal finde den højst prioriterede (hvis der er mere end en),
      //       full skal læse den højst prioriterede post og hente linkobjectet også.
      $this->watch->stop('Solr_rel');
      // build list of available ids for the unit's
      $rel_unit_pids = [];
      if (is_array($solr_arr['response']['docs'])) {
        foreach ($solr_arr['response']['docs'] as $fdoc) {
          $unit_id = $fdoc[FIELD_UNIT_ID];
          foreach ($fdoc[FIELD_REC_ID] as $rec_id) {
            if (self::is_corepo_pid($rec_id)) {
              $rel_unit_pids[$unit_id][$rec_id] = $rec_id;;
            }
          }
        }
      }
      // and build url to fetch.
      // FVS - behøver kun poster for full, for uri den højst prioriterede. corepo eller egen prio-løkke?
      $rel_urls = [];
      foreach ($rel_unit_pids as $u_id => $pids) {
        $rel_urls[$u_id] = self::corepo_get_url($u_id, $pids);
      }
      // FVS rel_res indeholder manifestationerne for de af relationerne pegede på unit's
      if ($rel_urls) {
        $rel_res = self::read_record_repo_all_urls($rel_urls);
      }
    }
    return array($rel_res, $rel_unit_pids);
  }

  /** get holdings for the records if needed
   *
   * @param $work_ids
   * @param $include_holdings
   * @param $use_sort_complex_key
   * @param $unit_sort_keys
   * @return array
   */
  private function collect_holdings($work_ids, $include_holdings, $use_sort_complex_key = FALSE, $unit_sort_keys = array()) {
    $hold_ws_url = $this->config->get_value('holdings_db', 'setup');
    $holdings_urls = [];
    foreach ($work_ids as &$work) {
      foreach ($work as $unit_id => $pids) {
        if ((isset($include_holdings) && self::xs_boolean($include_holdings->_value)) ||
          ($use_sort_complex_key && (strpos($unit_sort_keys[$unit_id], HOLDINGS) !== FALSE))) {
          $holdings_urls[$unit_id] = sprintf($hold_ws_url, reset($pids));
        }
      }
    }
    $ret_holdings = [];
    if ($holdings_urls) {
      $res_holdings = self::read_record_repo_all_urls($holdings_urls);
      // var_dump($work_ids); var_dump($holdings_urls); var_dump($ret_holdings); die();
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = FALSE;
      foreach ($res_holdings as $u_id => &$holds) {
        if (@ $dom->loadXML($holds)) {
          $ret_holdings[$u_id] = ['have' => self::get_dom_element($dom, 'librariesHave'),
                                 'lend' => self::get_dom_element($dom, 'librariesLend')];
        }
        else {
          VerboseJson::log(ERROR, 'Cannot load xml for unit ' . $u_id . ' from ' . $holdings_urls[$u_id]);
          $ret_holdings[$u_id] = ['have' => 0, 'lend' => 0];
        }
      }
    }
    return $ret_holdings;
  }

  /** \brief Return first element of array or the element for scalar vars
   *
   * @param mixed $mixed
   * @return mixed
   */
  private function scalar_or_first_elem($mixed) {
    if (is_array($mixed) || is_object($mixed)) {
      return reset($mixed);
    }
    return $mixed;
  }

  /** \brief Check an external relation against the search_profile
   *
   * @param string $collection -
   * @param string $relation -
   * @param array $profile -
   * @return boolean
   */
  private function check_valid_external_relation($collection, $relation, $profile) {
    self::set_valid_relations_and_sources($profile);
    $valid = isset($this->valid_relation[$collection][$relation]);
    if (DEBUG_ON) {
      echo __FUNCTION__ . ":: from: $collection relation: $relation - " . ($valid ? '' : 'no ') . "go\n";
    }
    return $valid;
  }

  /** \brief sets valid relations from the search profile
   *
   * @param array $profile -
   * @return void
   */
// TODO - valid_relation should also reflect collection_contained_in 
//        and then check in the collection is found in admin data in record_repo object
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

      $this->searchable_forskningsbibliotek = $this->searchable_source['870970-forsk'] ||
        $this->searchable_source['800000-danbib'] ||
        $this->searchable_source['800000-bibdk'];
      if (DEBUG_ON) {
        print_r($profile);
        echo "rels:\n";
        print_r($this->valid_relation);
        echo "source:\n";
        print_r($this->searchable_source);
      }
    }
  }

  /** \brief Get info for OpenAgency, solr_file cache style/setup
   *
   * @param $offset
   * @return array - cache information from config
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

  /**
   * @param $pid
   */
  private function is_corepo_pid($pid) {
    return ((count(explode('-', $pid)) == 2) && (count(explode(':', $pid)) == 2));
  }

  /** \brief Extract source part of an ID
   *
   * @param string $id - NNNNNN-xxxxxxx:nnnnnnn
   * @return string - the record source (NNNNNN-xxxxxxx)
   */
  private function record_source_from_pid($id) {
    list($ret, $dummy) = explode(':', $id, 2);
    return $ret;
  }

  /** \brief Check rec for available formats
   *
   * @param DOMDocument $dom -
   * @return object - available formats found
   */
  private function scan_for_formats(&$dom) {
    static $form_table;
    if (!isset($form_table)) {
      $form_table = $this->config->get_value('scan_format_table', 'setup');
    }

    if (($p = $dom->getElementsByTagName('container')->item(0)) || ($p = $dom->getElementsByTagName('localData')->item(0))) {
      foreach ($p->childNodes as $tag) {
        if ($x = &$form_table[$tag->tagName])
          Object::set_array_value($ret, 'format', $x);
      }
    }

    return $ret;
  }

  /** \brief Handle external relations located in commonData/localData streams
   * @param object $relations - return parameter, the relations found
   * @param $record
   * @param string $rels_type - type, uri or full
   * @param $pid
   */
  private function add_external_relations(&$relations, $record, $rels_type, $pid) {
    static $dom;
    static $ret_rel = array();
    if (empty($dom)) {
      $dom = new DOMDocument();
    }
    if (empty($ret_rel[$pid])) {
      if (@ !$dom->loadXML($record)) {
        VerboseJson::log(ERROR, 'Cannot load STREAMS for ' . $pid . ' into DomXml');
      }
      else {
       $ret_rel[$pid] = self::extract_external_relation_from_dom($dom, $rels_type);
      }
    }
    if ($ret_rel[$pid]) {
      $relations = $ret_rel[$pid];
    }
  }

  /**
   * @param $dom
   * @param $rels_type
   * @return mixed
   */
  private function extract_external_relation_from_dom($dom, $rels_type) {
    foreach ($dom->getElementsByTagName('link') as $link) {
      $url = self::get_dom_element($link, 'url');
      $access_type = self::get_dom_element($link, 'accessType');
      // test record: 870970-basis:52087708 with 2 hasOnlineAccess with different accessType
      if (empty($dup_check[$url . $access_type])) {
        $this_relation = self::get_dom_element($link, 'relationType');
        unset($lci);
        $relation_ok = FALSE;
        foreach ($link->getelementsByTagName('collectionIdentifier') as $collection) {
          $relation_ok = $relation_ok ||
            self::check_valid_external_relation($collection->nodeValue, $this_relation, $this->search_profile);
          Object::set($lci[], '_value', $collection->nodeValue);
        }
        if ($relation_ok) {
          if (empty($this_relation)) {   // ????? WHY - is relationType sometimes empty?
            Object::set_value($relation, 'relationType', self::get_dom_element($link, 'access'));
          }
          else {
            Object::set_value($relation, 'relationType', $this_relation);
          }
          if ($rels_type == 'uri' || $rels_type == 'full') {
            Object::set_value($relation, 'relationUri', $url);
            if ($access_type) {
              Object::set_value($relation->linkObject->_value, 'accessType', $access_type);
            }
            if ($nv = self::get_dom_element($link, 'access')) {
              Object::set_value($relation->linkObject->_value, 'access', $nv);
            }
            Object::set_value($relation->linkObject->_value, 'linkTo', self::get_dom_element($link, 'linkTo'));
            if ($lci) {
              $relation->linkObject->_value->linkCollectionIdentifier = $lci;
            }
          }
          $dup_check[$url . $access_type] = TRUE;
          Object::set_array_value($external_relation, 'relation', $relation);
          unset($relation);
        }
      }
    }
    return $external_relation;
  }

  /** Parse a RELS-EXT stream and return the relations found
   *  NB: Not all unit's have an RELS-EXT stream, so xml load errors are just ignored
   *
   * @param $unit_id - id of unit
   * @param $record_repo_addi_xml - the addi (RELS-EXT) xml record, containing relations for the unit
   * @param $valid_relations - array of relations accepted in profile
   * @return array - of relation unit id's
   */
  private function parse_addi_for_units_in_relations($unit_id, $record_repo_addi_xml, $valid_relations) {
    static $rels_dom;
    if (empty($rels_dom)) {
      $rels_dom = new DomDocument();
    }
    $relations = [];
    if (@ $rels_dom->loadXML($record_repo_addi_xml)) {  // ignore errors
      if (!$rels_dom->getElementsByTagName('Description')->item(0)) {
        VerboseJson::log(ERROR, 'Cannot load ' . $unit_id . ' object from RELS-EXT into DomXml');
      }
      else {
        foreach ($rels_dom->getElementsByTagName('Description')->item(0)->childNodes as $tag) {
          if ($tag->nodeType == XML_ELEMENT_NODE) {
            if ($rel_prefix = array_search($tag->getAttribute('xmlns'), $this->xmlns))
              $this_relation = $rel_prefix . ':' . $tag->localName;
            else
              $this_relation = $tag->localName;
            if ($valid_relations[$this_relation] && ($relation_count[$this_relation] < MAX_IDENTICAL_RELATIONS)) {
              $relation_count[$this_relation]++;
              $relations[$tag->nodeValue] = $this_relation;
            }
          }
        }
      }
    }
    return $relations;
  }

  /** \brief Handle relations comming from addi streams
  *
   * @param object $relations - the structure to contain the relations found
   * @param array $relation_units - the relations for the given unit
   * @param array $relation_recs - the records identified by the relation
   * @param string $rels_type - level for returning relations (type, uri, full)
   * @param array $unit_pids - pids for the relation units
   */
  private function add_internal_relations(&$relations, $relation_unit, $relation_recs, $rels_type, $unit_pids) {
    if (empty($relation_unit)) return;
    static $ret_rel = array();
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    foreach ($relation_unit as $rel_unit => $rel_name) {
      if (empty($unit_pids[$rel_unit])) {
        continue;
      }
      if (empty($ret_rel[$rel_unit])) {
        if (!$rec = json_decode($relation_recs[$rel_unit])) {
          VerboseJson::log(ERROR, 'Cannot decode json for best record from ' . $rel_unit);
        }
        else {
          Object::set_value($relation, 'relationType', $rel_name);
          $relation_pid = reset($rec->pids);
          if ($rels_type == 'uri' || $rels_type == 'full') {
            Object::set_value($relation, 'relationUri', $relation_pid);
          }
          if ($rels_type == 'full') {
            if (@ !$dom->loadXml($rec->dataStream)) {
              VerboseJson::log(ERROR, 'Cannot load ' . $relation_pid . ' into DomXml');
            }
            else {
              $rel_obj = &$relation->relationObject->_value->object->_value;
              $rel_obj = self::extract_record($dom, $rel_unit);
              Object::set_value($rel_obj, 'identifier', $relation_pid);
              if ($cd = self::get_creation_date($dom)) {
                Object::set_value($rel_obj, 'creationDate', $cd);
              }
              $ext_relations = self::extract_external_relation_from_dom($dom, $rels_type);
              if ($ext_relations) {
                Object::set_value($rel_obj, 'relations', $ext_relations);
                unset($ext_relations);
              }

              if ($fa = self::scan_for_formats($dom)) {
                Object::set_value($rel_obj, 'formatsAvailable', $fa);
              }
            }
          }
          $ret_rel[$rel_unit] = $relation;
          unset($relation);
        }
      }
      if ($ret_rel[$rel_unit]) {
        Object::set_array_value($relations, 'relation', $ret_rel[$rel_unit]);
      }
    }
  }

  /** \brief extract record status from record_repo obj
   *
   * @param DOMDocument $dom -
   * @return string - record status
   */
  private function get_record_status(&$dom) {
    return self::get_element_from_admin_data($dom, 'recordStatus');
  }

  /** \brief extract creation date from record_repo obj
   *
   * @param DOMDocument $dom -
   * @return string - creation date
   */
  private function get_creation_date(&$dom) {
    return self::get_element_from_admin_data($dom, 'creationDate');
  }

  /** \brief gets a given element from the adminData part
   *
   * @param DOMDocument $dom
   * @param string $tag_name
   * @return string
   */
  private function get_element_from_admin_data(&$dom, $tag_name) {
    if ($dom && $ads = $dom->getElementsByTagName('adminData')->item(0)) {
      return self::get_dom_element($ads, $tag_name);
    }
    return NULL;
  }

  /** \brief gets a given element from a dom node
   *
   * @param DOMDocument $dom
   * @param string $element
   * @param integer $item - default 0
   * @return string
   */
  private function get_dom_element($dom, $element, $item = 0) {
    if ($node = $dom->getElementsByTagName($element)->item($item)) {
      return $node->nodeValue;
    }
    return null;
  }

  /** \brief Extract record and namespace for it
   *         which parts is set by the user (or defaults)
   *
   * @param DOMDocument $dom - the container for the bibliographic record(s)
   * @param string $rec_id - only used for log-line(s)
   * @return object - the bibliographic object(s)
   */
  private function extract_record(&$dom, $rec_id) {
    $record_source = self::record_source_from_pid($rec_id);
    if (isset($this->collection_alias[$record_source])) {
      $record_source = $this->collection_alias[$record_source];
    }
    foreach ($this->format as $format_name => $format_arr) {
      switch ($format_name) {
        case 'dkabm':
          $rec = &$ret->record->_value;
          $record = $dom->getElementsByTagName('record');
          if ($record->item(0)) {
            $ret->record->_namespace = $record->item(0)->lookupNamespaceURI('dkabm');
          }
          if ($record->item(0)) {
            foreach ($record->item(0)->childNodes as $tag) {
              if (trim($tag->nodeValue)) {
                $o = new stdClass();
                if ($tag->hasAttributes()) {
                  foreach ($tag->attributes as $attr) {
                    Object::set_namespace($o->_attributes, $attr->localName, $record->item(0)->lookupNamespaceURI($attr->prefix));
                    Object::set_value($o->_attributes, $attr->localName, $attr->nodeValue);
                  }
                }
                $o->_namespace = $record->item(0)->lookupNamespaceURI($tag->prefix);
                $o->_value = trim($tag->nodeValue);
                if ($tag->localName && !($tag->localName == 'subject' && $tag->nodeValue == 'undefined')) {
                  $rec->{$tag->localName}[] = $o;
                }
                unset($o);
              }
            }
          }
          else {
            VerboseJson::log(FATAL, 'No dkabm record found in ' . $rec_id);
          }
          break;

        case 'marcxchange':
          $record = $dom->getElementsByTagName('collection');
          if ($record->item(0)) {
            Object::set_value($ret, 'collection', $this->xmlconvert->xml2obj($record->item(0), $this->xmlns['marcx']));
            Object::set_namespace($ret, 'collection', $this->xmlns['marcx']);
            if (is_array($this->repository['filter'])) {
              self::filter_marcxchange($record_source, $ret->collection->_value, $this->repository['filter']);
            }
          }
          break;

        case 'docbook':
          $record = $dom->getElementsByTagNameNS($this->xmlns['docbook'], 'article');
          if ($record->item(0)) {
            Object::set_value($ret, 'article', $this->xmlconvert->xml2obj($record->item(0)));
            Object::set_namespace($ret, 'article', $record->item(0)->lookupNamespaceURI('docbook'));
            if (is_array($this->repository['filter'])) {
              self::filter_docbook($record_source, $ret->article->_value, $this->repository['filter']);
            }
          }
          break;
        case 'opensearchobject':
          $record = $dom->getElementsByTagNameNS($this->xmlns['oso'], 'object');
          if ($record->item(0)) {
            Object::set_value($ret, 'object', $this->xmlconvert->xml2obj($record->item(0)));
            Object::set_namespace($ret, 'object', $record->item(0)->lookupNamespaceURI('oso'));
          }
          break;
      }
    }
    return $ret;
  }

  /** \brief Parse solr facets and build reply
   *
   * @param array $solr_arr - result from SOLR
   * array('facet_queries' => ..., 'facet_fields' => ..., 'facet_dates' => ...)
   *
   * @return object
   * facet(*)
   * - facetName
   * - facetTerm(*)
   *   - frequence
   *   - term
   */
  private function parse_for_facets(&$solr_arr) {
    if (is_array($solr_arr['facet_counts']['facet_fields'])) {
      foreach ($solr_arr['facet_counts']['facet_fields'] as $facet_name => $facet_field) {
        Object::set_value($facet, 'facetName', $facet_name);
        foreach ($facet_field as $term => $freq) {
          if (isset($term) && isset($freq)) {
            Object::set_value($o, 'frequence', $freq);
            Object::set_value($o, 'term', $term);
            Object::set_array_value($facet, 'facetTerm', $o);
            unset($o);
          }
        }
        Object::set_array_value($ret, 'facet', $facet);
        unset($facet);
      }
    }
    return $ret;
  }

  /** \brief Handle non-standardized characters - one day maybe, this code can be deleted
   *
   * @param string $s
   * @return string
   */
  private function normalize_chars($s) {
    $from[] = "\xEA\x9C\xB2";
    $to[] = 'Aa';
    $from[] = "\xEA\x9C\xB3";
    $to[] = 'aa';
    $from[] = "\XEF\x83\xBC";
    $to[] = "\xCC\x88";   // U+F0FC -> U+0308
    return str_replace($from, $to, $s);
  }

  /** \brief - ensure that a parameter is an array
   * @param mixed $par
   * @return array
   */
  private function as_array($par) {
    return is_array($par) ? $par : ($par ? [$par] : []);
  }

  /** \brief return specific value if set, otherwise the default
   *
   * @param array $struct - the structure to inspect
   * @param string $r_idx - the specific index
   * @param string $par - the parameter to return
   * @return string
   */
  private function get_val_or_default($struct, $r_idx, $par) {
    return $struct[$r_idx][$par] ? $struct[$r_idx][$par] : $struct[$par];
  }

  /** \brief -
   * @param mixed $value
   * @param mixed $default
   * @return mixed $value if TRUE, $default otherwise
   */
  private function value_or_default($value, $default) {
    return ($value ? $value : $default);
  }

  /** \brief - xs:boolean to php bolean
   * @param string $str
   * @return boolean - return true if xs:boolean is so
   */
  private function xs_boolean($str) {
    return (strtolower($str) == 'true' || $str == 1);
  }

  /**
   * @param $arr
   * @param string $glue
   * @return string
   */
  private function stringify_obj_array($arr, $glue = ',') {
    $vals = array();
    foreach ($arr as $val) {
      $vals[] = $val->_value;
    }
    return implode($glue, $vals);
  }

  /*
   ************************************ Info helper functions *******************************************
   */


  /** \brief Get information about search profile (info operation)
   *
   * @param string $agency
   * @param object $profile
   * @return object - the user profile
   */
  private function get_search_profile_info($agency, $profile) {
    if ($s_profile = self::fetch_profile_from_agency($agency, array($profile))) {
      foreach ($s_profile as $p) {
        Object::set_value($coll, 'searchCollectionName', $p['sourceName']);
        Object::set_value($coll, 'searchCollectionIdentifier', $p['sourceIdentifier']);
        Object::set_value($coll, 'searchCollectionIsSearched', self::xs_boolean($p['sourceSearchable']) ? 'true' : 'false');
        if ($p['relation'])
          foreach ($p['relation'] as $relation) {
            if ($r = $relation['rdfLabel']) {
              $all_relations[$r] = $r;
              Object::set($rels[], '_value', $r);
            }
            if ($r = $relation['rdfInverse']) {
              $all_relations[$r] = $r;
              Object::set($rels[], '_value', $r);
            }
          }
        if ($rels) {
          $coll->relationType = $rels;
        }
        if ($rels || self::xs_boolean($p['sourceSearchable'])) {
          Object::set_array_value($ret->_value, 'searchCollection', $coll);
        }
        unset($rels);
        unset($coll);
      }
      if (is_array($all_relations)) {
        ksort($all_relations);
        foreach ($all_relations as $rel) {
          Object::set_array_value($rels, 'relationType', $rel);
        }
        Object::set_value($ret->_value, 'relationTypes', $rels);
        unset($rels);
      }
    }
    return $ret;
  }

  /** \brief Get information about object formats from config (info operation)
   *
   * @return object
   */
  private function get_object_format_info() {
    foreach ($this->config->get_value('scan_format_table', 'setup') as $name => $value) {
      Object::set_array_value($ret->_value, 'objectFormat', $value);
    }
    foreach ($this->config->get_value('solr_format', 'setup') as $name => $value) {
      Object::set_array_value($ret->_value, 'objectFormat', $name);
    }
    foreach ($this->config->get_value('open_format', 'setup') as $name => $value) {
      Object::set_array_value($ret->_value, 'objectFormat', $name);
    }
    return $ret;
  }

  /** \brief Get information about repositories from config (info operation)
   *
   * @return object
   */
  private function get_repository_info() {
    $dom = new DomDocument();
    $repositories = $this->config->get_value('repository', 'setup');
    foreach ($repositories as $name => $value) {
      if ($name != 'defaults') {
        Object::set_value($r, 'repository', $name);
        self::set_repositories($name, FALSE);
        Object::set_value($r, 'cqlIndexDoc', $this->repository['cql_file']);
        if ($this->repository['cql_settings'] && @ $dom->loadXML($this->repository['cql_settings'])) {
          foreach ($dom->getElementsByTagName('indexInfo') as $index_info) {
            foreach ($index_info->getElementsByTagName('index') as $index) {
              foreach ($index->getElementsByTagName('map') as $map) {
                if ($map->getAttribute('hidden') !== '1') {
                  foreach ($map->getElementsByTagName('name') as $name) {
                    $idx = self::set_name_and_slop($name);
                  }
                  foreach ($map->getElementsByTagName('alias') as $alias) {
                    Object::set_array_value($idx, 'indexAlias', self::set_name_and_slop($alias));
                  }
                  Object::set_array_value($r, 'cqlIndex', $idx);
                  unset($idx);
                }
              }
            }
          }
        }
        Object::set_array_value($ret->_value, 'infoRepository', $r);
        unset($r);
      }
    }
    return $ret;
  }

  /** \brief Get info from dom node (info operation)
   *
   * @param domNode $node
   * @return object
   */
  private function set_name_and_slop($node) {
    $prefix = $node->getAttribute('set');
    Object::set_value($reg, 'indexName', $prefix . ($prefix ? '.' : '') . $node->nodeValue);
    if ($slop = $node->getAttribute('slop')) {
      Object::set_value($reg, 'indexSlop', $slop);
    }
    return $reg;
  }

  /** \brief Get information about namespaces from config (info operation)
   *
   * @return object
   */
  private function get_namespace_info() {
    foreach ($this->config->get_value('xmlns', 'setup') as $prefix => $namespace) {
      Object::set_value($ns, 'prefix', $prefix);
      Object::set_value($ns, 'uri', $namespace);
      Object::set_array_value($nss->_value, 'infoNameSpace', $ns);
      unset($ns);
    }
    return $nss;
  }

  /** \brief Get information about sorting and ranking from config (info operation)
   *
   * @return object
   */
  private function get_sort_info() {
    foreach ($this->config->get_value('rank', 'setup') as $name => $val) {
      if (isset($val['word_boost']) && ($help = self::collect_rank_boost($val['word_boost']))) {
        Object::set($boost, 'word', $help);
      }
      if (isset($val['phrase_boost']) && ($help = self::collect_rank_boost($val['phrase_boost']))) {
        Object::set($boost, 'phrase', $help);
      }
      if ($boost) {
        Object::set_value($rank, 'sort', $name);
        Object::set_value($rank, 'internalType', 'rank');
        Object::set_value($rank->rankDetails->_value, 'tie', $val['tie']);
        $rank->rankDetails->_value = $boost;
        Object::set_array_value($ret->_value, 'infoSort', $rank);
        unset($boost);
        unset($rank);
      }
    }
    foreach ($this->config->get_value('sort', 'setup') as $name => $val) {
      Object::set_value($sort, 'sort', $name);
      if (is_array($val)) {
        Object::set_value($sort, 'internalType', 'complexSort');
        foreach ($val as $simpleSort) {
          Object::set($simple[], '_value', $simpleSort);
        }
        Object::set($sortDetails, 'sort', $simple);
        unset($simple);
      }
      else {
        Object::set_value($sort, 'internalType', ($val == 'random' ? 'random' : 'basicSort'));
        Object::set_value($sortDetails, 'sort', $val);
      }
      Object::set_value($sort, 'sortDetails', $sortDetails);
      Object::set_array_value($ret->_value, 'infoSort', $sort);
      unset($sort);
      unset($sortDetails);
    }
    return $ret;
  }

  /** \brief return one rank entry (info operation)
   *
   * @param array $rank
   * @return object
   */
  private function collect_rank_boost($rank) {
    if (is_array($rank)) {
      foreach ($rank as $reg => $weight) {
        Object::set_value($rw, 'fieldName', $reg);
        Object::set_value($rw, 'weight', $weight);
        Object::set_array_value($iaw->_value, 'fieldNameAndWeight', $rw);
        unset($rw);
      }
    }
    return $iaw;
  }

  /**/
  /************************************ protected functions called from url *******************************************/
  /**/

  /** \brief fetch a cql-file from solr and display it
   *
   * @return void
   */
  protected function showCqlFile() {
    $repositories = $this->config->get_value('repository', 'setup');
    $repos = self::value_or_default($_GET['repository'], $this->config->get_value('default_repository', 'setup'));
    self::set_repositories($repos, FALSE);
    if ($file = $this->repository['cql_settings']) {
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
   * @return string - html doc
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
    $this->curl->close();
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
              $full_name = $name_item->getAttribute('set') . '.' . $name_item->nodeValue;
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

}

/*
 * MAIN
 */

if (!defined('PHPUNIT_RUNNING')) {
  $ws = new OpenSearch();

  $ws->handle_request();
}
