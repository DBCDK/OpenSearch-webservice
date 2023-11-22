<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
//error_reporting(E_ALL & ~E_NOTICE);
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
require_once('class_lib/webServiceServer_class.php');
require_once 'class_lib/memcache_class.php';
require_once 'class_lib/rediscache_class.php';
require_once 'class_lib/solr_query_class.php';
require_once 'class_lib/open_agency_v2_class.php';
require_once 'vendor/autoload.php';

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
  protected $filter_agency = '';
  protected $format = [];
  protected $which_rec_id = '';
  protected $separate_field_query_style = TRUE; // seach as field:(a OR b) ie FALSE or (field:a OR field:b) ie TRUE
  protected $valid_relation = [];
  protected $searchable_source = [];
  protected $searchable_forskningsbibliotek = FALSE;
  protected $collection_contained_in = [];
  protected $rank_frequence_debug;
  protected $feature_sw = [];
  protected $user_param;
  protected $debug_query = FALSE;
  protected $corepo_timers = [];
  protected $split_holdings_include;


   /**
   * openSearch constructor.
   */
  public function __construct() {
    webServiceServer::__construct('../ini_files/opensearch.ini');

    $this->watch->start("construct");
    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, self::value_or_default($this->config->get_value('curl_timeout', 'setup'), 20));
    $this->open_agency = self::initAgencyCore($this->config->get_value('vipcore', 'setup'));

    define('FIELD_UNIT_ID', 'rec.unitId');
    define('FIELD_REPOSITORY_ID', 'rec.repositoryId');
    define('FIELD_WORK_ID', 'rec.workId');
    define('FIELD_REC_ID', 'rec.id');
    define('FIELD_COLLECTION_INDEX', 'rec.collectionIdentifier');
    define('RR_MARC_001_A', 'marc.001a');
    define('RR_MARC_001_B', 'marc.001b');
    define('RR_MARC_001_AB', 'marc.001a001b');

    define('HOLDINGS', ' holdings ');
    define('DEBUG_ON', $this->debug);
    define('MAX_IDENTICAL_RELATIONS', self::value_or_default($this->config->get_value('max_identical_relation_names', 'setup'), 20));
    define('MAX_OBJECTS_IN_WORK', 100);
    define('AND_OP', ' AND ');
    define('OR_OP', ' OR ');
    $this->watch->stop("construct");
  }

  /** \brief Entry search: Handles the request and set up the response
   *
   * @param $param
   * @return mixed
   */
  public function search($param) {
    _Object::set_value($ret_error, 'searchResponse', new stdClass());
    _Object::set_value($ret_error->searchResponse->_value, 'error', new stdClass());

    // Add custom headers *always* - to allow SLA
    self::add_dbc_sla_header_action("search");

    $this->watch->start('aaa');
    try {
      if (!$this->aaa->has_right('opensearch', 'read')) {
        _Object::set_value($ret_error->searchResponse->_value, 'error', 'authentication_error');
        return $ret_error;
      }
    } finally {
      $this->watch->stop('aaa');
    }

    $this->watch->start('preamble');
    try {
      // check for unsupported stuff
      @ $ret_error->searchResponse->_value->error->_value = &$unsupported;  // ignore PHP warning
      if (empty($param->query->_value)) {
        $unsupported = 'Error: No query found in request';
      }

      // for testing and group all
      if (count($this->aaa->aaa_ip_groups) == 1 && isset($this->aaa->aaa_ip_groups['all'])) {
        _Object::set_value($param, 'agency', '100200');
        _Object::set_value($param, 'profile', 'test');
      }
      if (empty($param->agency->_value) && empty($param->profile)) {
        _Object::set_value($param, 'agency', $this->config->get_value('agency_fallback', 'setup'));
        _Object::set_value($param, 'profile', $this->config->get_value('profile_fallback', 'setup'));
      }
      if (isset($param->profile) && !is_array($param->profile)) {
        $param->profile = array($param->profile);
      }
      $this->watch->start('preamble_profile_filter');
      try {
        if (empty($param->agency->_value)) {
          $unsupported = 'Error: No agency in request';
        } elseif (isset($param->sort) && is_object($param->sort) && (strtolower($param->sort->_value) == 'random')) {
          $unsupported = 'Error: random sort is currently disabled';
        } elseif (empty($param->profile)) {
          $unsupported = 'Error: No profile in request';
        } elseif (!($this->search_profile = self::fetch_profile_from_agency($param->agency->_value, $param->profile))) {
          $unsupported = 'Error: Cannot fetch profile(s): ' . self::stringify_obj_array($param->profile) .
            ' for ' . $param->agency->_value;
        }
        $this->user_param = $param;
        $this->agency = $param->agency->_value;
        // We know now the agency, so set it in the header.
        self::add_dbc_sla_header_agency($this->agency);
        if ($repository_error = self::set_repositories($param->repository->_value ?? '')) {
          $unsupported = $repository_error;
        }

        if ($unsupported) return $ret_error;

        $this->show_agency = self::value_or_default($param->showAgency->_value ?? '', $this->agency);
        $this->profile = $param->profile;
        $this->filter_agency = self::set_solr_filter($this->search_profile);
        $this->split_holdings_include = self::split_collections_for_holdingsitem($this->search_profile);
        self::set_valid_relations_and_sources($this->search_profile);
        // self::set_search_filters_for_800000_collection($param->forceFilter->_value);

      } finally {
        $this->watch->stop('preamble_profile_filter');
      }
      $this->feature_sw = $this->config->get_value('feature_switch', 'setup');

      $this->format = self::set_format($param->objectFormat ?? '',
        $this->config->get_value('open_format', 'setup'),
        $this->config->get_value('open_format_force_namespace', 'setup'),
        $this->config->get_value('solr_format', 'setup'));

      $use_work_collection = ($param->collectionType->_value ?? '') <> 'manifestation';
      if (isset($this->repository['rawrepo'])) {
        $fetch_raw_records = (!$this->format['found_solr_format'] || $this->format['marcxchange']['user_selected']);
        if ($fetch_raw_records) {
          define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_manifestations', 'setup'), 200));
        } else {
          define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_rawrepo', 'setup'), 1000));
        }
      } elseif ($use_work_collection) {
        define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_collections', 'setup'), 50));
      } else {
        define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_manifestations', 'setup'), 200));
      }

      $sort = [];
      $sort_types = [];
      $rank_types = $this->config->get_value('rank', 'setup');
      if (!self::parse_for_ranking($param, $rank, $rank_types)) {
        if ($unsupported = self::parse_for_sorting($param, $sort, $sort_types)) {
          return $ret_error;
        }
      }
      $boost_q = self::boostUrl($param->userDefinedBoost ?? '');
      if (is_numeric($boost_q) && $boost_q < 0) {
        Object::set_value($ret_error->searchResponse->_value, 'error', 'Only positive float value allowed');
        return $ret_error;
      }
      if ($unsupported) return $ret_error;

      $ret_error->searchResponse->_value->error->_value = &$error;
      $start = $param->start->_value ?? 1;
      $step_value = min($param->stepValue->_value ?? 10, MAX_STEP_VALUE);
      if (empty($start) && $step_value) {
        $start = 1;
      }
      if ($param->queryLanguage->_value ?? '') {
        $this->query_language = $param->queryLanguage->_value;
      }
      $this->debug_query = $this->xs_boolean($param->queryDebug->_value ?? NULL);

    } finally {
      $this->watch->stop('preamble');
    }

    if (isset($this->repository['rawrepo'])) {
      $this->watch->start('rawrepo');
      try {
        $this->cql2solr = new SolrQuery($this->repository, $this->config, $this->query_language);
        $this->watch->start('cql');
        $solr_query = $this->cql2solr->parse($param->query->_value);
        $this->watch->stop('cql');
        if (isset($solr_query['error'])) {
          $error = self::cql2solr_error_to_string($solr_query['error']);
          return $ret_error;
        }
        VerboseJson::log(TRACE, array('message' => 'CQL to SOLR', 'query' => $param->query->_value, 'pretty' => print_r($solr_query, TRUE), 'parsed' => preg_replace('/\s+/', ' ', print_r($solr_query, TRUE))));
        $q = implode(AND_OP, $solr_query['edismax']['q']);
        if (!in_array($this->agency, self::value_or_default($this->config->get_value('all_rawrepo_agency', 'setup'), []))) {
          $filter = rawurlencode(RR_MARC_001_B . ':(870970 OR ' . $this->agency . ')');
        }
        $sort_q = '';
        if ($sort) {
          foreach ($sort as $s) {
            $ss[] = urlencode($sort_types[$s]);
          }
          $sort_q = '&sort=' . implode(',', $ss);
        }
        foreach ($solr_query['edismax']['fq'] as $fq) {
          $filter .= '&fq=' . rawurlencode($fq);
        }
        if (isset($solr_query['edismax']['add_params'])) {
          foreach ($solr_query['edismax']['add_params'] as $par => $val) {
            $filter .= '&' . $par . '=' . rawurlencode($val);
          }
        }
        $solr_urls[0]['url'] = $this->repository['solr'];
        $solr_urls[0]['q'] = 'q=' . urlencode($q) .
          (@$filter ? '&fq=' . $filter : '') .
          '&start=' . ($start - 1) .
          '&rows=' . $step_value . $sort_q .
          '&defType=edismax&wt=phps&fl=' . ($this->debug_query ? '&debugQuery=on' : '');
        $solr_urls[0]['debug'] = str_replace('wt=phps', 'wt=xml', $solr_urls[0]['q']);
        if ($err = self::do_solr($solr_urls, $solr_arr)) {
          $error = $err;
          return $ret_error;
        }
        $s11_agency = self::value_or_default($this->config->get_value('s11_agency', 'setup'), []);
        if ($fetch_raw_records) {
          $collections = self::get_records_from_rawrepo($this->repository['rawrepo'], $solr_arr['response'], in_array($this->agency, $s11_agency));
          if (is_scalar($collections)) {
            $error = $collections;
            return $ret_error;
          }
        }
        _Object::set_value($ret, 'searchResponse', new stdClass());
        _Object::set_value($ret->searchResponse->_value, 'result', new stdClass());
        $result = &$ret->searchResponse->_value->result->_value;
        if (!empty($this->format['found_solr_format'])) {
          self::collections_from_solr($collections, $solr_arr['response']);
        }
        _Object::set_value($result, 'hitCount', self::get_num_found($solr_arr));
        _Object::set_value($result, 'collectionCount', is_countable($collections) ? count($collections) : 0);
        _Object::set_value($result, 'more', (($start + $step_value) <= $result->hitCount->_value ? 'true' : 'false'));
        $result->searchResult = &$collections;
        $result->statInfo = new stdClass();
        _Object::set_value($result->statInfo->_value, 'time', $this->watch->splittime('Total'));
        _Object::set_value($result->statInfo->_value, 'trackingId', VerboseJson::$tracking_id);
        if ($this->debug_query) {
          _Object::set_value($result, 'queryDebugResult', self::set_debug_info($solr_arr['debug']));
        }
        self::log_stat_search();
        return $ret;
      } finally {
        $this->watch->stop('rawrepo');
      }
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

    $this->watch->start('precql');
    if (isset($sort[0])) {
      $use_work_collection |= $sort_types[$sort[0]] == 'random';
    }
    $key_work_struct = md5($param->query->_value .
        (is_scalar($this->repository_name) ? $this->repository_name : '') .
        $this->filter_agency .
        self::xs_boolean($param->allObjects->_value ?? NULL) .
        $use_work_collection .
        implode('', $sort) .
        $rank .
        $boost_q .
        $this->config->get_inifile_hash());

    $this->watch->start('precql_newsolrquery');
    $this->cql2solr = new SolrQuery($this->repository, $this->config, $this->query_language, $this->split_holdings_include);
    $this->watch->stop('precql_newsolrquery');
    $this->watch->stop('precql');
    $this->watch->start('cql');
    $solr_query = $this->cql2solr->parse($param->query->_value);
    $this->watch->stop('cql');
    $this->watch->start('postcql');
    if (DEBUG_ON) {
      var_dump($solr_query);
    }
    try {
      if (isset($solr_query['error'])) {
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

      if (isset($sort_def['include']) && is_array($solr_query['edismax']['ranking'])) {
        if (!$rank_cql = $rank_types['rank_cql'][reset($solr_query['edismax']['ranking'])]) {
          $rank_cql = $rank_types['rank_cql']['default'];
        }
        if ($rank_cql) {
          $rank = $rank_cql;
        }
      }
      $sort_q = '';
      $best_match_debug = new stdClass();
      if ($this->query_language == 'bestMatch') {
        $sort_q .= '&mm=1';
        $solr_query['edismax'] = $solr_query['best_match'];
        foreach ($solr_query['best_match']['sort'] as $key => $val) {
          $sort_q .= '&' . $key . '=' . urlencode($val);
          _Object::set_value($best_match_debug, $key, $val);
        }
      } elseif ($sort) {
        foreach ($sort as $s) {
          $ss[] = urlencode($sort_types[$s]);
        }
        $sort_q = '&sort=' . implode(',', $ss);
      }
      if ($rank == 'rank_frequency') {
        if ($new_rank = self::guess_rank($solr_query, $rank_types, $filter_q)) {
          $rank = $new_rank;
        } else {
          $rank = 'rank_none';
        }
      }
      $rank_q = '';
      if (!empty($rank_types[$rank])) {
        $rank_qf = $this->cql2solr->make_boost($rank_types[$rank]['word_boost']);
        $rank_pf = $this->cql2solr->make_boost($rank_types[$rank]['phrase_boost']);
        $rank_tie = $rank_types[$rank]['tie'];
        $rank_q = '&qf=' . urlencode($rank_qf) . '&pf=' . urlencode($rank_pf) . '&tie=' . $rank_tie;
      }

      $facet_q = empty($param->facets) ? '' : self::set_solr_facet_parameters($param->facets->_value);

      // TODO rows should max to like 5000 and use cursorMark to page forward. cursorMark need a sort parameter to work
      $rows = $step_value ? (($start + $step_value + 100) * 2) + 100 : 0;

      VerboseJson::log(TRACE, array('message' => 'CQL to SOLR', 'query' => $param->query->_value, 'pretty' => print_r($solr_query, TRUE),'parsed' => preg_replace('/\s+/', ' ', print_r($solr_query, TRUE))));

    } finally {
      $this->watch->stop('postcql');
    }
    // do the query
    $this->watch->start('Solr_ids');
    if (isset($sort[0]) && ($sort[0] == 'random')) {
      if ($err = self::get_solr_array($solr_query['edismax'], 0, 0, '', '', $facet_q, $filter_q, '', $solr_arr))
        $error = $err;
      else {
        $numFound = self::get_num_found($solr_arr);
      }
    }
    else {
      if ($err = self::get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, $facet_q, $filter_q, $boost_q, $solr_arr))
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
    $this->watch->start('post_Solr_ids');
    try {
      if ($error) return $ret_error;

      if ($this->debug_query) {
        $debug_result = self::set_debug_info($solr_arr['debug'], $this->rank_frequence_debug, $best_match_debug);
      }
      if (!empty($solr_arr['debug'])) {
        $solr_timing = $solr_arr['debug']['timing'];
      }

      $facets = '';
      if ($facet_q) {
        $facets = self::parse_for_facets($solr_arr);
      }
    } finally {
      $this->watch->stop('post_Solr_ids');
    }
    $this->watch->start('Build_id');
    try {
      $work_ids = $used_search_fids = [];
      if (isset($sort[0]) && ($sort[0] == 'random')) {
        $rows = min($step_value, $numFound);
        $more = $step_value < $numFound;
        for ($w_idx = 0; $w_idx < $rows; $w_idx++) {
          do {
            $no = rand(0, $numFound - 1);
          } while (isset($used_search_fid[$no]));
          $used_search_fid[$no] = TRUE;
          self::get_solr_array($solr_query['edismax'], $no, 1, '', '', '', $filter_q, '', $solr_arr);
          $uid = self::get_first_solr_element($solr_arr, FIELD_UNIT_ID);
          $work_ids[] = [$uid];
        }
      } else {
        if ($this->config->get_value('cache_type', 'setup') == "redis") {
          VerboseJson::log(DEBUG, 'Using redis for search');
          try {
            $this->cache = new rediscache($this->config->get_value('cache_host', 'setup'),
              $this->config->get_value('cache_port', 'setup'),
              $this->config->get_value('cache_expire', 'setup'),
              $this->config->get_value('cache_is_cluster', 'setup'),
              $this->config->get_value('cache_connect_timeout', 'setup'),
              $this->config->get_value('cache_read_timeout', 'setup'));
          } catch(Exception $e) {
            if($e->getMessage() == "UnableToConnectRedis") {
              VerboseJson::log(WARNING, 'UnableToConnectRedis in search');
              $this->cache = new cache('localhost', '11211', 360);
            }
          }
        } else {
          VerboseJson::log(DEBUG, 'Using memcache for search');
          $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
            $this->config->get_value('cache_port', 'setup'),
            $this->config->get_value('cache_expire', 'setup'));
        }
        $work_cache_struct = [];
        if (empty($_GET['skipCache']) && @$this->cache) {
          if ($work_cache_struct = @$this->cache->get($key_work_struct)) {
            VerboseJson::log(TRACE, 'Cache hit lines' . count($work_cache_struct));
          } else {
            $work_cache_struct = [];
            VerboseJson::log(TRACE, 'work_struct cache miss');
          }
        }

        if (DEBUG_ON) {
          echo 'solr_work_ids: ';
          print_r($solr_work_ids);
        }

        if (empty($step_value)) {
          $more = ($numFound >= $start);   // no need to find records, only hit count is returned and maybe facets
        } else {
          if ($err = self::build_work_struct_from_solr($work_cache_struct, $work_ids, $more, $solr_work_ids, $solr_query['edismax'], $start, $step_value, $rows, $sort_q, $rank_q, $filter_q, $boost_q, $use_work_collection, self::xs_boolean($param->allObjects->_value ?? NULL), $numFound)) {
            $error = $err;
            return $ret_error;
          }
        }
      }
      if ($this->cache && $this->cache->check()) {
        VerboseJson::log(TRACE, array('Cache set # work' => count($work_cache_struct)));
        $this->cache->set($key_work_struct, $work_cache_struct);
      }
    } finally {
      $this->watch->stop('Build_id');
    }

    $this->watch->start('post_Build_id');
    try {
      if (isset($solr_work_ids) && (count($work_ids) < $step_value) && (count($solr_work_ids) < $numFound)) {
        VerboseJson::log(WARNING, 'To few search_ids found in solr. Query' . implode(AND_OP, $solr_query['edismax']['q']));
      }

      if (DEBUG_ON) {
        echo PHP_EOL . 'work_ids:' . PHP_EOL;
        var_dump($work_ids);
      };

// fetch data to sort_keys and (if needed) solr display format(s)
      $units_in_result = [];
      foreach ($work_ids as $work) {
        foreach ($work as $unit_id => $unit_pids) {
          $units_in_result[$unit_id] = '"' . $unit_id . '"';
        }
      }
      $add_queries = [FIELD_UNIT_ID . ':(' . implode(OR_OP, $units_in_result) . ')'];
    } finally {
      $this->watch->stop('post_Build_id');
    }
    $this->watch->start('Solr_disp');
    $display_solr_arr = self::do_add_queries_and_fetch_solr_data_fields($add_queries, '*', self::xs_boolean($param->allObjects->_value ?? NULL), '');
    $this->watch->stop('Solr_disp');
    $this->watch->start('buildresponse');
    $unit_sort_keys = [];
    try {
      $found_primary = [];
      if (is_iterable($display_solr_arr)) {
        foreach ($display_solr_arr as $d_s_a) {
          if (is_iterable($d_s_a['response']['docs'])) {
            foreach ($d_s_a['response']['docs'] as $solr_rec) {
              $unit_id = self::scalar_or_first_elem($solr_rec[FIELD_UNIT_ID]);
              if (isset($solr_rec['sort.complexKey']) && empty($found_primary[$unit_id])) {
                $unit_sort_keys[$unit_id] = $solr_rec['sort.complexKey'] . '  ' . $unit_id;
                $source = self::record_source_from_pid($solr_rec[FIELD_REPOSITORY_ID]);
                $found_primary[$unit_id] = (self::scalar_or_first_elem($solr_rec['unit.isPrimaryObject']) == 'true') &&
                    in_array($source, $solr_rec[FIELD_COLLECTION_INDEX]);
              }
            }
          }
        }
      }
      if ($this->debug_query) {
        $explain_keys = array_keys($solr_arr['debug']['explain']);
        foreach ($solr_arr['response']['docs'] as $solr_idx => $solr_rec) {
          $unit_id = self::scalar_or_first_elem($solr_rec[FIELD_UNIT_ID]);
          if (isset($units_in_result[$unit_id])) {
            $explain[$unit_id] = $solr_arr['debug']['explain'][$explain_keys[$solr_idx]];
          }
        }
      }

      // work_ids now contains the work-records and the fedoraPids they consist of
      // now fetch the records for each work/collection
      $collections = [];
      $rec_no = max(1, $start);
      $use_sort_complex_key = in_array($this->agency, self::value_or_default($this->config->get_value('use_sort_complex_key', 'setup'), []));

      // fetch all addi and hierarchi records for all units in work_ids
      foreach ($work_ids as $idx => $work) {
        if (count($work) >= MAX_OBJECTS_IN_WORK) {
          VerboseJson::log(WARNING, 'record_repo work-record containing ' . json_encode($work) . ' contains ' . count($work) . ' units. Cut work to first ' . MAX_OBJECTS_IN_WORK . ' units');
          array_splice($work_ids[$idx], MAX_OBJECTS_IN_WORK);
        }
      }

      // find and read best record in unit's. Fetch addi records if relations is part of the request
      list($raw_res, $primary_pids, $unit_info, $relation_units) = self::read_records_and_extract_data($work_ids, $param, 'UNIT');

      // modify order of pids to reflect show priority, as returned from corepo
      foreach ($unit_info as $info_uid => $info) {
        if (is_array($info[0]) && count($info[0]) > 1) {
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
      $holdings_res = self::collect_holdings($work_ids, $param->includeHoldingsCount ?? '', 'UNIT', $use_sort_complex_key, $unit_sort_keys);

      $record_repo_dom = new DomDocument();
      $record_repo_dom->preserveWhiteSpace = FALSE;
      $missing_record = $this->config->get_value('missing_record', 'setup');
      foreach ($work_ids as $work) {
        $objects = [];
        foreach ($work as $unit_id => $pids) {
          $rec_id = reset($pids);
          $sort_holdings = ' ';
          if (isset($holdings_res[$unit_id]) && $use_sort_complex_key && (strpos($unit_sort_keys[$unit_id], HOLDINGS) !== FALSE)) {
            $sort_holdings = sprintf(' %04d ', 9999 - intval($holdings_res[$unit_id]['lend']));
          }
          if(isset($unit_sort_keys[$unit_id])) {
            $sort_key = str_replace(HOLDINGS, $sort_holdings, $unit_sort_keys[$unit_id]);
          } else {
            $sort_key = '';
          }
          $objects[$sort_key] = new stdClass();
          unset($rec_error);
          if (!empty($raw_res[$unit_id]) && @ !$record_repo_dom->loadXML($raw_res[$unit_id])) {
            VerboseJson::log(FATAL, 'Cannot load recid ' . $rec_id . ' into DomXml');
            if ($missing_record) {
              $record_repo_dom->loadXML(sprintf($missing_record, $rec_id));
            } else {
              $rec_error = new stdClass();
              _Object::set_value($rec_error->object->_value, 'error', 'unknown/missing/inaccessible record: ' . reset($pids));
              _Object::set_value($rec_error->object->_value, 'identifier', reset($pids));
            }
          }
          if (!empty($rec_error)) {
            $objects[$sort_key]->_value = $rec_error;
          } else {
            $objects[$sort_key]->_value = self::build_record_object($record_repo_dom,
              $raw_res[$unit_id],
              reset($pids),
              $rel_res,
              !empty($relation_units[$unit_id]) ? $relation_units[$unit_id] : NULL,
              $rel_unit_pids,
              !empty($primary_pids[$unit_id]) ? $primary_pids[$unit_id] : NULL,
              !empty($holdings_res[$unit_id]) ? $holdings_res[$unit_id] : NULL,
              $param);
          }
          if (empty($param->includeHoldingsCount) || !self::xs_boolean($param->includeHoldingsCount->_value)) {
            unset($objects[$sort_key]->_value->holdingsCount);
            unset($objects[$sort_key]->_value->lendingLibraries);
          }
          foreach ($pids as $um) {
            _Object::set_array_value($u_member, 'identifier', $um);
          }
          _Object::set_value($objects[$sort_key]->_value, 'objectsAvailable', $u_member);
          unset($u_member);
          if (isset($explain[$unit_id])) {
            _Object::set_value($objects[$sort_key]->_value, 'queryResultExplanation', $explain[$unit_id]);
          }
        }
        $o = new stdClass();
        $o->collection = new stdClass();
        $o->collection->_value = new stdClass();
        @ _Object::set_value($o->collection->_value, 'resultPosition', $rec_no++);  // ignore PHP warning
        _Object::set_value($o->collection->_value, 'numberOfObjects', count($objects));
        if (count($objects) > 1) {
          ksort($objects);
        }
        $o->collection->_value->object = $objects;
        _Object::set($collections[], '_value', $o);
        unset($o);
      }
      if (DEBUG_ON) {
        echo PHP_EOL . 'unit_sort_keys:' . PHP_EOL;
        var_dump($unit_sort_keys);
        echo PHP_EOL . 'holdings_res:' . PHP_EOL;
        var_dump($holdings_res);
        echo PHP_EOL . 'raw_res:' . PHP_EOL;
        var_dump($raw_res);
        echo PHP_EOL . 'relation_units (relations found in records):' . PHP_EOL;
        var_dump($relation_units);
        echo PHP_EOL . 'rel_unit_pids (relations in search profile):' . PHP_EOL;
        var_dump($rel_unit_pids);
        echo PHP_EOL . 'rel_res (relation records):' . PHP_EOL;
        var_dump($rel_res);
        echo PHP_EOL . 'work_ids:' . PHP_EOL;
        var_dump($work_ids);
        echo PHP_EOL . 'unit_info:' . PHP_EOL;
        var_dump($unit_info);
        echo PHP_EOL . 'solr_arr:' . PHP_EOL;
        var_dump($solr_arr);
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

      _Object::set_value($ret, 'searchResponse', new stdClass());
      _Object::set_value($ret->searchResponse->_value, 'result', new stdClass());

      @ $result = &$ret->searchResponse->_value->result->_value;  // ignore PHP warning
      _Object::set_value($result, 'hitCount', $numFound);
      _Object::set_value($result, 'collectionCount', count($collections));
      _Object::set_value($result, 'more', ($more ? 'true' : 'false'));
      self::set_sortUsed($result, $rank, $sort, $sort_types);
      $result->searchResult = $collections;
      _Object::set_value($result, 'facetResult', $facets);
      if ($this->debug_query && $debug_result) {
        _Object::set_value($result, 'queryDebugResult', $debug_result);
      }
      if (isset($solr_timing)) {
        VerboseJson::log(STAT, array('solrTiming ' => json_encode($solr_timing)));
      }
      $result->statInfo = new stdClass();
      $result->statInfo->_value = new stdClass();
      @ _Object::set_value($result->statInfo->_value, 'fedoraRecordsCached', $this->number_of_record_repo_cached);  // ignore PHP warning
      _Object::set_value($result->statInfo->_value, 'fedoraRecordsRead', $this->number_of_record_repo_calls);
      _Object::set_value($result->statInfo->_value, 'time', $this->watch->splittime('Total'));
      _Object::set_value($result->statInfo->_value, 'trackingId', VerboseJson::$tracking_id);

      self::log_stat_search();

      //var_dump($ret); die();

      return $ret;
    } finally {
      $this->watch->stop('buildresponse');
    }
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
    _Object::set_value($ret_error, 'searchResponse', new stdClass());
    _Object::set_value($ret_error->searchResponse->_value, 'error', new stdClass());

     // Add custom headers *always* - to allow SLA
    self::add_dbc_sla_header_action("getObject");

    @ $ret_error->searchResponse->_value->error->_value = &$error;  // ignore PHP warning
    $this->watch->start('aaa');
    try {
      if (!$this->aaa->has_right('opensearch', 'read')) {
        $error = 'authentication_error';
        return $ret_error;
      }
    } finally {
      $this->watch->stop('aaa');
    }

    $this->watch->start('preamble');
    try {
      if (empty($param->agency->_value) && empty($param->profile)) {
        _Object::set_value($param, 'agency', $this->config->get_value('agency_fallback', 'setup'));
        _Object::set_value($param, 'profile', $this->config->get_value('profile_fallback', 'setup'));
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
      $this->user_param = $param;
      $this->watch->start('preamble_profile_filter');
      try {

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
          // self::set_search_filters_for_800000_collection();
        }
        // We know now the agency, so set it in the header.
        self::add_dbc_sla_header_agency($this->agency);
      } finally {
        $this->watch->stop('preamble_profile_filter');
      }
      if ($error = self::set_repositories($param->repository->_value ?? '')) {
        VerboseJson::log(FATAL, $error);
        return $ret_error;
      }
      $this->show_agency = self::value_or_default($param->showAgency->_value ?? '', $this->agency);
      if ($this->filter_agency) {
        $filter_q = rawurlencode($this->filter_agency);
      }

      $this->feature_sw = $this->config->get_value('feature_switch', 'setup');

      $this->format = new stdClass();
      $this->format = self::set_format($param->objectFormat ?? null,
        $this->config->get_value('open_format', 'setup'),
        $this->config->get_value('open_format_force_namespace', 'setup'),
        $this->config->get_value('solr_format', 'setup'));
      if ($this->config->get_value('cache_type', 'setup') == "redis") {
        VerboseJson::log(DEBUG, 'Using redis for getobject');
        try {
          $this->cache = new rediscache($this->config->get_value('cache_host', 'setup'),
            $this->config->get_value('cache_port', 'setup'),
            $this->config->get_value('cache_expire', 'setup'),
            $this->config->get_value('cache_is_cluster', 'setup'),
            $this->config->get_value('cache_connect_timeout', 'setup'),
            $this->config->get_value('cache_read_timeout', 'setup'));
        } catch(Exception $e) {
          if($e->getMessage() == "UnableToConnectRedis") {
            VerboseJson::log(WARNING, 'UnableToConnectRedis in getObject');
            $this->cache = new cache('localhost', '11211', 360);
          }
        }
      } else {
        VerboseJson::log(DEBUG, 'Using memcache for getObject');
        $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
          $this->config->get_value('cache_port', 'setup'),
          $this->config->get_value('cache_expire', 'setup'));
      }

      $fpids = self::as_array($param->identifier ?? '');
      $lpids = self::as_array($param->localIdentifier ?? '');
      $alpids = self::as_array($param->agencyAndLocalIdentifier ?? '');

      if (isset($this->repository['rawrepo'])) {
        $fetch_raw_records = (!$this->format['found_solr_format'] || $this->format['marcxchange']['user_selected']);
        if ($fetch_raw_records) {
          define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_manifestations', 'setup'), 200));
        } else {
          define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_rawrepo', 'setup'), 1000));
        }
      } else {
        define('MAX_STEP_VALUE', self::value_or_default($this->config->get_value('max_manifestations', 'setup'), 200));
      }
      if (MAX_STEP_VALUE <= count($fpids) + count($lpids) + count($alpids)) {
        $error = 'getObject can fetch up to ' . MAX_STEP_VALUE . ' records. ';
        return $ret_error;
      }


      $add_fl = '';
      if (!empty($this->format['found_solr_format'])) {
        foreach ($this->format as $f) {
          if (isset($f['is_solr_format']) && isset($f['format_name'])) {
            $add_fl .= ',' . $f['format_name'];
          }
        }
      }
      foreach ($fpids as $key => $pid) {    // handling identifiers used as localIdentifiers
        if (!self::is_corepo_pid($pid->_value)) {
          $lpids[] = $pid;
          unset($fpids[$key]);
        }
      }
      foreach ($lpids as $lid) {
        $fpid = new stdClass();
        $fpid->_value = $this->agency . '-katalog:' . str_replace(' ', '', $lid->_value);
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
      $fpids = self::sanitize_pids(self::handle_sequencing($fpids));
    } finally {
      $this->watch->stop('preamble');
    }

    $id_array = [];
    if (isset($this->repository['rawrepo'])) {
      $this->watch->start('rawrepo');
      try {
        foreach ($fpids as $fpid) {
          $id_array[] = $fpid->_value;
          list($owner_collection, $id) = explode(':', $fpid->_value);
          list($owner) = explode('-', $owner_collection);
          if (($owner == $this->agency)
            || ($owner == '870970')
            || in_array($this->agency, self::value_or_default($this->config->get_value('all_rawrepo_agency', 'setup'), []))
          ) {
            $docs['docs'][] = [RR_MARC_001_A => $id, RR_MARC_001_B => $owner, RR_MARC_001_AB => $id . ':' . $owner];
          }
        }
        $s11_agency = self::value_or_default($this->config->get_value('s11_agency', 'setup'), []);
        if ($fetch_raw_records) {
          $collections = self::get_records_from_rawrepo($this->repository['rawrepo'], $docs, in_array($this->agency, $s11_agency));
        }
        if (is_scalar($collections)) {
          $error = $collections;
          return $ret_error;
        }
        _Object::set_value($ret, 'searchResponse', new stdClass());
        _Object::set_value($ret->searchResponse->_value, 'result', new stdClass());
        $result = &$ret->searchResponse->_value->result->_value;
        if (!empty($this->format['found_solr_format'])) {
          self::collections_from_solr($collections, $docs);
        }
        _Object::set_value($result, 'hitCount', count($collections));
        _Object::set_value($result, 'collectionCount', count($collections));
        _Object::set_value($result, 'more', 'false');
        $result->searchResult = &$collections;
        $result->statInfo = new stdClass();
        _Object::set_value($result->statInfo->_value, 'time', $this->watch->splittime('Total'));
        _Object::set_value($result->statInfo->_value, 'trackingId', VerboseJson::$tracking_id);
        if ($this->debug_query) {
          _Object::set_value($debug_result, 'rawQueryString', $solr_arr['debug']['rawquerystring']);
          _Object::set_value($debug_result, 'queryString', $solr_arr['debug']['querystring']);
          _Object::set_value($debug_result, 'parsedQuery', $solr_arr['debug']['parsedquery']);
          _Object::set_value($debug_result, 'parsedQueryString', $solr_arr['debug']['parsedquery_toString']);
          _Object::set_value($result, 'queryDebugResult', $debug_result);
        }
        self::log_stat_get_object($id_array);
        return $ret;
      } finally {
        $this->watch->stop('rawrepo');
      }
    }

    $this->watch->start('precql');
    $localdata_object = [];
    foreach ($fpids as $fpid) {
      $id_array[] = $fpid->_value;
      list($owner_collection, $id) = explode(':', $fpid->_value);
      list($owner) = explode('-', $owner_collection);
// 870970-basis contain records for libraries with 'use_localdata_stream switch set
// BUT, similar actions should be made for school libraries and 300000-katalog, but no switch is currently made for this
      if (self::agency_rule($owner, 'use_localdata_stream')) {
        $id_array[] = '870970-basis:' . $id;
        $localdata_object[$fpid->_value] = '870970-basis:' . $id;
      }
    }
    $this->watch->start('precql_newsolrquery');
    $this->cql2solr = new SolrQuery($this->repository, $this->config);
    $this->watch->stop('precql_newsolrquery');
    $this->watch->stop('precql');
    $this->watch->start('cql');
// need to set all ids in ' to handle ids containing cql specific characters
    $chk_query = $this->cql2solr->parse('rec.id=(\'' . implode('\'' . OR_OP . '\'', $id_array) . '\')');
    if (DEBUG_ON) {
      echo 'rec.id=(\'' . implode('\'' . OR_OP . '\'', $id_array) . '\')' . PHP_EOL;
      var_dump($chk_query);
    }
    $this->watch->stop('cql');
    $this->watch->start('solrq');
    $edismax_q = $chk_query['edismax']['q'] ?? [];
    $solr_q = 'wt=phps' .
        '&q=' . urlencode(implode(AND_OP, $edismax_q)) .
        '&fq=' . $filter_q .
        '&start=0' .
        '&rows=500' .
        '&defType=edismax' .
        '&fl=' . FIELD_COLLECTION_INDEX . ',' . FIELD_WORK_ID . ',' . FIELD_REPOSITORY_ID . ',rec.id,' . FIELD_UNIT_ID . ',unit.isPrimaryObject' .
        $add_fl . '&trackingId=' . VerboseJson::$tracking_id;
    VerboseJson::log(TRACE, 'Search for pids in Solr: ' . $this->repository['solr'] . str_replace('wt=phps', '?', $solr_q));
    $curl = new curl();
    $curl->set_option(CURLOPT_TIMEOUT, self::value_or_default($this->config->get_value('curl_timeout', 'setup'), 20));
    $curl->set_post($solr_q); // use post here because query can be very long. curl has current 8192 as max length get url
    $this->watch->start("solrq_curl");
    $solr_result = $curl->get($this->repository['solr']);
    $this->watch->stop("solrq_curl");
    $curl->close();
    $this->watch->start("solrq_unserialize");
    $solr_2_arr[] = unserialize($solr_result);
    $this->watch->stop("solrq_unserialize");
    $this->watch->stop('solrq');

    $this->watch->start("buildresponse");
    try {
      $work_ids = [];
      foreach ($fpids as $fpid_number => $fpid) {
        $work_ids[$fpid_number] = array('NotFound' => array($fpid->_value => $fpid->_value));
        $localdata_pid = $localdata_object[$fpid->_value] ?? '';
        foreach ($solr_2_arr as $s_2_a) {
          if (@$s_2_a['response']['docs']) {
            foreach ($s_2_a['response']['docs'] as $fdoc) {
              $rec_id = $fdoc[FIELD_REC_ID];
              $unit_id = self::scalar_or_first_elem($fdoc[FIELD_UNIT_ID]);
              if (in_array($fpid->_value, $rec_id)) {
                $work_ids[$fpid_number] = array($unit_id => array($fpid->_value => $fpid->_value));
                break 2;
              } elseif (in_array($localdata_pid, $rec_id)) {
                $work_ids[$fpid_number] = array($unit_id => array($localdata_pid => $localdata_pid));
              }
            }
          }
        }
      }

      // read requested record in unit's. Fetch addi records if relations is part of the request
      list($raw_res, $primary_pids, $unit_info, $relation_units) = self::read_records_and_extract_data($work_ids, $param, 'PID');

      // If relations is asked for, build a search to find available records using "full" search profile
      list($rel_res, $rel_unit_pids) = self::fetch_valid_relation_records($relation_units);

      // collect holdings if requested
      $holdings_res = self::collect_holdings($work_ids, $param->includeHoldingsCount ?? '', 'PID');

      if (DEBUG_ON) {
        echo PHP_EOL . 'fpids:' . PHP_EOL;
        var_dump($fpids);
        echo PHP_EOL . 'holdings_res:' . PHP_EOL;
        var_dump($holdings_res);
        echo PHP_EOL . 'raw_res:' . PHP_EOL;
        var_dump($raw_res);
        echo PHP_EOL . 'relation_units (relations found in records):' . PHP_EOL;
        var_dump($relation_units);
        echo PHP_EOL . 'rel_unit_pids (relations in search profile):' . PHP_EOL;
        var_dump($rel_unit_pids);
        echo PHP_EOL . 'rel_res (relation records):' . PHP_EOL;
        var_dump($rel_res);
        echo PHP_EOL . 'work_ids:' . PHP_EOL;
        var_dump($work_ids);
      }

      $record_repo_dom = new DomDocument();
      $record_repo_dom->preserveWhiteSpace = FALSE;
      $missing_record = $this->config->get_value('missing_record_getObject', 'setup');
      foreach ($work_ids as $rec_no => &$work) {
        foreach ($work as $unit_id => $pids) {
          $key = reset($pids);
          $o = new stdClass();
          $o->collection = new stdClass();
          @ _Object::set_value($o->collection->_value, 'resultPosition', $rec_no + 1);  // ignore PHP warning
          _Object::set_value($o->collection->_value, 'numberOfObjects', 1);

          if (@ !$raw_res[$key] || @ !$record_repo_dom->loadXML($raw_res[$key])) {
            if (@ !$raw_res[$key]) {
                VerboseJson::log(DEBUG, 'Record ' . $key . ' does not exist in index');
            } else {
                // Object is in index, but not in repository or parsing error
                VerboseJson::log(FATAL, 'Cannot load recid ' . reset($pids) . ' into DomXml');
            }
            if ($missing_record) {
              $record_repo_dom->loadXML(sprintf($missing_record, reset($pids)));
            } else {
              $o->collection->_value->object = new stdClass();
              @ _Object::set_value($o->collection->_value->object->_value, 'error', 'unknown/missing/inaccessible record: ' . reset($pids));  // ignore PHP warning
              _Object::set_value($o->collection->_value->object->_value, 'identifier', reset($pids));
            }
          }
          if (empty($o->collection->_value->object)) {
            _Object::set($o->collection->_value->object[], '_value',
              self::build_record_object($record_repo_dom,
                $raw_res[$key],
                reset($pids),
                $rel_res,
                !empty($relation_units[$key]) ? $relation_units[$key] : NULL,
                $rel_unit_pids,
                !empty($primary_pids[$key]) ? $primary_pids[$key] : NULL,
                !empty($holdings_res[$key]) ? $holdings_res[$key] : NULL,
                $param));
          }
          _Object::set($collections[], '_value', $o);
          unset($o);
        }
      }

      //var_dump($corepo_urls); var_dump($corepo_res); var_dump($fpids); var_dump($match); var_dump($solr_2_arr); die();

      if (!empty($this->format['found_open_format'])) {
        self::format_records($collections);
      }
      if (!empty($this->format['found_solr_format'])) {
        self::format_solr($collections, $solr_2_arr);
      }
      self::remove_unselected_formats($collections);

      $ret = new stdClass();
      $ret->searchResponse = new stdClass();
      $ret->searchResponse->_value = new stdClass();
      $ret->searchResponse->_value->result = new stdClass();
      $ret->searchResponse->_value->result->_value = new stdClass();
      @ $result = &$ret->searchResponse->_value->result->_value;  // ignore PHP warning
      $no_collections = is_countable($collections) ? count($collections) : 0;
      _Object::set_value($result, 'hitCount', $no_collections);
      _Object::set_value($result, 'collectionCount', $no_collections);
      _Object::set_value($result, 'more', 'false');
      $result->searchResult = $collections;
      _Object::set_value($result, 'facetResult', '');
      $ret->searchResponse->_value->result->_value->statInfo = new stdClass();
      $ret->searchResponse->_value->result->_value->statInfo->_value = new stdClass();
      @ _Object::set_value($result->statInfo->_value, 'fedoraRecordsCached', $this->number_of_record_repo_cached);  // ignore PHP warning
      _Object::set_value($result->statInfo->_value, 'fedoraRecordsRead', $this->number_of_record_repo_calls);
      _Object::set_value($result->statInfo->_value, 'time', $this->watch->splittime('Total'));
      _Object::set_value($result->statInfo->_value, 'trackingId', VerboseJson::$tracking_id);

      self::log_stat_get_object($id_array);

      return $ret;
    } finally {
      $this->watch->stop('buildresponse');
    }

  }

  /** \brief Entry info: collect info
   *
   * @param object $param - the user request
   * @return object - the answer to the request
   */
  public function info($param) {
    // Add custom headers *always* - to allow SLA
    self::add_dbc_sla_header_action("info");

    $this->watch->start('info');
    try {
      $ret = new stdClass();
      $ret->infoResponse = new stdClass();
      $ret->infoResponse->_value = new stdClass();
      $ret->infoResponse->_value->infoGeneral = new stdClass();
      @ $result = &$ret->infoResponse->_value;  // ignore PHP warning
      @ _Object::set_value($result->infoGeneral->_value, 'defaultRepository', $this->config->get_value('default_repository', 'setup'));  // ignore PHP warning
      $result->infoRepositories = self::get_repository_info();
      $result->infoObjectFormats = self::get_object_format_info();
      $result->infoSearchProfile = self::get_search_profile_info($param->agency->_value, $param->profile);
      $result->infoSorts = self::get_sort_info();
      $result->infoNameSpaces = self::get_namespace_info();
      // We know now the agency, so set it in the header.
      // Actually, it turns out that this is probably newer set for info calls... it turns up empty, anyway.
      self::add_dbc_sla_header_agency($this->agency);
      VerboseJson::log(STAT, array('agency' => $this->agency,
        'profile' => self::stringify_obj_array($param->profile),
        'timings' => $this->watch->get_timers()));
      return $ret;
    } finally {
      $this->watch->stop('info');
    }
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
    if (isset($param->userDefinedRanking)) {
      $rr = $param->userDefinedRanking;
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
    elseif (isset($param->sort) && is_object($param->sort) && is_scalar($param->sort->_value)) {
      if (isset($rank_types[$param->sort->_value])) {
        $rank = $param->sort->_value;
      }
    }
    return !empty($rank);
  }

  /** \brief parse input for sort parameters
   *
   * @param object $param -       The request
   * @param array $sort -        Name of sort used by request
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
      // Get information about limitations in sort for this repo.
      $repo_sorts = self::fetch_sortfields_in_repository();
      foreach ($sorts as $s) {
        if (!isset($sort_types[$s->_value])) {
          return 'Error: Unknown sort: ' . $s->_value;
        }
        $random = $random || ($s->_value == 'random');
        if ($random && count($sort)) {
          return 'Error: Random sorting can only be used alone';
        }
        // If this specific sort "vector" is disabled for this repo, then ignore it, and skip to the next
        if (empty($repo_sorts[$s->_value])) {
          VerboseJson::log(DEBUG, "Request for sort on element '"
            . $s->_value
            . "'' requested, but this sort element is not supported in configuration for repository. "
            . "Element is ignored in sort.");
        }
        else {
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
    return null;
  }

  /** \brief decides which formats to include in result and how the should be build
   *
   * @param mixed $objectFormat
   * @param array $open_format
   * @param array $force_namespace
   * @param array $solr_format
   * @return array
   */
  private function set_format($objectFormat, $open_format, $force_namespace, $solr_format) {
    if (is_array($objectFormat))
      $help = $objectFormat;
    elseif (empty($objectFormat->_value))
      _Object::set($help[], '_value', 'dkabm');
    else
      $help[] = $objectFormat;
    $ret = ['found_open_format' => FALSE, 'found_solr_format' => FALSE];
    foreach ($help as $of) {
      if (isset($open_format[$of->_value])) {
        $ret[$of->_value] = ['user_selected' => TRUE,
                             'is_open_format' => TRUE,
                             'format_name' => $open_format[$of->_value]['format'],
                             'uri' => $open_format[$of->_value]['uri'] ?? null];
        if (isset($force_namespace[$of->_value])) {
          $ret[$of->_value]['force_namespace'] = $force_namespace[$of->_value];
        }
        $ret['found_open_format'] = TRUE;
      }
      elseif (isset($solr_format[$of->_value])) {
        $ret[$of->_value] = ['user_selected' => TRUE, 'is_solr_format' => TRUE, 'format_name' => $solr_format[$of->_value]['format']];
        $ret['found_solr_format'] = TRUE;
      }
      else {
        $ret[$of->_value] = ['user_selected' => TRUE, 'is_solr_format' => FALSE];
      }
    }
    if (!empty($ret['found_open_format']) || !empty($ret['found_solr_format'])) {
      if (empty($ret['dkabm']))
        $ret['dkabm'] = ['user_selected' => FALSE, 'is_open_format' => FALSE];
      if (empty($ret['marcxchange']))
        $ret['marcxchange'] = ['user_selected' => FALSE, 'is_open_format' => FALSE];
    }
    return $ret;
  }

  private static function set_app_id() {
    $pod_name = getenv('POD_NAME') ?: 'NO_POD';
    $namespace = getenv('POD_NAMESPACE') ?: 'NO_NAMESPACE';
    return $namespace . '-' .$pod_name;
  }

  /** \brief Build Solr filter_query parm
   *
   * @param array $collection_identifiers - The sources that the profile contatins
   * @return string - the SOLR filter query that represent the profile
   */
  private function solr_profile_filter($collection_identifiers) {
    return $collection_identifiers ? '({!terms f=' . FIELD_COLLECTION_INDEX . '}' . implode(",", $collection_identifiers) . ')' : '';
  }

  /** \brief Build Solr filter_query parm
   *
   * @param array $profile - the users search profile
   * @param string $add_relation_sources - include sources using relations
   * @return string - the SOLR filter query that represent the profile
   */
  private function set_solr_filter($profile, $add_relation_sources = FALSE) {
    $ret = [];
    if (is_array($profile)) {
      foreach ($profile as $p) {
        if (self::xs_boolean($p['sourceSearchable']) || ($add_relation_sources && isset($p['relation']) && count($p['relation']))) {
          $source_id = $p['sourceIdentifier'] ?? '';
          if(@$p['sourceIdentifier']) {
            $ret[] = $p['sourceIdentifier'];
          }
        }
      }
    }
    return $this->solr_profile_filter($ret);
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
        $weight = floatval($bf->_value->weight->_value) ? : 1;
        if ($weight < 0) {
           return $weight;
           break;
        }
        if (empty($bf->_value->fieldValue->_value)) {
          $ret .= '&bf=' .
            urlencode('product(' . $bf->_value->fieldName->_value . ',' . sprintf('%.5f', $weight) . ')');
        }
        else {
          $ret .= '&bq=' .
            urlencode($bf->_value->fieldName->_value . ':"' .
                      str_replace('"', '', $bf->_value->fieldValue->_value) . '"^' .
                      sprintf('%.5f', $weight));
        }
      }
    }
    return $ret;
  }

  /** \brief Check if this is a collection idenifier that is related to holdings
   *
   * @param array $source - a collection identifier from the profile
   * @return boolean - if this source can have holdings-items
   */
  private function is_agency_catalog_source($source) {
    return $source == $this->agency . '-katalog' ||
           $source == $this->agency . '-komplet' ||
           $source == $this->agency . '-aktive' ||
           $source == $this->agency . '-egne' ||
           $source == $this->agency . '-inaktive' ||
           $source == '870970-basis';
  }

  /** \brief Build search to include collections without holdings
   *
   * @param array $profile - the users search profile
   * @param string $add_relation_sources - include sources using relations
   * @return string - the SOLR filter query that represent the profile
   */
  private function split_collections_for_holdingsitem($profile, $add_relation_sources = FALSE) {
    $bibliographic_collections = $holdings_collections = [];
    if (is_array($profile)) {
      foreach ($profile as $p) {
        $source_id = $p['sourceIdentifier'] ?? '';
        if (self::xs_boolean($p['sourceSearchable']) || ($add_relation_sources && count($p['relation']))) {
          if (self::is_agency_catalog_source($source_id)) {
            $holdings_collections[] = $source_id;
          } else {
            $bibliographic_collections[] = $source_id;
          }
        }
      }
    }
    return array(
      'bibliographic' => $this->solr_profile_filter($bibliographic_collections),
      'holdings' => $this->solr_profile_filter($holdings_collections),
    );
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
    if (!empty($this->repository_name)
        && is_scalar($this->repository_name)
        && isset($repositories[$this->repository_name])
        && ($this->repository = $repositories[$this->repository_name])) {
      foreach ($repositories['defaults'] as $key => $url_par) {
        if (empty($this->repository[$key])) {
          $this->repository[$key] = self::expand_default_repository_setting($key) ? ($this->repository['fedora'] ?? '') . $url_par : $url_par;
        }
      }
      $handler_format = &$this->repository['handler_format'];
      if(is_array($handler_format)) {
        foreach ($handler_format['holding'] as &$format) {
          $format = urldecode($format);
        }
      }
      if ($cql_file_mandatory && empty($this->repository['cql_file'])) {
        VerboseJson::log(FATAL, 'cql_file not defined for repository: ' . $this->repository_name);
        return 'Error: cql_file not defined for repository: ' . $this->repository_name;
      }
      if ($this->repository['cql_file']) {
        if (!$this->repository['cql_settings'] = self::get_solr_file('solr_file', $this->repository['cql_file'])) {
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
        _Object::set($ret[], '_value', $pid);
      }
    }
    return $ret;
  }

  /** Need to escape some characters to remove cql conflicts
   * @param $pids
   * @return mixed
   */
  private function sanitize_pids($pids) {
    $cql_chars = ['+', '!', '(', ')', '{', '}', '[', ']', '^', '~', ' '];
    $cql_chars_escaped = [];
    foreach ($cql_chars as $ch) {
      $cql_chars_escaped[] = '\\' . $ch;
    }
    foreach ($pids as &$pid) {
      $pid->_value = str_replace($cql_chars, $cql_chars_escaped, $pid->_value);
    }
    return $pids;
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
        _Object::set_value($ret, 'sortUsed', $rank);
      }
    }
    elseif (!empty($sort)) {
      if ($key = array_search($sort, $sort_types)) {
        _Object::set_value($ret, 'sortUsed', $key);
      }
      else {
        foreach ($sort as $s) {
          _Object::set_array_value($ret, 'sortUsed', $s);
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
    _Object::set_value($ret, 'rawQueryString', $solr_debug['rawquerystring']);
    _Object::set_value($ret, 'queryString', $solr_debug['querystring']);
    _Object::set_value($ret, 'parsedQuery',
        is_scalar($solr_debug['parsedquery']) ?
            $solr_debug['parsedquery'] :
            implode("\n", $solr_debug['parsedquery']));
    _Object::set_value($ret, 'parsedQueryString',
        is_scalar($solr_debug['parsedquery_toString']) ?
            $solr_debug['parsedquery_toString'] :
            implode("\n", $solr_debug['parsedquery_toString']));
    if (!empty(get_object_vars($best_match_debug))) {
      _Object::set_value($ret, 'bestMatch', $best_match_debug);
    }
    if ($rank_freq_debug) {
      _Object::set_value($ret, 'rankFrequency', $rank_freq_debug);
    }
    return $ret;
  }

  /** \brief Pick tags from solr result and create format
   *
   * @param array $collections - the structure is modified
   * @param array $solr
   */
  private function collections_from_solr(&$collections, $solr) {
    foreach ($solr['docs'] as $idx => $solr_doc) {
      $pos = $solr['start'] + $idx + 1;
      if (empty($collections[$pos])) {
        $collections[$pos] = new StdClass();
        _Object::set_value($collection, 'resultPosition', $pos);
        _Object::set_value($collection, 'numberOfObjects', '1');
        _Object::set_value($collections[$pos]->_value, 'collection', $collection);
        unset($collection);
      }
      foreach ($this->format as $format_name => $format_arr) {
        if (!empty($format_arr['is_solr_format'])) {
          $format_tags = explode(',', $format_arr['format_name']);
          $mani = self::collect_solr_tags($format_tags, $solr_doc);
          _Object::set($formattedCollection, $format_name, $mani);
          unset($mani);
        }
      }
      _Object::set_value($collections[$pos]->_value, 'formattedCollection', $formattedCollection);
      unset($formattedCollection);
    }
    return;
  }

  /** \brief Pick tags from solr result and create format
   *
   * @param array $collections - the structure is modified
   * @param array $solr
   */
  private function format_solr(&$collections, $solr) {
    //var_dump($collections); var_dump($solr); die();
    $solr_display_ns = $this->xmlns['ds'] ?? '';
    $this->watch->start('format_solr');
    foreach ($this->format as $format_name => $format_arr) {
      if (!empty($format_arr['is_solr_format'])) {
        $format_tags = explode(',', $format_arr['format_name']);
        foreach ($collections as $idx => &$c) {
          $format_pids = [];
          $manifestation = new stdClass();
          foreach ($c->_value->collection->_value->object as $o_key => $obj) {
            $pid = $obj->_value->identifier->_value ?? '';
            if (!empty($pid)) {
              $best_idx = $this->find_best_solr_rec($solr[0]['response']['docs'], FIELD_REC_ID, $pid);
              $solr_doc = &$solr[0]['response']['docs'][$best_idx];
              $mani = self::collect_solr_tags($format_tags, $solr_doc, $pid);
            }
            if (isset($mani)) {   // should contain data if pid exist, but for some odd reason it can be empty. Some bug in the solr-indexes?
              $mani->_namespace = $solr_display_ns;
              $manifestation->manifestation[$o_key] = $mani;
            }
            unset($mani);
            $format_pids[$o_key] = $obj->_value->identifier->_value ?? '';
          }
// need to sort objects to put data correct
          if (is_array(@$manifestation->manifestation) && count(@$manifestation->manifestation) > 1) {
            ksort($manifestation->manifestation);
          }
          $c->_value->formattedCollection = new stdClass();
          _Object::set_namespace($c->_value->formattedCollection->_value, $format_name, $solr_display_ns);
          _Object::set_value($c->_value->formattedCollection->_value, $format_name, $manifestation);
          unset($manifestation);
        }
      }
    }
    $this->watch->stop('format_solr');
  }

  /** \brief Pick tags from solr result and create format
   *
   * @param array $format_tags - tags to collect from the solr result
   * @param array $solr_doc - the solr result
   * @param string $pid - identifier for the formatting record
   * @return mixed
   */
  private function collect_solr_tags($format_tags, $solr_doc, $pid = '') {
    $solr_display_ns = $this->xmlns['ds'] ?? '';
    $mani = new stdClass();
    foreach ($format_tags as $format_tag) {
      if (!empty($solr_doc[$format_tag]) || $format_tag == 'fedora.identifier') {
        if (strpos($format_tag, '.')) {
          list($tag_NS, $tag_value) = explode('.', $format_tag);
          if (is_numeric($tag_value[0])) $tag_value = $format_tag;
        }
        else {
          $tag_value = $format_tag;
        }
        if ($format_tag == 'fedora.identifier') {
          _Object::set_namespace($mani->_value, $tag_value, $solr_display_ns);
          _Object::set_value($mani->_value, $tag_value, $pid);
        }
        else {
          if (is_array($solr_doc[$format_tag])) {
            if (is_array($this->feature_sw) && $this->feature_sw[$format_tag] == 'array') {   // more than one for this
              foreach ($solr_doc[$format_tag] as $solr_tag) {
                $help = new stdClass();
                $help->_namespace = $solr_display_ns;
                $help->_value = self::normalize_chars($solr_tag);
                $mani->_value->{$tag_value}[] = $help;
                unset($help);
              }
            }
            else {
              _Object::set_namespace($mani->_value, $tag_value, $solr_display_ns);
              _Object::set_value($mani->_value, $tag_value, self::normalize_chars($solr_doc[$format_tag][0]));
            }
          }
          else {
            _Object::set_namespace($mani->_value, $tag_value, $solr_display_ns);
            _Object::set_value($mani->_value, $tag_value, self::normalize_chars($solr_doc[$format_tag]));
          }
        }
      }
    }
    return $mani;
  }


  /** \brief Setup call to OpenFormat and execute the format request
   * If ws_open_format_uri is set, the format request is send to that server otherwise
   * openformat is included using the [format] section from config
   *
   * @param array $collections - the structure is modified
   */
  private function format_records(&$collections) {

    $this->watch->start('format');
    $open_format_uri = $this->config->get_value('ws_open_format_uri', 'setup');
    if(!$open_format_uri) {
      header('HTTP/1.0 500 Internal Server Error');
      die('HTTP/1.0 500 Internal Server Error OpenFormat Configuration Required');
    }

    $open_format_request = array(
      "formats" => [],
      "objects" => [],
      "trackingId" => VerboseJson::$tracking_id
    );
    foreach ($this->format as $format_name => $format_arr) {
      if (!empty($format_arr['is_open_format'])) {
        $open_format_request['formats'][] = $format_arr['format_name'];
      }
    }

    $object_target = []; // Where does each response go

    if(!empty($open_format_request['formats'])) {
      foreach ($collections as $collection) {
        $formattedCollection = new stdClass();
        $formattedCollection->_value = new stdClass();
        $collection->_value->formattedCollection = $formattedCollection;
        foreach($collection->_value->collection->_value->object as $k => &$v) {
          _Object::set($obj, 'object', $v);
          $open_format_request['objects'][] = array("object" => $this->objconvert->obj2xmlNs($obj));
          $object_target[] = $formattedCollection;
        }
      }

      VerboseJson::log(DEBUG, $open_format_request);

      $this->curl->set_post(json_encode($open_format_request), 0);
      $this->curl->set_option(CURLOPT_HTTPHEADER, ['Content-Type: application/json'], 0);
      $open_format_response_raw = $this->curl->get($open_format_uri);
      $open_format_response_status = $this->curl->get_status();
      $this->curl->set_option(CURLOPT_POST, 0, 0);
      $this->curl->close();

      VerboseJson::log(DEBUG, json_encode($open_format_response_status));
      $open_format_response = @json_decode($open_format_response_raw);
      if(!$open_format_response) {
        VerboseJson::log(FATAL, 'openFormat http-error: ' . $open_format_response_status['http_code'] .
                                ' from: ' . $open_format_response_status['url'] .
                                ' with content: ' . $open_format_response_raw);
      } else {
        VerboseJson::log(DEBUG, $open_format_response);
        foreach($open_format_response->objects as $idx => $response) {
          $doc_target = $object_target[$idx];
          foreach($open_format_request['formats'] as $format) {
            if(!isset($doc_target->_value->$format)) {
              $doc_target->_value->$format = new StdClass();
              $doc_target->_value->$format->_namespace = $this->xmlns['of'];
              $doc_target->_value->$format->_value = new StdClass();
            }
          }
          foreach ($open_format_response->objects[$idx] as $format => $format_response) {
            $target = new StdClass();
            $target->_namespace = $this->xmlns['of'];
            if(isset($format_response->error)) {
              VerboseJson::log(FATAL, 'openFormat format-error: ' . $format_response->error);
              _Object::set_value($target->_value, $format, $format_response->error);
            } else if(isset($format_response->formatted)) {
              VerboseJson::log(DEBUG, $format_response);
              $dom = new DomDocument();
              $dom->preserveWhiteSpace = false;
              if(!@$dom->loadXML($format_response->formatted)) {
                VerboseJson::log(FATAL, 'openFormat Invalid XML: ' . $format_response->formatted);
                _Object::set_value($target->_value, $format, "Invalid XML in response from formatting service");
              } else {
                $formatted = $this->xmlconvert->xml2obj($dom, '', $this->xmlns['of']);
                $target = $formatted->display;
              }
            } else {
              VerboseJson::log(FATAL, 'openFormat neither formatted nor error');
              _Object::set_value($target->_value, $format, "Invalid response from formatting service");
            }
            self::add_to_array($doc_target->_value->$format, $target);
          }
        }
      }
    }
    $this->watch->stop('format');
  }

  /** \brief Add a repeated element to output xml
   *
   * @param array $node where in output tp palace it under
   * @param array $value what to place there
   */
  private function add_to_array(&$node, $value) {
    foreach($value->_value as $k => $v) {
      if(!str_starts_with($k, '_')) {
        if(!isset($node->_value->$k)) {
          $node->_value->$k = $v;
        } else {
          if(!is_array($node->_value->$k))
            $node->_value->$k = [ $node->_value->$k ];
          if(!is_array($v))
            $v = [$v];
          foreach($v as $v1)
            $node->_value->$k[] = $v1;
        }
        return;
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
        if (!empty($collection->record->_value->datafield)) {
          foreach ($collection->record->_value->datafield as $idf => &$df) {
            foreach ($marc_filters as $tag => $filter) {
              if (preg_match('/' . $tag . '/', $df->_attributes->tag->_value)) {
                if (is_array($df->_value->subfield)) {
                  foreach ($df->_value->subfield as $isf => &$sf) {
                    if (preg_match('/' . $filter . '/', $sf->_attributes->code->_value)) {
                      unset($collection->record->_value->datafield[$idf]->_value->subfield[$isf]);
                    }
                  }
                  if (!count($df->_value->subfield)) {  // removed all subfield
                    unset($collection->record->_value->datafield[$idf]);
                  }
                }
                elseif (preg_match('/' . $filter . '/', $df->_value->subfield->_attributes->code->_value)) {
                  unset($collection->record->_value->datafield[$idf]);
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
            if (@$section->_value->$item->_value == $match) {
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
    if (is_iterable($s_docs)) {
      foreach ($s_docs as $s_idx => $s_rec) {
        $no_coll = is_countable($s_rec[FIELD_COLLECTION_INDEX]) ? count($s_rec[FIELD_COLLECTION_INDEX]) : 0;
        if (is_array($s_rec[$field]) && in_array($match, $s_rec[$field]) && ($max_coll < $no_coll)) {
          $max_coll = $no_coll;
          $best_idx = $s_idx;
        }
      }
    }
    return $best_idx;
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
      $ret .= '&facet=true&facet.threads=' . $max_threads . '&facet.limit=' . $facets->numberOfTerms->_value . '&facet.mincount=' . $facet_min;
      if (isset($facets->facetSort)) {
        $ret .= '&facet.sort=' . $facets->facetSort->_value;
      }
      if (isset($facets->facetOffset)) {
        $ret .= '&facet.offset=' . $facets->facetOffset->_value;
      }
      if (isset($facets->facetName) && is_array($facets->facetName)) {
        foreach ($facets->facetName as $facet_name) {
          $ret .= '&facet.field=' . $facet_name->_value;
        }
      }
      elseif (isset($facets->facetName) && is_scalar($facets->facetName->_value)) {
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
    foreach (['no' => '|: ', 'description' => '|', 'details' => ' (|)', 'pos' => ' at pos |'] as $tag => $txt) {
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
    $add_fl = '';
    if (!empty($this->format['found_solr_format'])) {
      foreach ($this->format as $f) {
        if (!empty($f['is_solr_format'])) {
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
      if ($all_objects) {
        $chk_query['edismax']['q'] = [$add_q];
      }
      else {
        if ($add_query) {
          $chk_query['edismax']['q'][] = $add_q;
        }
      }
      if (!empty($chk_query['error'])) {
        return $chk_query['error'];
      }
      $q = $chk_query['edismax'];
      $rows = (substr_count($add_query, OR_OP) + 3) * 250;
      $solr_url = self::create_solr_url($q, 0, $rows, $filter_q);
      $solr_url['q'] .= '&fl=' . FIELD_COLLECTION_INDEX . ',unit.isPrimaryObject,' . FIELD_UNIT_ID . ',sort.complexKey' . $add_field_list;
      VerboseJson::log(DEBUG, 'Re-search: ' . $this->repository['solr'] . '?' . str_replace('&wt=phps', '', $solr_url['q']) . '&debugQuery=on');
      if (DEBUG_ON) {
        echo 'post_array: ' . $solr_url['url'] . PHP_EOL;
      }

      $curl = new curl();
      $curl->set_option(CURLOPT_TIMEOUT, self::value_or_default($this->config->get_value('curl_timeout', 'setup'), 20));
      $curl->set_post($solr_url['q']); // use post here because query can be very long
      $curl->set_option(CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded; charset=utf-8'], 0);
      $solr_result = $curl->get($solr_url['url']);
      $curl->close();
      if (!($solr_arr[$add_idx] = unserialize($solr_result))) {
        VerboseJson::log(FATAL, 'Internal problem: Cannot decode Solr re-search');
        return 'Internal problem: Cannot decode Solr re-search';
      }
      $numFound = $solr_arr[$add_idx]['response']['numFound'];
      if ($rows < intval($numFound * 1.1)) {
        VerboseJson::log(FATAL, __FUNCTION__ . '() Solr calculated rows, close to hitCount. Adjust code. (calculated: ' . $rows . ' got: ' . $numFound . ')');
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
    return $solr_arr['response']['numFound'] ?? 0;
  }

  /** \brief Encapsules extraction of ids (unitId or workId) solr result
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
          break 1;
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
   * @param array $solr_arr - result from SOLR
   * @return string - error if any, NULL otherwise
   */
  private function get_solr_array($q, $start, $rows, $sort, $rank, $facets, $filter, $boost, &$solr_arr) {
    $solr_urls[0] = self::create_solr_url($q, $start, $rows, $filter, $sort, $rank, $facets, $boost);
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
    $filter = '';
    foreach ($eq['fq'] as $fq) {
      $filter .= '&fq=' . rawurlencode($fq);
    }
    foreach ($guess as $idx => $g) {
      $filter = implode('&fq=', $g['filter']);
      $solr_urls[] = [ 'url' => $this->repository['solr'],
                       'q' => 'q=' . $g['register'] . '%3A(' . urlencode($q) . ')&fq=' . $filter . '&start=1&rows=0&wt=phps'];
      $ret[$idx] = 0;
    }
    $err = self::do_solr($solr_urls, $solr_arr);
    $n = 0;
    foreach ($guess as $idx => $g) {
      $tmp = @$solr_arr[$n++];
      if ($tmp) {
        $ret[$idx] = self::get_num_found($tmp);
      }
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
   * @return array - then SOLR url and url for debug purposes
   */
  private function create_solr_url($eq, $start, $rows, $filter, $sort = '', $rank = '', $facets = '', $boost = '') {
    $q = '(' . implode(')' . AND_OP . '(', $eq['q']) . ')';   // force parenthesis around each AND-node, to fix SOLR problem. BUG: 20957
    $handler_var = '';
    if (isset($eq['handler_var']) && is_array($eq['handler_var'])) {
      $handler_var = '&' . implode('&', $eq['handler_var']);
      $filter = '';  // search profile collection filter is done via fq parm created with handler_var
    }
    if (isset($eq['fq']) && is_array($eq['fq'])) {
      foreach ($eq['fq'] as $fq) {
        $filter .= '&fq=' . rawurlencode($fq);
      }
    }
    if (!empty($eq['add_params'])) {
      // If we need to use combined_search add a count of the number of added parameters as a complexity measurement.
      // The presence of the 'combined_search' key in the log, can be used to filter and determine use of this feature.
      // Se FBI-93 for more information about this feature.
      VerboseJson::set_verbose_element('combined_search', count($eq['add_params']) - 1);
      foreach ($eq['add_params'] as $par => $val) {
        $filter .= '&' . $par . '=' . rawurlencode($val);
      }
    }
    $q = 'q=' . urlencode($q) .
    '&fq=' . $filter .
    '&start=' . $start . $sort . $rank . $boost . $facets . $handler_var .
    '&defType=edismax' .
    '&fl=' . FIELD_REPOSITORY_ID . ',' . FIELD_UNIT_ID . ',' . FIELD_WORK_ID . ',' . FIELD_REC_ID . ',' . FIELD_COLLECTION_INDEX;
    $debug_q = $q . '&rows=1&debugQuery=on';
    $q .= '&wt=phps&rows=' . $rows . ($this->debug_query ? '&debugQuery=on' : ''); 
    return ['url' => $eq['url'] ?? $this->repository['solr'], 'q' => $q, 'debug' => $debug_q];
  }

  /** \brief send one or more requests to Solr
   *
   * @param array $urls - the url(s) to send to SOLR
   * @param array $solr_arr - result from SOLR
   * @return string - error if any, NULL otherwise
   */
  private function do_solr($urls, &$solr_arr) {
    VerboseJson::log(DEBUG, 'do_solr with ' . count($urls) . ' urls');
    $solr_appid = self::set_app_id();
    foreach ($urls as $no => $url) {
      VerboseJson::log(@$url['q'] ? DEBUG : ERROR, "url = " . json_encode($url));
      $url['q'] .= '&trackingId=' . VerboseJson::$tracking_id . '&appId=' . $solr_appid;
      VerboseJson::log(TRACE, 'Query: ' . $url['q']);
      if (isset($url['debug'])) VerboseJson::log(DEBUG, 'Query: ' . $url['debug']);
      $this->curl->set_option(CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded; charset=utf-8'], $no);
      $this->curl->set_post($url['q'], $no);
      $this->curl->set_url($url['url'], $no);
    }
    $this->watch->start('solr');
    $solr_results = $this->curl->get();
    $this->curl->close();
    $this->watch->stop('solr');
    if (empty($solr_results))
      return 'Internal problem: No answer from Solr';
    if (count($urls) > 1) {
      foreach ($solr_results as $solr_result) {
        if (!$solr_arr[] = @unserialize($solr_result)) {
          VerboseJson::log(WARNING, 'Unable to parse solr result' . json_encode($solr_results));
          return 'Internal problem: Cannot decode Solr result';
        }
      }
    }
    elseif (!$solr_arr = @unserialize($solr_results)) {
      VerboseJson::log(WARNING, 'Unable to parse solr result' . json_encode($solr_results));
      return 'Internal problem: Cannot decode Solr result';
    }
    elseif (!empty($solr_arr['error'])) {
      $err = $solr_arr['error'];
      VerboseJson::log(FATAL, 'Solr result in error: (' . $err['code'] . ') ' . preg_replace('/\s+/', ' ', $err['msg']) . ' for queries ' . json_encode($urls));
      return 'Internal problem: Solr result contains error';
    }
    VerboseJson::log(DEBUG, 'do_solr results count: ' . count($solr_arr));
    return null;
  }

  /** \brief Selects a ranking scheme depending on some register frequency lookups
   *
   * @param struct $solr_query - the parsed user query
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
    $debug_str = '';
    foreach ($guess as $idx => $g) {
      $freq = $freqs[$idx] * $g['weight'];
      _Object::set_value($this->rank_frequence_debug, $g['register'], $freq . ' (' . $freqs[$idx] . '*' . $g['weight'] . ')');
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
  private function get_solr_file($ini_def, $name='') {
    static $solr_file_cache;
    $file_url = $this->repository['solr'];
    $file = $this->config->get_value($ini_def, 'setup');
    foreach ($file as $from => $to) {
      $file_url = str_replace($from, $to, $file_url);
    }
    $solr_url = sprintf($file_url, $name);
    if (empty($solr_file_cache)) {
      $cache = self::get_cache_info('solr_file');
      $solr_file_cache = new cache($cache['host'], $cache['port'], $cache['expire']);
    }
    if (!$solr_data = $solr_file_cache->get($solr_url)) {
      $solr_data = $this->curl->get($solr_url);
      if ($this->curl->get_status('http_code') != 200) {
        return FALSE;
      }
      $solr_file_cache->set($solr_url, $solr_data);
      $this->curl->close();
    }
    return $solr_data;
  }

  /** \brief filter allowed sort and rank for the repository
   *
   * sort.exclude and sor.include tables in in-file sepcifies the repository sort setting
   */
  private function fetch_sortfields_in_repository() {
    self::test();
    $sort_def = $this->repository['sort'];
    $all_sorts = $repo_sorts = $this->config->get_value('sort', 'setup');
    if (is_array($sort_def['exclude'])) {
      foreach ($sort_def['exclude'] as $exclude) {
        if ($exclude == 'ALL') {
          $repo_sorts = [];
        }
        else {
          unset($repo_sorts[$exclude]);
        }
      }
    }
    if (isset($sort_def['include']) && is_array($sort_def['include'])) {
      foreach ($sort_def['include'] as $include) {
        $repo_sorts[$include] = $all_sorts[$include];
      }
    }

    return $repo_sorts;
  }

  /** \brief 
   *
   * For next version. Filtering ini-files sort-setup against the solr index
   * get files in index and adjust sorting to include only existing sort-fields in the actual repository (solr)
   */
  private function test() {
    return;
    /*
    $luke_result = self::get_solr_file('solr_luke');
    $all_sorts = $repo_sorts = $this->config->get_value('sort', 'setup');
    if ($luke_result) {
      $repo_sorts = [];
      $solr_fields = json_decode($luke_result);
      foreach ($solr_fields->fields as $sf_name => $sf_field) {
        if (substr($sf_name, 0, 5) == 'sort.') {
          foreach ($all_sorts as $as_name => $as_spec) {
            if (is_scalar($as_spec) && (strpos($as_spec, $sf_name) !== FALSE)) {
              $repo_sorts[$as_name] = $as_spec;
              foreach ($all_sorts as $as_name_arr => $as_spec_arr) {
                if (is_array($as_spec_arr) && in_array($as_name, $as_spec_arr)) {
                  $repo_sorts[$as_name_arr][] = $as_name;
                }
              }
            }
          }
        }
      }
    }
    return $repo_sorts;
    */
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
   * @return mixed string or null
   */
  private function build_work_struct_from_solr(&$work_cache_struct, &$work_struct, &$more, &$work_ids, $edismax, $start, $step_value, $rows, $sort_q, $rank_q, $filter_q, $boost_q, $use_work_collection, $all_objects, $num_found) {
    $more = (count($work_cache_struct) >= ($start + $step_value));
    for ($w_idx = 0; isset($work_ids[$w_idx]); $w_idx++) {
      $struct_id = $work_ids[$w_idx][FIELD_WORK_ID] . ($use_work_collection ? '' : '-' . $work_ids[$w_idx][FIELD_UNIT_ID]);
      if (isset($work_cache_struct[$struct_id])) continue;
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
        if ($err = self::get_solr_array($edismax, 0, $rows, $sort_q, $rank_q, '', $filter_q, $boost_q, $solr_arr)) {
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
      foreach ($work_slice as $key_id => $w_list) {
        if (empty($w_list)) {
          @ list($w_id, $u_id) = explode('-', $key_id);
          $search_w[] = '"' . ($use_work_collection ? $w_id : $u_id) . '"';
        }
      }
      if (isset($search_w) && is_array($search_w)) {
        if ($all_objects) {
          $edismax['q'] = [];
        }
        $edismax['q'][] = ($use_work_collection ? FIELD_WORK_ID : FIELD_UNIT_ID) . ':(' . implode(OR_OP, $search_w) . ')';
        if ($err = self::get_solr_array($edismax, 0, 99999, '', $rank_q, '', $filter_q, '', $solr_arr)) {
          return $err;
        }
        foreach ($solr_arr['response']['docs'] as $fdoc) {
          $unit_id = $fdoc[FIELD_UNIT_ID];
          $work_id = $fdoc[FIELD_WORK_ID];
          foreach ($fdoc[FIELD_REC_ID] as $rec_id) {
            if (self::is_corepo_pid($rec_id)) {
              $struct_id = $work_id . ($use_work_collection ? '' : '-' . $unit_id);
              $work_cache_struct[$struct_id][$unit_id][$rec_id] = $rec_id;;
            }
          }
        }
      }
    }
    $work_struct = array_slice($work_cache_struct, ($start - 1), $step_value);
    //var_dump($edismax); var_dump($work_slice); var_dump($work_struct); var_dump($work_cache_struct); var_dump($work_ids); die();
    return null;
  }


  /**************************************
   *********** repo functions ***********
   **************************************/


  /**
   * @param $work_ids
   * @param $param
   * @param $collect_type
   * @return array
   */
  private function read_records_and_extract_data($work_ids, $param, $collect_type) {
    $raw_urls = [];
    foreach ($work_ids as $work) {
      foreach ($work as $unit_id => $pids) {
        if ($unit_id <> 'NotFound') {
          $key = $collect_type == 'PID' ? reset($pids) : $unit_id;
          $raw_urls[$key] = self::corepo_get_url($unit_id, $pids);
          if (isset($param->relationData) && in_array($param->relationData->_value, ['type', 'uri', 'full'])) {
            $raw_urls[$key . '-addi'] = self::record_repo_url('fedora_get_rels_addi', $unit_id);
          }
        }
      }
    }
    $raw_res = self::read_record_repo_all_urls($raw_urls);
    // unpack records and find and retrieve relations if requested and collect unit's from relations (if needed)
    $relation_units = [];
    $primary_pids = [];
    $unit_info = [];
    foreach ($raw_res as $record_key => $record) {
      if (strpos($record_key, '-addi')) {
        $unit_id = str_replace('-addi', '', $record_key);
        $relation_units[$unit_id] = self::parse_addi_for_units_in_relations($record_key, $record);
      }
      else {
        $help = json_decode($record);
        $raw_res[$record_key] = $help->dataStream ?? '';
        $primary_pids[$record_key] = $help->primaryPid ?? '';
        $h_pids = $help->pids ?? array('');
        $unit_info[$record_key] = [$h_pids, $h_pids[0], $primary_pids[$record_key]];
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
    $trackingId = 'trackingId=' . VerboseJson::$tracking_id;
    foreach ($urls as $key => $uri) {
      VerboseJson::log(TRACE, 'repo_read: ' . $uri);
      if (DEBUG_ON) echo __FUNCTION__ . ':: ' . $uri . "\n";
      if ($this->cache && ($ret[$key] = $this->cache->get($uri))) {
        $this->number_of_record_repo_cached++;
      }
      else {
        $this->number_of_record_repo_calls++;
        $res_map[$no] = $key;
        $curl->set_url($uri . (strpos($uri, '?') ? '&' : '?') . $trackingId, $no);
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
          $s = &$status[$no];
          $this->corepo_timers[] = (object) array('http' => $s['http_code'], 'total' => $s['total_time'], 'namelookup' => $s['namelookup_time'], 'connect' => $s['connect_time'], 'pretransfer' => $s['pretransfer_time']);
          if (!strpos($urls[$res_map[$no]], 'RELS-EXT') && (empty($rec) || $status[$no]['http_code'] > 299)) {
            VerboseJson::log(ERROR, 'record_repo http-error: ' . $status[$no]['http_code'] . ' from: ' . $urls[$res_map[$no]] .
                              ' record ' . substr(preg_replace('/\s+/', ' ', $rec), 0, 200) . '...');
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
    $rec_pos = $solr_response['start'] ?? 0;
    $post = '';
    foreach ($solr_response['docs'] as $solr_doc) {
      $bib = self::scalar_or_first_elem($solr_doc[RR_MARC_001_B]);
      $post .= sprintf($r_mask, self::scalar_or_first_elem($solr_doc[RR_MARC_001_A]), $bib, ($bib == '870970' ? 'MERGED' : 'RAW')) . PHP_EOL;
    }
    $this->curl->set_post(sprintf($p_mask, $post), 0); // use post here because query can be very long
    $this->curl->set_option(CURLOPT_HTTPHEADER, ['Accept:application/xml;', 'Content-Type: text/xml; charset=utf-8'], 0);
    VerboseJson::log(TRACE, array('message' => 'rawrepo_read: ' . $rr_service, 'post' => $post));
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
            $bib_record = self::get_dom_element($record, 'data');
            if (!empty($bib_record) && (!$data = base64_decode($bib_record))) {
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
        if (!empty($data)) @ $dom->loadXml($data);
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
        $ret[$rec_pos] = new stdClass();
        $ret[$rec_pos]->_value = new stdClass();
        $ret[$rec_pos]->_value->collection = new stdClass();
        $ret[$rec_pos]->_value->collection->_value = new stdClass();
        _Object::set_value($ret[$rec_pos]->_value->collection->_value, 'resultPosition', $rec_pos);
        _Object::set_value($ret[$rec_pos]->_value->collection->_value, 'numberOfObjects', 1);
        $ret[$rec_pos]->_value->collection->_value->object = [];
        $ret[$rec_pos]->_value->collection->_value->object[0] = new stdClass();
        $ret[$rec_pos]->_value->collection->_value->object[0]->_value = new stdClass();
        _Object::set_value($ret[$rec_pos]->_value->collection->_value->object[0]->_value, 'collection', $marc_obj);
        _Object::set_namespace($ret[$rec_pos]->_value->collection->_value->object[0]->_value, 'collection', $this->xmlns['marcx']);
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


  /** \brief Fetch a profile $profile_name for agency $agency
   *
   * @param string $agency -
   * @param array $profiles
   * @return mixed - profile (array) or FALSE
   */
  private function fetch_profile_from_agency($agency, $profiles) {
    $this->watch->start('agency_profile');
    $ret = [];
    foreach ($profiles as $profile) {
      $collections = $this->open_agency->get_search_profile($agency, $profile->_value);
      if (!$collections || isset($collections->error)) {
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

  /** \brief Merge two search profiles, extending the first ($sum) with the additions found in the second ($add)
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
      if (@$add[$idx]['relation']) {
        foreach ($add[$idx]['relation'] as $add_rel) {
          $add_rel_to_sum = TRUE;
          if (@$sum[$idx]['relation']) {
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
    if ($agency && empty($agency_rules[$agency])) {
      $this->watch->start('agency_rule');
      $rules = $this->open_agency->get_library_rules($agency);
      if (is_array($rules)) {
        $agency_rules[$agency] = $rules;
      }
      $this->watch->stop('agency_rule');
    }
    return isset($agency_rules[$agency]) ? self::xs_boolean($agency_rules[$agency][$name]) : false;
  }

  /** Initialize OpenAgencyCore (VipCore) with memcached
   *
   * @param $config
   * @return \DBC\VC\OpenAgencyCore|null
   */
  private function initAgencyCore($config) {
    try {
      $cacheMiddleware = null;
      if ($config['memcached']) {
        VerboseJson::log(DEBUG, 'Using memcache for initAgencyCore');
        $memcached = [['url' => $config['memcached']['url'], 'port' => $config['memcached']['port']]];
        $cacheMiddleware = \DBC\VC\CacheMiddleware\MemcachedCacheMiddleware::createCacheMiddleware(
            $memcached, $config['memcached']['expire'], 'OS'
        );
      }
      elseif ($config['redis']) {
        VerboseJson::log(DEBUG, 'Using redis for initAgencyCore');
        $redis = ['url' => $config['redis']['url'], 'port' => $config['redis']['port']];
        $cacheMiddleware = \DBC\VC\CacheMiddleware\PredisCacheMiddleware::createCacheMiddleware(
            $redis, $config['redis']['expire'], 'OS'
        );
      }
      else {
        VerboseJson::log(ERROR, 'No memcached or redis settings for vipCore');
      }
      return new \DBC\VC\OpenAgencyCore(
          $config['url'], $config['timeout'], VerboseJson::$tracking_id, $cacheMiddleware);
    } catch (Error $e) {
      VerboseJson::log(FATAL, 'Error initializing vipCore: ' . $e->getMessage());
    }
    return null;
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

    _Object::set_value($obj, 'identifier', $pid);
    _Object::set_value($obj, 'primaryObjectIdentifier', $primary_pid);
    if ($rs = self::get_record_status($record_repo_dom)) {
      _Object::set_value($obj, 'recordStatus', $rs);
    }
    if ($cd = self::get_creation_date($record_repo_dom)) {
      _Object::set_value($obj, 'creationDate', $cd);
    }
    $drop_holding = empty($obj->creationDate->_value) && (strpos($pid, 'tsart:') || strpos($pid, 'avis:'));
    if (is_array($holdings_res) && (!$drop_holding)) {
      _Object::set_value($obj, 'holdingsCount', $holdings_res['have'] ?? 0);
      _Object::set_value($obj, 'lendingLibraries', $holdings_res['lend'] ?? 0);
    }
    if (isset($param->relationData) && in_array($param->relationData->_value, ['type', 'uri', 'full'])) {
      self::add_external_relations($relations, $raw_res, $param->relationData->_value, $pid);
      self::add_internal_relations($relations, $relation_unit, $rel_res, $param->relationData->_value, $rel_unit_pids);
    }
    if (isset($relations)) {
      _Object::set_value($obj, 'relations', $relations);
    }
    if (DEBUG_ON) {
      echo PHP_EOL . 'relations(' . $pid . '):' . PHP_EOL; var_dump($relations);
    }
    if ($fa = self::scan_for_formats($record_repo_dom)) {
      _Object::set_value($obj, 'formatsAvailable', $fa);
    }
    return $obj;
  }

  /** \brief search units in full profile and collect pids for each unit and create urls to read records with corepo_get
   * The record pointed to via the relation, should allow the relation in the search profile (the record source)
   *
   * @param $relation_units
   * @return array
   */
  private function fetch_valid_relation_records($relation_units) {
    $rel_res = [];
    $rel_unit_pids = [];
    if ($relation_units) {
      VerboseJson::log(DEBUG, 'fetch_valid_relation_records for ' . count($relation_units));
      $relations_in_to_unit = [];
      $rel_query_ids = [];
      foreach ($relation_units as $unit_rels) {
        foreach ($unit_rels as $u_id => $rel) {
          $rel_query_ids[$u_id] = $u_id;
          $relations_in_to_unit[$u_id][$rel] = $rel;
        }
      }
      $this->watch->start('Solr_rel');
      VerboseJson::log(DEBUG, 'fetch_valid_relation_records get relation ids ' . count($rel_query_ids));
      $chunks = array_chunk ( $rel_query_ids, 20 );
      $solr_urls = [];
      $filter_all_q = rawurlencode(self::set_solr_filter($this->search_profile, TRUE));
      foreach ($chunks as $chunk) {
        $query['q'] = [FIELD_UNIT_ID . ':("' . implode('" OR "', $chunk) . '")'];
        $solr_urls[] = self::create_solr_url($query, 0, 99999, $filter_all_q, '', '', '', '');
      }
      if ( count($solr_urls) > 0) {
        if ($err = self::do_solr($solr_urls, $solr_arr)) {
          VerboseJson::log(FATAL, 'Solr error searching relations: ' . $err . ' - query: ' . json_encode($solr_urls));
        }
      }
      // - type skal ikke læse unit'en,
      //   uri skal finde den højst prioriterede (hvis der er mere end en),
      //   full skal læse den højst prioriterede post og hente linkobjectet også.
      $this->watch->stop('Solr_rel');
      // build list of available ids for the unit's
      $rel_unit_pids = [];
      if (isset($solr_arr) && is_array($solr_arr)) {
        // If request only har one URL, result will return the first element
        // Change it to array so foreach code will work
        if ( count($solr_urls) == 1) {
          $solr_arr = [$solr_arr];
        }
        VerboseJson::log(DEBUG, 'fetch_valid_relation_records solr result: ' . count($solr_arr));
        foreach ($solr_arr as $solr_result) {
          if (is_array($solr_result['response']['docs'])) {
            if (DEBUG_ON) {
              echo 'relations_in_to_unit ';
              var_dump($relations_in_to_unit);
            }
            foreach ($solr_result['response']['docs'] as $fdoc) {
              $unit_id = $fdoc[FIELD_UNIT_ID];
              $collections = $fdoc[FIELD_COLLECTION_INDEX];
              $this_relation = key($relations_in_to_unit[$unit_id]);
              foreach ($fdoc[FIELD_REC_ID] as $rec_id) {
                if (self::is_corepo_pid($rec_id) && empty($rel_unit_pids[$unit_id][$rec_id])) {
                  if (DEBUG_ON) {
                    printf('Relation for %s in %s. collections %s', $rec_id, $unit_id, implode(',', $collections));
                  }
                  $debug_no = 'no ';
                  foreach ($relations_in_to_unit[$unit_id] as $rel) {
                    foreach ($collections as $rc) {
                      if (isset($this->valid_relation[$rc][$rel])) {
                        $debug_no = '';
                        $rel_unit_pids[$unit_id][$rec_id] = $rec_id;;
                        break 2;
                      }
                    }
                  }
                  if (DEBUG_ON) { echo ' -> ' . $debug_no . 'go' . PHP_EOL; }
                }
              }
            }
          }
        }
      }
      // reduce identical relations to MAX_IDENTICAL_RELATIONS
      foreach ($relation_units as $u_id_from => $unit_rels) {
        $relation_count = [];
        foreach ($unit_rels as $u_id_to => $rel) {
          if (isset($rel_unit_pids[$u_id_to])) {
            if (!isset($relation_count[$rel])) $relation_count[$rel] = 0;
            if (++$relation_count[$rel] > MAX_IDENTICAL_RELATIONS) {
              unset($rel_unit_pids[$u_id_to]);
            }
          }
        }
      }
      // and build url to fetch.
      $rel_urls = [];
      foreach ($rel_unit_pids as $u_id => $pids) {
        $rel_urls[$u_id] = self::corepo_get_url($u_id, $pids);
      }
      // rel_res indeholder manifestationerne for de af relationerne pegede på unit's
      if ($rel_urls) {
        $rel_res = self::read_record_repo_all_urls($rel_urls);
      }
    }
    return array($rel_res, $rel_unit_pids);
  }

  /** \brief get holdings for the records if needed
   *
   * @param $work_ids
   * @param $include_holdings
   * @param $use_sort_complex_key
   * @param $collect_type
   * @param $unit_sort_keys
   * @return array
   */
  private function collect_holdings($work_ids, $include_holdings, $collect_type, $use_sort_complex_key = FALSE, $unit_sort_keys = []) {
    $this->watch->start('collect_holdings');

    $hold_ws_url = $this->config->get_value('holdings_db', 'setup');
    $holdings_urls = [];
    foreach ($work_ids as &$work) {
      foreach ($work as $unit_id => $pids) {
        if ((isset($include_holdings) && self::xs_boolean(!empty($include_holdings) ? $include_holdings->_value : '')) ||
          ($use_sort_complex_key && isset($unit_sort_keys[$unit_id]) && (strpos($unit_sort_keys[$unit_id], HOLDINGS) !== FALSE))) {
          $key = $collect_type == 'PID' ? reset($pids) : $unit_id;
          $holdings_urls[$key] = sprintf($hold_ws_url, reset($pids));
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
    $this->watch->stop('collect_holdings');

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
        if (!empty($src['sourceContainedIn'])) {
          $this->collection_contained_in[$src['sourceIdentifier']] = $src['sourceContainedIn'];
        }
        if (!empty($src['relation'])) {
          foreach ($src['relation'] as $rel) {
            if (!empty($rel['rdfLabel']))
              $this->valid_relation[$src['sourceIdentifier']][$rel['rdfLabel']] = TRUE;
            if (!empty($rel['rdfInverse']))
              $this->valid_relation[$src['sourceIdentifier']][$rel['rdfInverse']] = TRUE;
          }
        }
      }

      $this->searchable_forskningsbibliotek = isset($this->searchable_source['870970-forsk']) ||
        isset($this->searchable_source['800000-danbib']) ||
        isset($this->searchable_source['800000-bibdk']);
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
   * @return bool
   */
  private function is_corepo_pid($pid) {
    $record_source = self::record_source_from_pid($pid);
    return ((count(explode(':', $pid)) == 2) &&
            (($record_source != '870970-basis') || (count(explode('-', $pid)) == 2)) &&
            (!strpos($pid, $record_source, 7))
    );
  }

  /** \brief Extract source part of an ID
   *
   * @param string $id - NNNNNN-xxxxxxx:nnnnnnn
   * @return string - the record source (NNNNNN-xxxxxxx)
   */
  private function record_source_from_pid($id) {
    @ list($ret, $dummy) = explode(':', $id, 2);
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

    $ret = new stdClass();
    if (($p = $dom->getElementsByTagName('container')->item(0)) || ($p = $dom->getElementsByTagName('localData')->item(0))) {
      foreach ($p->childNodes as $tag) {
        if ($x = &$form_table[$tag->tagName])
          _Object::set_array_value($ret, 'format', $x);
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
    static $ret_rel = [];
    if (empty($dom)) {
      $dom = new DOMDocument();
    }
    if (empty($ret_rel[$pid])) {
      if (!empty($record) && @ !$dom->loadXML($record)) {
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
    $external_relation = null;
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
          _Object::set($lci[], '_value', $collection->nodeValue);
        }
        if ($relation_ok) {
          if (empty($this_relation)) {   // ????? WHY - is relationType sometimes empty?
            _Object::set_value($relation, 'relationType', self::get_dom_element($link, 'access'));
          }
          else {
            _Object::set_value($relation, 'relationType', $this_relation);
          }
          if ($rels_type == 'uri' || $rels_type == 'full') {
            _Object::set_value($relation, 'relationUri', $url);
            $relation->linkObject = new stdClass();
            $relation->linkObject->_value = new stdClass();
            if ($access_type) {
              @ _Object::set_value($relation->linkObject->_value, 'accessType', $access_type);  // ignore PHP warning
            }
            if ($nv = self::get_dom_element($link, 'access')) {
              @ _Object::set_value($relation->linkObject->_value, 'access', $nv);  // ignore PHP warning
            }
            _Object::set_value($relation->linkObject->_value, 'linkTo', self::get_dom_element($link, 'linkTo'));
            if ($lci) {
              $relation->linkObject->_value->linkCollectionIdentifier = $lci;
            }
          }
          $dup_check[$url . $access_type] = TRUE;
          _Object::set_array_value($external_relation, 'relation', $relation);
          unset($relation);
        }
      }
    }
    return $external_relation;
  }

  /** \brief Parse a RELS-EXT stream and return the relations found
   *  NB: Not all unit's have an RELS-EXT stream, so xml load errors are just ignored
   *
   * @param $unit_id - id of unit
   * @param $record_repo_addi_xml - the addi (RELS-EXT) xml record, containing relations for the unit
   * @return array - of relation unit id's
   */
  private function parse_addi_for_units_in_relations($unit_id, $record_repo_addi_xml) {
    static $rels_dom;
    if (empty($rels_dom)) {
      $rels_dom = new DomDocument();
    }
    $relations = [];
    if (!empty($record_repo_addi_xml) && @ $rels_dom->loadXML($record_repo_addi_xml)) {  // ignore errors
      if ($rels_dom->getElementsByTagName('Description')->item(0)) {
        foreach ($rels_dom->getElementsByTagName('Description')->item(0)->childNodes as $tag) {
          if ($tag->nodeType == XML_ELEMENT_NODE) {
            if ($rel_prefix = array_search($tag->getAttribute('xmlns'), $this->xmlns))
              $this_relation = $rel_prefix . ':' . $tag->localName;
            else
              $this_relation = $tag->localName;
            $relations[$tag->nodeValue] = $this_relation;
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
    static $ret_rel = [];
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
          $relation = new stdClass();

          _Object::set_value($relation, 'relationType', $rel_name);
          $relation_pid = reset($rec->pids);
          if ($rels_type == 'uri' || $rels_type == 'full') {
            _Object::set_value($relation, 'relationUri', $relation_pid);
          }
          if ($rels_type == 'full') {
            if (@ !$dom->loadXml($rec->dataStream)) {
              VerboseJson::log(ERROR, 'Cannot load ' . $relation_pid . ' into DomXml');
            }
            else {
              $relation->relationObject = new stdClass();
              $relation->relationObject->_value = new stdClass();
              $relation->relationObject->_value->object = new stdClass();
              $relation->relationObject->_value->object->_value = new stdClass();
              @ $rel_obj = &$relation->relationObject->_value->object->_value;  // ignore PHP warning
              $rel_obj = self::extract_record($dom, $rel_unit);
              _Object::set_value($rel_obj, 'identifier', $relation_pid);
              if ($cd = self::get_creation_date($dom)) {
                _Object::set_value($rel_obj, 'creationDate', $cd);
              }
              $ext_relations = self::extract_external_relation_from_dom($dom, $rels_type);
              if ($ext_relations) {
                _Object::set_value($rel_obj, 'relations', $ext_relations);
                unset($ext_relations);
              }

              if ($fa = self::scan_for_formats($dom)) {
                _Object::set_value($rel_obj, 'formatsAvailable', $fa);
              }
            }
          }
          $ret_rel[$rel_unit] = $relation;
          unset($relation);
        }
      }
      if ($ret_rel[$rel_unit]) {
        _Object::set_array_value($relations, 'relation', $ret_rel[$rel_unit]);
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
    $ret = new stdClass();
    foreach ($this->format as $format_name => $format_arr) {
      switch ($format_name) {
        case 'dkabm':
          if (!$ret) {
            $ret = new stdClass();
          }
          $ret->record = new stdClass();
          $ret->record->_value = new stdClass();
          @ $rec = &$ret->record->_value;  // ignore PHP warning
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
                    _Object::set_namespace($o->_attributes, $attr->localName, $record->item(0)->lookupNamespaceURI($attr->prefix));
                    _Object::set_value($o->_attributes, $attr->localName, $attr->nodeValue);
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
            //FVS
            _Object::set_value($ret, 'collection', $this->xmlconvert->xml2obj($record->item(0), $this->xmlns['marcx']));
            _Object::set_namespace($ret, 'collection', $this->xmlns['marcx']);
            if (is_array($this->repository['filter'])) {
              self::filter_marcxchange($record_source, $ret->collection->_value, $this->repository['filter']);
            }
          }
          break;

        case 'docbook':
          $record = $dom->getElementsByTagNameNS($this->xmlns['docbook'], 'article');
          if ($record->item(0)) {
            _Object::set_value($ret, 'article', $this->xmlconvert->xml2obj($record->item(0)));
            _Object::set_namespace($ret, 'article', $record->item(0)->lookupNamespaceURI('docbook'));
            if (is_array($this->repository['filter'])) {
              self::filter_docbook($record_source, $ret->article->_value, $this->repository['filter']);
            }
          }
          break;
        case 'opensearchobject':
          $record = $dom->getElementsByTagNameNS($this->xmlns['oso'], 'object');
          if ($record->item(0)) {
            _Object::set_value($ret, 'object', $this->xmlconvert->xml2obj($record->item(0)));
            _Object::set_namespace($ret, 'object', $record->item(0)->lookupNamespaceURI('oso'));
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
    $ret = new stdClass();
    if (is_array($solr_arr['facet_counts']['facet_fields'])) {
      foreach ($solr_arr['facet_counts']['facet_fields'] as $facet_name => $facet_field) {
        _Object::set_value($facet, 'facetName', $facet_name);
        foreach ($facet_field as $term => $freq) {
          if (isset($term) && isset($freq)) {
            _Object::set_value($o, 'frequence', $freq);
            _Object::set_value($o, 'term', $term);
            _Object::set_array_value($facet, 'facetTerm', $o);
            unset($o);
          }
        }
        _Object::set_array_value($ret, 'facet', $facet);
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
    return $struct[$r_idx][$par] ?? $struct[$par] ?? '';
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
    $str_bool = is_scalar($str) ? $str : 'false';
    return (strtolower($str_bool) == 'true' || $str_bool == 1);
  }

  /** Log STAT line for search
   *
   */
  private function log_stat_search() {
    $my_out_array = array('query' => $this->user_param->query->_value ?? '',
      'start' => $this->user_param->start->_value ?? '',
      'stepValue' => $this->user_param->stepValue->_value ?? '',
      'userDefinedRanking' => $this->user_param->userDefinedRanking->_value ?? '',
      'userDefinedBoost' => $this->user_param->userDefinedBoost->_value ?? '',
      'sort' => isset($this->user_param->sort) && is_array($this->user_param->sort)
        ? self::stringify_obj_array($this->user_param->sort)
        : $this->user_param->sort->_value ?? '',
      'collectionType' => $this->user_param->collectionType->_value ?? '',
      'facets' => $this->user_param->facets->_value ?? '',
      'corepo' => $this->corepo_timers);
    // If facets, collectionType, userDefinedBoost, or userDefinedRanking are empty, do not log them, because it will
    // break the logging system. See SE-3009
    $remove_empty = array('facets', 'collectionType', 'userDefinedBoost', 'userDefinedRanking');
    foreach ($my_out_array as $key => $val) {
      if (in_array($key, $remove_empty) && ('' == $val)) {
        unset($my_out_array[$key]);
      }
    }
    self::log_stat($my_out_array);
  }

  /** Log STAT line for getObject
   *
   * @param $id_array
   *
   */
  private function log_stat_get_object($id_array) {
    self::log_stat(array('ids' => implode(',', $id_array)));
  }

  /** Log STAT line 
   *
   * @param $extra
   *
   */
  private function log_stat($extra) {
    VerboseJson::log(STAT, array_merge(
                           array('agency' => $this->agency,
                                 'profile' => self::stringify_obj_array($this->user_param->profile),
                                 'repository' => $this->repository_name,
                                 'objectFormat' => isset($this->user_param->objectFormat) ? self::stringify_obj_array($this->user_param->objectFormat) : '',
                                 'outputType' => $this->user_param->outputType->_value ?? '',
                                 'repoTotal' => $this->number_of_record_repo_calls + $this->number_of_record_repo_cached,
                                 'repoRecs' => $this->number_of_record_repo_calls,
                                 'repoCache' => $this->number_of_record_repo_cached,
                                 'callback' => $this->user_param->callback->_value ?? '',
                                 'timings' => $this->watch->get_timers()), 
                            $extra));
  }

  /**
   * @param $arr
   * @param string $glue
   * @return string
   */
  private function stringify_obj_array($arr, $glue = ',') {
    $vals = [];
    if ($arr) {
      foreach ($arr as $val) {
        if (isset($val->_value)) {
          $vals[] = $val->_value;
        }
      }
    }
    return implode($glue, $vals);
  }

  /** Adds a DBC SLA header for the action.
   * @param $action
   *
   */
  private function add_dbc_sla_header_action($action)   {
    header("dbcdk-action: " . ($action ?? 'unknown'));
  }

  /** Adds a DBC SLA header for the agency.
   * @param $agency
   */
  private function add_dbc_sla_header_agency($agency)   {
    header("dbcdk-agency: " . ($agency ?? 'unknown'));
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
    $ret = new stdClass();
    if ($s_profile = self::fetch_profile_from_agency($agency, array($profile))) {
      foreach ($s_profile as $p) {
        _Object::set_value($coll, 'searchCollectionName', $p['sourceName']);
        _Object::set_value($coll, 'searchCollectionIdentifier', $p['sourceIdentifier']);
        _Object::set_value($coll, 'searchCollectionIsSearched', self::xs_boolean($p['sourceSearchable']) ? 'true' : 'false');
        $rels = array();
        if (isset($p['relation']))
          foreach ($p['relation'] as $relation) {
            if ($r = $relation['rdfLabel']) {
              $all_relations[$r] = $r;
              _Object::set($rels[], '_value', $r);
            }
            if (isset($relation['rdfInverse']) && ($r = $relation['rdfInverse'])) {
              $all_relations[$r] = $r;
              _Object::set($rels[], '_value', $r);
            }
          }
        if (isset($rels)) {
          $coll->relationType = $rels;
        }
        if ($rels || self::xs_boolean($p['sourceSearchable'])) {
          @ _Object::set_array_value($ret->_value, 'searchCollection', $coll);  // ignore PHP warning
        }
        unset($rels);
        unset($coll);
      }
      if (is_array($all_relations)) {
        $rels = new StdClass();
        ksort($all_relations);
        foreach ($all_relations as $rel) {
          _Object::set_array_value($rels, 'relationType', $rel);
        }
        _Object::set_value($ret->_value, 'relationTypes', $rels);
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
    $ret = new stdClass();
    foreach ($this->config->get_value('scan_format_table', 'setup') as $name => $value) {
      @ _Object::set_array_value($ret->_value, 'objectFormat', $value);  // ignore PHP warning
    }
    foreach ($this->config->get_value('solr_format', 'setup') as $name => $value) {
      if (empty($value['secret']))
        _Object::set_array_value($ret->_value, 'objectFormat', $name);
    }
    foreach ($this->config->get_value('open_format', 'setup') as $name => $value) {
      _Object::set_array_value($ret->_value, 'objectFormat', $name);
    }
    return $ret;
  }

  /** \brief Get information about repositories from config (info operation)
   *
   * @return object
   */
  private function get_repository_info() {
    $ret = new stdClass();
    $dom = new DomDocument();
    $repositories = $this->config->get_value('repository', 'setup');
    foreach ($repositories as $name => $value) {
      if ($name != 'defaults') {
        _Object::set_value($r, 'repository', $name);
        self::set_repositories($name, FALSE);
        _Object::set_value($r, 'cqlIndexDoc', $this->repository['cql_file']);
        if ($this->repository['cql_settings'] && @ $dom->loadXML($this->repository['cql_settings'])) {
          foreach ($dom->getElementsByTagName('indexInfo') as $index_info) {
            foreach ($index_info->getElementsByTagName('index') as $index) {
              foreach ($index->getElementsByTagName('map') as $map) {
                if ($map->getAttribute('hidden') !== '1') {
                  foreach ($map->getElementsByTagName('name') as $name) {
                    $idx = self::set_name_and_slop($name);
                  }
                  foreach ($map->getElementsByTagName('alias') as $alias) {
                    _Object::set_array_value($idx, 'indexAlias', self::set_name_and_slop($alias));
                  }
                  _Object::set_array_value($r, 'cqlIndex', $idx);
                  unset($idx);
                }
              }
            }
          }
        }
        @ _Object::set_array_value($ret->_value, 'infoRepository', $r);  // ignore PHP warning
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
    _Object::set_value($reg, 'indexName', $prefix . ($prefix ? '.' : '') . $node->nodeValue);
    if ($slop = $node->getAttribute('slop')) {
      _Object::set_value($reg, 'indexSlop', $slop);
    }
    return $reg;
  }

  /** \brief Get information about namespaces from config (info operation)
   *
   * @return object
   */
  private function get_namespace_info() {
    $nss = new stdClass();
    foreach ($this->config->get_value('xmlns', 'setup') as $prefix => $namespace) {
      _Object::set_value($ns, 'prefix', $prefix);
      _Object::set_value($ns, 'uri', $namespace);
      _Object::set_array_value($nss->_value, 'infoNameSpace', $ns);
      unset($ns);
    }
    return $nss;
  }

  /** \brief Get information about sorting and ranking from config (info operation)
   *
   * @return object
   */
  private function get_sort_info() {
    $ret = new stdClass();
    foreach ($this->config->get_value('rank', 'setup') as $name => $val) {
      if (isset($val['word_boost']) && ($help = self::collect_rank_boost($val['word_boost']))) {
        _Object::set($boost, 'word', $help);
      }
      if (isset($val['phrase_boost']) && ($help = self::collect_rank_boost($val['phrase_boost']))) {
        _Object::set($boost, 'phrase', $help);
      }
      if (isset($boost)) {
        _Object::set_value($rank, 'sort', $name);
        _Object::set_value($rank, 'internalType', 'rank');
        _Object::set_value($rank, 'rankDetails', $boost);
        _Object::set_array_value($ret->_value, 'infoSort', $rank);
        unset($boost);
        unset($rank);
      }
    }
    foreach ($this->config->get_value('sort', 'setup') as $name => $val) {
      _Object::set_value($sort, 'sort', $name);
      if (is_array($val)) {
        _Object::set_value($sort, 'internalType', 'complexSort');
        foreach ($val as $simpleSort) {
          _Object::set($simple[], '_value', $simpleSort);
        }
        _Object::set($sortDetails, 'sort', $simple);
        unset($simple);
      }
      else {
        _Object::set_value($sort, 'internalType', ($val == 'random' ? 'random' : 'basicSort'));
        _Object::set_value($sortDetails, 'sort', $val);
      }
      _Object::set_value($sort, 'sortDetails', $sortDetails);
      _Object::set_array_value($ret->_value, 'infoSort', $sort);
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
    $iaw = new stdClass();
    if (is_array($rank)) {
      foreach ($rank as $reg => $weight) {
        _Object::set_value($rw, 'fieldName', $reg);
        _Object::set_value($rw, 'weight', $weight);
        _Object::set_array_value($iaw->_value, 'fieldNameAndWeight', $rw);
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
    //$repositories = $this->config->get_value('repository', 'setup');
    $repos = self::value_or_default(@$_GET['repository'], $this->config->get_value('default_repository', 'setup'));
    self::set_repositories($repos, FALSE);
    if ($file = $this->repository['cql_settings']) {
      header('Content-Type: application/xml; charset=utf-8');
      echo str_replace('<?xml-stylesheet type="text/xsl" href="explain.xsl"?>','<?xml-stylesheet type="text/xsl" href="?showExplainXslFile"?>', $file);
    }
    else {
      header('HTTP/1.0 404 Not Found');
      echo 'Cannot locate the cql-file: ' . $this->repository['cql_file']  . '<br /><br />Use info operation to check name and repository';
    }
  }
  /** \brief fetch a explain.xsl from solr and display it
   *
   * @return void
   */
  protected function showExplainXslFile() {
    //$repositories = $this->config->get_value('repository', 'setup');
    $repos = self::value_or_default(@$_GET['repository'], $this->config->get_value('default_repository', 'setup'));
    self::set_repositories($repos, FALSE);
    if ($file = self::get_solr_file('solr_file', 'explain.xsl')) {
      header('Content-Type: application/xslt+xml; charset=utf-8');
      echo $file;
    }
    else {
      header('HTTP/1.0 404 Not Found');
      echo 'Cannot locate the xsl stylesheet: explain.xsl<br /><br />Use info operation to check name and repository';
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
    $luke_result = self::get_solr_file('solr_luke');
    $this->curl->close();
    if (!$luke_result) {
      die('Cannot fetch register info from solr: ');
    }
    $luke_result = json_decode($luke_result);
    @ $luke_fields = &$luke_result->fields;
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

    echo '<html lang="da"><body><h1>Found in ' . $this->repository['cql_file'] . ' but not in Solr for repository ' . $this->repository_name . '</h1>';
    foreach ($cql_regs as $cr)
      echo $cr . '</br>';
    echo '</br><h1>Found in Solr but not in ' . $this->repository['cql_file'] . ' for repository ' . $this->repository_name . '</h1>';
    if (is_array($luke_fields))
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
