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
define(DEBUG_ON, FALSE);
define(REL_TO_INTERNAL_OBJ, 1);       // relation points to internal object
define(REL_TO_EXTERNAL_OBJ, 2);     // relation points to external object

//-----------------------------------------------------------------------------
class openSearch extends webServiceServer {
    protected $curl;
    protected $repository; // array containing solr and fedora uri's

    public function __construct(){
        webServiceServer::__construct('opensearch.ini');

        if (!$timeout = $this->config->get_value('curl_timeout', 'setup'))
            $timeout = 20;
        $this->curl = new curl();
        $this->curl->set_option(CURLOPT_TIMEOUT, $timeout);
    }

    /** 
        \brief Handles the request and set up the response
    */

    public function search($param) { 
        // set some defines
        $param->trackingId->_value = verbose::set_tracking_id('os', $param->trackingId->_value);
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
        if (empty($param->agency->_value) && empty($param->profile->_value)) {
            $param->agency->_value = $this->config->get_value('agency_fallback', 'setup');
            $param->profile->_value = $this->config->get_value('profile_fallback', 'setup');
        }
        if (empty($param->agency->_value)) {
            $unsupported = 'Error: No agency in request';
        } elseif (empty($param->profile->_value)) {
            $unsupported = 'Error: No profile in request';
        } elseif ($agency = $param->agency->_value) {
            if ($param->profile->_value) {
                if (!($agencies[$agency] = $this->get_agencies_from_profile($agency, $param->profile->_value)))
                    $unsupported = 'Error: Cannot fetch profile: ' . $param->profile->_value . ' for ' . $agency;
            } else
                $agencies = $this->config->get_value('agency', 'agency');
            if (isset($agencies[$agency]))
                $filter_agency = $agencies[$agency];
            else
                $unsupported = 'Error: Unknown agency: ' . $agency;
        }
        $repositories = $this->config->get_value('repository', 'setup');
        if (empty($param->repository->_value))
            $this->repository = $repositories[$this->config->get_value('default_repository', 'setup')];
        elseif (!$this->repository = $repositories[$param->repository->_value])
            $unsupported = 'Error: Unknown repository: ' . $param->repository->_value;

        $use_work_collection = ($param->collectionType->_value <> 'manifestation');
        if (($rr = $param->userDefinedRanking) || ($rr = $param->userDefinedBoost->_value->userDefinedRanking)) {
            $rank = 'rank';
            $rank_user['tie'] = $rr->_value->tieValue->_value;
            
            if (is_array($rr->_value->rankField))
                foreach ($rr->_value->rankField as $rf) {
                    $boost_type = ($rf->_value->fieldType->_value == 'word' ? 'word_boost' : 'phrase_boost');
                    $rank_user[$boost_type][$rf->_value->fieldName->_value] = $rf->_value->weight->_value;
                    $rank .= '_' . $boost_type . '-' . $rf->_value->fieldName->_value . '-' . $rf->_value->weight->_value;
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
        } elseif ($sort = $param->sort->_value) {
            $sort_type = $this->config->get_value('sort', 'setup');
            if (!isset($sort_type[$sort])) $unsupported = 'Error: Unknown sort: ' . $sort;
        } elseif (($rank = $param->rank->_value) || ($rank = $param->userDefinedBoost->_value->rank->_value)) {
            $rank_type = $this->config->get_value('rank', 'setup');
            if (!isset($rank_type[$rank])) $unsupported = 'Error: Unknown rank: ' . $rank;
        }

        if (($boost_str = $this->boostUrl($param->userDefinedBoost->_value->boostField)) && empty($rank)) {
            $rank_type = $this->config->get_value('rank', 'setup');
            $rank = 'rank_none';
        }

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
        $key_relation_cache = md5($param->query->_value . $this->repository . $filter_agency . 
                                  $use_work_collection .  $sort . $rank . $this->version);

        $cql2solr = new cql2solr('opensearch_cql.xml', $this->config);
        // urldecode ???? $query = $cql2solr->convert(urldecode($param->query->_value));
        // ' is handled differently in indexing and searching, so remove it until this is solved
        $query = $cql2solr->convert(str_replace("'", '', $param->query->_value), $rank_type[$rank]);
        //$query = $cql2solr->convert($param->query->_value, $rank_type[$rank]);
        if (!$query['operands']) {
            $error = 'Error: No query found in request';
            return $ret_error;
        }
        if ($sort) {
            $sort_q = '&sort=' . urlencode($sort_type[$sort]);
        }
        

        if ($filter_agency) {
            $filter_q = rawurlencode($filter_agency);
        }
        $rows = ($start + $step_value + 100) * 2;
        if ($param->facets->_value->facetName) {
            $facet_q .= '&facet=true&facet.limit=' . $param->facets->_value->numberOfTerms->_value;
            if (is_array($param->facets->_value->facetName))
                foreach ($param->facets->_value->facetName as $facet_name)
                    $facet_q .= '&facet.field=' . $facet_name->_value;
            else
                $facet_q .= '&facet.field=' . $param->facets->_value->facetName->_value;
        }

        verbose::log(TRACE, 'CQL to SOLR: ' . $param->query->_value . ' -> ' . urldecode($query['solr']));
        if ($query['dismax'])
            verbose::log(TRACE, 'CQL to DISMAX: ' . $param->query->_value . ' -> ' . urldecode($query['dismax']));

        $debug_query = $this->xs_boolean_is_true($param->queryDebug->_value);

        // do the query
        $search_ids = array();
        if ($sort == 'random') {
            if ($err = $this->get_solr_array($query['solr'], 0, 0, '', $facet_q, $filter_q, '', $debug_query, $solr_arr))
                $error = $err;
        } else {
            if ($err = $this->get_solr_array($query['dismax'], 0, $rows, $sort_q, $facet_q, $filter_q, $boost_str, $debug_query, $solr_arr))
                $error = $err;
            else
                foreach ($solr_arr['response']['docs'] as $fpid)
                    $search_ids[] = $fpid['fedoraPid'];
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
        $facets = $this->parse_for_facets(&$solr_arr['facet_counts']);

        $this->watch->start('Build_id');
        $work_ids = $used_search_fids = array();
        if ($sort == 'random') {
            $rows = min($step_value, $numFound);
            $more = $step_value < $numFound;
            for ($w_idx = 0; $w_idx < $rows; $w_idx++) {
                do { $no = rand(0, $numFound-1); } while (isset($used_search_fid[$no]));
                $used_search_fid[$no] = TRUE;
                $this->get_solr_array($query['solr'], $no, 1, '', '', $filter_q, '', $debug_query, $solr_arr);
                $work_ids[] = array($solr_arr['response']['docs'][0]['fedoraPid']);
            }
        } 
        else {
            $cache = new cache($this->config->get_value('cache_host', 'setup'), 
                            $this->config->get_value('cache_port', 'setup'), 
                            $this->config->get_value('cache_expire', 'setup'));
            if (empty($_GET['skipCache'])) {
                if ($relation_cache = $cache->get($key_relation_cache)) {
                    verbose::log(STAT, 'Cache hit, lines: ' . count($relation_cache));
                }                
                else {
                    verbose::log(STAT, 'Cache miss');
                }
            }
    
            $w_no = 0;

            if (DEBUG_ON) print_r($search_ids);

            for ($s_idx = 0; isset($search_ids[$s_idx]); $s_idx++) {
                $fid = &$search_ids[$s_idx];
                if (!isset($search_ids[$s_idx+1]) && count($search_ids) < $numFound) {
                    $this->watch->start('Solr_add');
                    verbose::log(FATAL, 'To few search_ids fetched from solr. Query: ' . urldecode($query['solr']));
                    $rows *= 2;
                    if ($err = $this->get_solr_array($query['dismax'], 0, $rows, $sort_q, '', $filter_q, $boost_str, $debug_query, $solr_arr)) {
                        $error = $err;
                        return $ret_error;
                    } 
                    else {
                        $search_ids = array();
                        foreach ($solr_arr['response']['docs'] as $fpid)
                            $search_ids[] = $fpid['fedoraPid'];
                        $numFound = $solr_arr['response']['numFound'];
                    }
                    $this->watch->stop('Solr_add');
                }
                if ($used_search_fids[$fid]) continue;
                if (count($work_ids) >= $step_value) {
                    $more = TRUE;
                    break;
                }
  
                $w_no++;
                // find relations for the record in fedora
                if ($relation_cache[$w_no]) {
                    $fid_array = $relation_cache[$w_no];
                } else {
                    if ($use_work_collection) {
                        $this->watch->start('get_w_id');
                        $record_uri =  sprintf($this->repository['fedora_get_rels_ext'], $fid);
                        $record_result = $this->curl->get($record_uri);
                        $curl_err = $this->curl->get_status();
                        /* ignore the fact that there is no RELS_EXT datastream
                        *          if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
                        *            $error = 'Error: Cannot fetch record: ' . $fid . ' - http-error: ' . $curl_err['http_code'];
                        *            verbose::log(FATAL, 'Fedora http-error: ' . $curl_err['http_code'] . ' ' . $curl_err['error'] . ' from: ' . $record_uri);
                        *            return $ret_error;
                        *          }
                        */
                        $this->watch->stop('get_w_id');
  
                        if ($work_id = $this->parse_rels_for_work_id($record_result)) {
                            // find other recs sharing the work-relation
                            $this->watch->start('get_fids');
                            $work_uri = sprintf($this->repository['fedora_get_rels_ext'], $work_id);
                            $work_result = $this->curl->get($work_uri);
                            if (DEBUG_ON) echo $work_result;
                            $this->watch->stop('get_fids');
                            if (!$fid_array = $this->parse_work_for_fedora_id($work_result, $fid)) {
                              verbose::log(FATAL, 'Fedora fetch/parse record: ' . $work_uri . ' refered from: ' . $record_uri);
                              $fid_array = array($fid);
                            }
                            if ($_REQUEST['work'] == 'debug') {  
                                echo 'fid: ' . $fid . ' -> ' . $work_id . ' ' . 
                                     $work_uri . " with manifestations:\n"; print_r($fid_array);
                            }
                        } else 
                            $fid_array = array($fid);
                    } else
                        $fid_array = array($fid);
                    $relation_cache[$w_no] = $fid_array;
                }
                if (DEBUG_ON) print_r($fid_array);

                foreach ($fid_array as $id) {
                    $used_search_fids[$id] = TRUE;
                    if ($w_no >= $start) 
                        $work_ids[$w_no][] = $id;
                }
                if ($w_no >= $start) 
                    $work_ids[$w_no] = $fid_array;
            }
        }

        if (count($work_ids) < $step_value && count($search_ids) < $numFound) {
            verbose::log(FATAL, 'To few search_ids fetched from solr. Query: ' . urldecode($query['solr']));
        }

        // check if the search result contains the ids
        // allObject=0 - remove objects not included in the search result
        // allObject=1 & agency - remove objects not included in agency
        if ($use_work_collection) {
            $add_query = '';
            foreach ($work_ids as $w_no => $w) {
                if (count($w) > 1) {
                    foreach ($w as $id) {
                        $add_query .= (empty($add_query) ? '' : ' OR ') . str_replace(':', '\:', $id);
                    }
                }
            }
            if (!empty($add_query)) {     // use post here because query can be very long
                if (!$this->xs_boolean_is_true($param->allObjects->_value))
                    $q = '(' . urldecode($query['solr']) . ') AND rec.id:(' . $add_query . ')';
                elseif ($filter_agency)
                    $q = urldecode('rec.id:(' . $add_query . ') ');
                else
                    $q = '';
                if ($q) {			
                    // need to remove unwanted object from work_ids
                    $this->curl->set_post(array('wt' => 'phps',
                                                'q' => $q,
                                                'fq' => urldecode($filter_q),
                                                'start' => '0',
                                                'rows' => '50000',
                                                'fl' => 'fedoraPid'));
                    $this->watch->start('Solr 2');
                    $solr_result = $this->curl->get($this->repository['solr']);
                    $this->watch->stop('Solr 2');
                    if (!$solr_2_arr = unserialize($solr_result)) {
                        verbose::log(FATAL, 'Internal problem: Cannot decode Solr re-search');
                        $error = 'Internal problem: Cannot decode Solr re-search';
                        return $ret_error;
                    }
                    foreach ($work_ids as $w_no => $w_list) {
                        if (count($w_list) > 1) {
                            $hit_fid_array = array();
                            foreach ($w_list as $w)
                              foreach ($solr_2_arr['response']['docs'] as $fpid)
                                if ($fpid['fedoraPid'] == $w) {
                                    $hit_fid_array[] = $w;
                                    break;
                                }
                            $work_ids[$w_no] = $hit_fid_array;
                        }
                    }
                }
            }
        }


        if (DEBUG_ON) echo 'txt: ' . $txt . "\n";
        if (DEBUG_ON) print_r($solr_2_arr);
        if (DEBUG_ON) print_r($add_query);
        if (DEBUG_ON) print_r($used_search_fids);

        $this->watch->stop('Build_id');

        if ($cache)
            $cache->set($key_relation_cache, $relation_cache);

        if (DEBUG_ON) echo 'work_ids: '. "\n";
        if (DEBUG_ON) print_r($work_ids);
        
        // work_ids now contains the work-records and the fedoraPids they consist of
        // now fetch the records for each work/collection
        $this->watch->start('get_recs');
        $collections = array();
        $rec_no = max(1, $start);
        foreach ($work_ids as $work) {
            $objects = array();
            foreach ($work as $fid) {
                $work_uri = sprintf($this->repository['fedora_get_rels_ext'], $work_id);
                $fedora_get =  sprintf($this->repository['fedora_get_raw'], $fid);
                $fedora_result = $this->curl->get($fedora_get);
                $curl_err = $this->curl->get_status();
                //verbose::log(TRACE, 'Fedora get: ' . $fedora_get);
                verbose::log(DEBUG, 'SFID: ' . $fid);
                if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
                    $error = 'Error: Cannot fetch record: ' . $fid . ' - http-error: ' . $curl_err['http_code'];
                    verbose::log(FATAL, 'Fedora http-error: ' . $curl_err['http_code'] . ' from: ' . $fedora_get);
                    return $ret_error;
                }
                if ($this->xs_boolean_is_true($param->allRelations->_value)) {
                    //verbose::log(TRACE, 'rels_ext: ' . sprintf($this->repository['fedora_get_rels_ext'], $fid));
                    $fedora_relation = $this->curl->get(sprintf($this->repository['fedora_get_rels_ext'], $fid));
                }
                if ($debug_query) {
                    unset($explain);
                    foreach ($solr_arr['response']['docs'] as $solr_idx => $solr_rec) {
                        if ($fid == $solr_rec['fedoraPid']) {
                            $strange_idx = $solr_idx ? ' '.$solr_idx : '';
                            $explain = $solr_arr['debug']['explain'][$strange_idx];
                            break;
                        }
                    }
                  
                }
                $objects[]->_value = 
                    $this->parse_fedora_object(&$fedora_result, 
                                               &$fedora_relation, 
                                               $param->relationData->_value,
                                               $fid, 
                                               NULL, // no $filter_agency on search - bad performance
                                               $param->format->_value, 
                                               $this->xs_boolean_is_true($param->includeMarcXchange->_value), 
                                               $explain);
            }
            $o->collection->_value->resultPosition->_value = $rec_no++;
            $o->collection->_value->numberOfObjects->_value = count($objects);
            $o->collection->_value->object = $objects;
            $collections[]->_value = $o;
            unset($o);
        }
        $this->watch->stop('get_recs');


        if ($_REQUEST['work'] == 'debug') {  
            echo "returned_work_ids: \n"; print_r($work_ids); echo "cache: \n"; print_r($relation_cache); die();
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
        $result->debugResult->_value = $debug_result;
        $result->time->_value = $this->watch->splittime('Total');
    
        //print_r($collections[0]);
        //exit;
    
        return $ret;
    }

            
    /** \brief Get an object in a specific format
    *
    * param: identifier - fedora pid
    *        objectFormat - one of dkabm, docbook, marcxchange, opensearchobject
    *        allRelations - boolean
    *        includeMarcXchange - boolean
    *        relationData - type, uri og full
    *        repository
    */
    public function getObject($param) { 
        $param->trackingId->_value = verbose::set_tracking_id('os', $param->trackingId->_value);
        $ret_error->searchResponse->_value->error->_value = &$error;
        if (!$this->aaa->has_right('opensearch', 500)) {
            $error = 'authentication_error';
            return $ret_error;
        }
        if (empty($param->agency->_value) && empty($param->profile->_value)) {
            $param->agency->_value = $this->config->get_value('agency_fallback', 'setup');
            $param->profile->_value = $this->config->get_value('profile_fallback', 'setup');
        }
        if ($agency = $param->agency->_value) {
            if ($param->profile->_value) {
                if (!($agencies[$agency] = $this->get_agencies_from_profile($agency, $param->profile->_value))) {
                    $error = 'Error: Cannot fetch profile: ' . $param->profile->_value . ' for ' . $agency;
                    return $ret_error;
                }
            } else
                $agencies = $this->config->get_value('agency', 'agency');
            if (isset($agencies[$agency]))
                $filter_agency = $agencies[$agency];
            else {
                $error = 'Error: Unknown agency: ' . $agency;
                return $ret_error;
            }
        }
        $repositories = $this->config->get_value('repository', 'setup');
        if (empty($param->repository->_value))
            $this->repository = $repositories[$this->config->get_value('default_repository', 'setup')];
        elseif (!$this->repository = $repositories[$param->repository->_value]) {
            $error = 'Error: Unknown repository: ' . $param->repository->_value;
            verbose::log(FATAL, $error);
            return $ret_error;
        }
        $fid = $param->identifier->_value;
        $record_uri =  sprintf($this->repository['fedora_get_raw'], $fid);
        verbose::log(DEBUG, 'GFID: ' . $fid);
        $fedora_result = $this->curl->get($record_uri);
        $curl_err = $this->curl->get_status();
        if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
            $error = 'Error: Cannot fetch record: ' . $fid . ' - http-error: ' . $curl_err['http_code'];
            verbose::log(FATAL, 'Fedora http-error: ' . $curl_err['http_code'] . ' from: ' . $fedora_get);
            return $ret_error;
        }
        if ($this->xs_boolean_is_true($param->allRelations->_value))
            $fedora_relation = $this->curl->get(sprintf($this->repository['fedora_get_rels_ext'], $fid));
        $format = &$param->objectFormat->_value;
        $o->collection->_value->resultPosition->_value = 1;
        $o->collection->_value->numberOfObjects->_value = 1;
        $o->collection->_value->object[]->_value = 
            $this->parse_fedora_object(&$fedora_result, 
                                       &$fedora_relation, 
                                       $param->relationData->_value, 
                                       $fid, 
                                       $filter_agency, 
                                       $format, 
                                       $this->xs_boolean_is_true($param->includeMarcXchange->_value));
        $collections[]->_value = $o;
    
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

    /** \brief Fetch a profile $profile_name for agency $agency and build Solr filter_query parm
     *
     */
    private function get_agencies_from_profile($agency, $profile_name) {
        require_once 'OLS_class_lib/search_profile_class.php';
        if (!($host = $this->config->get_value('profile_cache_host', 'setup')))
            $host = $this->config->get_value('cache_host', 'setup');
        if (!($port = $this->config->get_value('profile_cache_port', 'setup')))
            $port = $this->config->get_value('cache_port', 'setup');
        if (!($expire = $this->config->get_value('profile_cache_expire', 'setup')))
            $expire = $this->config->get_value('cache_expire', 'setup');
        $profiles = new search_profiles($this->config->get_value('open_agency', 'setup'), $host, $port, $expire);
        $profile = $profiles->get_profile($agency, $profile_name);
        if (! is_array($profile))
            return FALSE;
        $ret = '';
        foreach ($profile as $p)
          $ret .= ($ret ? ' OR ' : '') . 
                  '(submitter:' . $p['sourceOwner'] .  ' AND original_format:' . $p['sourceFormat'] . ')';
        return $ret;
    }

    /** \brief Build bq (BoostQuery) as field:content^weight
     *
     */
    public static function boostUrl($boost) {
        $ret = '';
        if ($boost) {
            $boosts = (is_array($boost) ? $boost : array($boost));
            foreach ($boosts as $bf)
                $ret .= '&bq=' . 
                        urlencode($bf->_value->fieldName->_value . ':"' . 
                                  str_replace('"', '', $bf->_value->fieldValue->_value) . '"^' . 
                                  $bf->_value->weight->_value);
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
    private function get_solr_array($q, $start, $rows, $sort, $facets, $filter, $boost, $debug, &$solr_arr) {
        $solr_query = $this->repository['solr'] . "?wt=phps&q=$q&fq=$filter&start=$start&rows=$rows$sort$boost&fl=fedoraPid$facets" . ($debug ? '&debugQuery=on' : '');
    
          //echo $solr_query;
        //exit;
    
        verbose::log(TRACE, 'Query: ' . $solr_query);
        verbose::log(DEBUG, 'Query: ' . $this->repository['solr'] . "?q=$q&fq=$filter&start=$start&rows=1$sort$boost&fl=fedoraPid$facets&debugQuery=on");
        $solr_result = $this->curl->get($solr_query);
        if (empty($solr_result))
            return 'Internal problem: No answer from Solr';
        if (!$solr_arr = unserialize($solr_result))
            return 'Internal problem: Cannot decode Solr result';
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
        if (@ $dom->loadXML($rels_ext)) {
            $imo = $dom->getElementsByTagName('isMemberOfWork');
            if ($imo->item(0)) {
                return($imo->item(0)->nodeValue);
            }
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

    /** \brief Parse a work relation and return array of ids
     *
     */
    private function parse_work_for_fedora_id($w_rel, $fid) {
        static $dom;
        $res = array();
        if (empty($dom)) {
            $dom = new DomDocument();
        }
        $dom->preserveWhiteSpace = false;
        if (@ $dom->loadXML($w_rel)) {
            $r_list = $dom->getElementsByTagName('hasManifestation');
            $res[] = $fid;
            foreach ($r_list as $r) {
                if ($r->nodeValue <> $fid) $res[] = $r->nodeValue;
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
     * @param $include_marcx   -
     * @param $debug_info      -
     */
    private function parse_fedora_object(&$fedora_obj, $fedora_rels_obj, $rels_type, $rec_id, $filter, $format, $include_marcx=FALSE, $debug_info='') {
        static $dom, $rels_dom, $allowed_relation;
        if (empty($format)) {
            $format = 'dkabm';
        }
        if (empty($dom)) {
            $dom = new DomDocument();
        }
        $dom->preserveWhiteSpace = false;
        if (@ !$dom->loadXML($fedora_obj)) {
            verbose::log(FATAL, 'Cannot load recid ' . $rec_id . ' into DomXml');
            return;
        }
  
        $rec = $this->extract_record($dom, $rec_id, $format, $include_marcx);

        if ($fedora_rels_obj) {
            if (!isset($allowed_relation)) {
                $allowed_relation = $this->config->get_value('relation', 'setup');
            }
            if (empty($rels_dom)) {
                $rels_dom = new DomDocument();
            }
            if (@ !$rels_dom->loadXML($fedora_rels_obj)) {
                verbose::log(FATAL, 'Cannot load RELS_EXT for ' . $rec_id . ' into DomXml');
            } elseif ($rels_dom->getElementsByTagName('Description')->item(0)) {
                foreach ($rels_dom->getElementsByTagName('Description')->item(0)->childNodes as $tag) {
                    if ($tag->nodeType == XML_ELEMENT_NODE && $allowed_relation[$tag->tagName]) { 
                        //verbose::log(TRACE, $tag->localName . ' ' . $tag->getAttribute('xmlns'). ' -> ' .  array_search($tag->getAttribute('xmlns'), $this->xmlns));
                        if ($allowed_relation[$tag->tagName] <> REL_TO_INTERNAL_OBJ
                          || $this->is_searchable($tag->nodeValue, $filter)) {
                            if ($rel_prefix = array_search($tag->getAttribute('xmlns'), $this->xmlns))
                                $rel_prefix .= ':';
                            $relation->relationType->_value = $rel_prefix . $tag->localName;
                            if ($rels_type == 'uri' || $rels_type == 'full')
                                $relation->relationUri->_value = $tag->nodeValue;
                            if ($rels_type == 'full' && $allowed_relation[$tag->tagName] == REL_TO_INTERNAL_OBJ) {
                                verbose::log(DEBUG, 'RFID: ' . $tag->nodeValue);
                                $related_obj = $this->curl->get(sprintf($this->repository['fedora_get_raw'], $tag->nodeValue));
                                if (@ !$rels_dom->loadXML($related_obj)) {
                                    verbose::log(FATAL, 'Cannot load ' . $tag->tagName . ' object for ' . $rec_id . ' into DomXml');
                                } else {
                                    $rel_obj = &$relation->relationObject->_value->object->_value;
                                    $rel_obj = $this->extract_record($rels_dom, $tag->nodeValue, $format, $include_marcx);
                                    $rel_obj->identifier->_value = $tag->nodeValue;
                                    $rel_obj->formatsAvailable->_value = $this->scan_for_formats($rels_dom);
                                }
                            } 
                            $relations->relation[]->_value = $relation;
                            unset($relation);
                        }
                    }
                    //print_r($relations);
                    //echo $rels;
                }
            }
        }

        $ret = $rec;
        $ret->identifier->_value = $rec_id;
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
        if (empty($filter_q)) return TRUE;

        $this->get_solr_array('rec.id:'.str_replace(':', '\:', $rec_id), 1, 0, '', '', rawurlencode($filter_q), '', '', $solr_arr);
        return $solr_arr['response']['numFound'];
    }

    /** \brief Check rec for available formats
     *
     */
    private function scan_for_formats(&$dom) {
        static $form_table;
        if (!isset($form_table)) {
            $form_table = $this->config->get_value('scan_format_table', 'setup');
        }
        
        if ($p = &$dom->getElementsByTagName('container')->item(0))
          foreach ($p->childNodes as $tag)
              if ($x = &$form_table[$tag->tagName])
                  $ret->format[]->_value = $x;

        return $ret;
    }

    /** \brief Extract record and namespace for it
     *
     */
    private function extract_record(&$dom, $rec_id, $format, $include_marcx=FALSE) {
        switch ($format) {
        case 'dkabm':
            $rec = &$ret->record->_value;
            $record = &$dom->getElementsByTagName('record');
            if ($record->item(0)) {
                $ret->record->_namespace = $record->item(0)->lookupNamespaceURI('dkabm');
            }
            if ($record->item(0))
                foreach ($record->item(0)->childNodes as $tag) {
                    if ($format == 'dkabm' || $tag->prefix == 'dc') {
                        if (trim($tag->nodeValue)) {
                            if ($tag->hasAttributes())
                                foreach ($tag->attributes as $attr) {
                                    $o->_attributes->{$attr->localName}->_namespace = $record->item(0)->lookupNamespaceURI($attr->prefix);
                                    $o->_attributes->{$attr->localName}->_value = $attr->nodeValue;
                                }
                            $o->_namespace = $record->item(0)->lookupNamespaceURI($tag->prefix);
                            $o->_value = $this->char_norm(trim($tag->nodeValue));
                            if (!($tag->localName == 'subject' && $tag->nodeValue == 'undefined'))
                                $rec->{$tag->localName}[] = $o;
                            unset($o);
                        }
                    }
                }
            else
                verbose::log(FATAL, 'No dkabm record found in ' . $rec_id);

            if (! $include_marcx)   // include marcx-record below?
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
        return $ret;
    }

    private function char_norm($s) {
        $from[] = "\xEA\x9C\xB2"; $to[] = 'Aa';
        $from[] = "\xEA\x9C\xB3"; $to[] = 'aa';
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
        if ($facets['facet_fields'])
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
        return $ret;
    }

   /** \brief
    *  return true if xs:boolean is so
    */
    private function xs_boolean_is_true($str) {
        return (strtolower($str) == "true" || $str == 1);
    }

}

/*
 * MAIN
 */

if (!defined('PHPUNIT_RUNNING')) {
    $ws=new openSearch();

    $ws->handle_request();
}
?>

