<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2013, Dansk Bibliotekscenter a/s,
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


require_once 'OLS_class_lib/memcache_class.php';

/**
 * \brief Class for formatting records from fedora object repository
 *   records are split according to record_blocking and send to one or more formating servers
 *
 * Usage: \n
 *    $formater = new formatRecords($config_array, $namespace, $objconverter, $xmlconverter [, $timer]);
 * $formatted_records = $formater->format($records, $param);
 *
 * Example:
 *   $formatRecords = new formatRecords($this->config->get_section('setup'), $this->xmlns['of'], $this->objconvert, $this->xmlconvert, $this->watch);
 *   $formatted = $formatRecords->format($form_req, $param);
 *
 * @author Finn Stausgaard - DBC
 */
class FormatRecords {
  protected $cache;                     ///< for caching formatted records
  protected $curl;                      ///< the curl connection
  protected $namespace;                 ///< the namespace for openformat
  protected $objconvert;                ///< OLS object to xml convert
  protected $xmlconvert;                ///< xml to OLS object convert
  protected $watch;                     ///< timer object
  protected $record_blocking = 1;       ///< block factor: number of records in each request to js_server
  protected $js_server_url = array();   ///< if more than one, the formatting requests will be split amongst them
  protected $rec_status = array();      ///< curl_status for each record formattet
  protected $timeout = 5;               ///< -

  /** \brief
   * @param $setup object
   * @param $namespace string
   * @param $objconvert class
   * @param $xmlconvert class
   * @param $watch class optional
   */
  public function __construct($setup, $namespace, &$objconvert, &$xmlconvert, &$watch = NULL) {
    $this->curl = new curl();
    foreach ($setup['js_server'] as $url) {
      $this->js_server_url[] = $url;
    }
    if (!($this->timeout = (integer)$setup['curl_timeout'])) {
      $this->timeout = 5;
    }
    // Since the api for record_blocking is not defined, it will be set to 1
    //  if (!($this->record_blocking = (integer) $setup['record_blocking'])) {
    $this->record_blocking = 1;
    //  }
    $this->cache = new cache($setup['cache_host'], $setup['cache_port'], $setup['cache_expire']);

    $this->curl = new curl();
    $this->namespace = $namespace;
    $this->objconvert = $objconvert;
    $this->xmlconvert = $xmlconvert;
    $this->watch = $watch;
  }

  /** \brief
   * @param $records array Records to format
   * @param $param object User given parameters
   * @return array - of formatted records
   */
  public function format($records, $param) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = false;
    }
    $output_format = $param->outputFormat->_value;
    $form_req = new stdClass();
    $form_req->formatSingleManifestationRequest = new stdClass();
    $form_req->formatSingleManifestationRequest->_value = new stdClass();
    $form_req->formatSingleManifestationRequest->_value->agency = $param->agency;
    $form_req->formatSingleManifestationRequest->_value->holdBackEndDate = $param->holdBackEndDate;
    $form_req->formatSingleManifestationRequest->_value->language = $param->language;
    $form_req->formatSingleManifestationRequest->_value->outputFormat = $param->outputFormat;
    $form_req->formatSingleManifestationRequest->_value->trackingId = $param->trackingId;
    if (isset($param->customDisplay)) {
      $form_req->formatSingleManifestationRequest->_value->customDisplay = $param->customDisplay;
    }
    $ret = array();
    $ret_index = array();  // to make future caching easier
    $curls = 0;
    $tot_curls = 0;
    $next_js_server = rand(0, count($this->js_server_url) - 1);
    for ($no = 0; $no < count($records); $no = $no + $this->record_blocking) {
      $cache_key[$curls] = self::make_cache_key($records[$no]->_value->collection->_value->object, $param);
      if ($ret[$no] = $this->cache->get($cache_key[$curls])) {
        self::local_verbose(DEBUG, 'format cache hit ' . $cache_key[$curls]);
        continue;
      }
      self::local_verbose(DEBUG, 'no format cache hit');
      $ret_index[$curls] = $no;
      $form_req->formatSingleManifestationRequest->_value->originalData = &$records[$tot_curls];
      $this->curl->set_option(CURLOPT_TIMEOUT, $this->timeout, $curls);
      $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: text/xml; charset=UTF-8'), $curls);
      $this->curl->set_url($this->js_server_url[$next_js_server], $curls);
      $rec = $this->objconvert->obj2xmlNs($form_req);
      $this->curl->set_post_xml($rec, $curls);
      $curls++;
      $tot_curls++;
      if ($curls == count($this->js_server_url) || $tot_curls == count($records)) {
        if (is_object($this->watch)) {
          $this->watch->start('js_server');
        }
        $js_result = $this->curl->get();
        $curl_status = $this->curl->get_status();
        if (is_object($this->watch)) {
          $this->watch->stop('js_server');
        }
        if ($curl_status['url']) $curl_status = array($curl_status);
        if (!is_array($js_result)) $js_result = array($js_result);
        for ($i = 0; $i < $curls; $i++) {
          $this->rec_status[] = $curl_status[$i];
          if ($curl_status[$i]['http_code'] == 200) {
            if (@ $dom->loadXML($js_result[$i])) {
              $js_obj = $this->xmlconvert->xml2obj($dom);
            }
            else {
              $error = 'Error formatting record - no valid response';
            }
          }
          else {
            self::local_verbose(ERROR, 'http code: ' . $curl_status[$i]['http_code'] .
                              ' error: "' . $curl_status[$i]['error'] .
                              '" for: ' . $curl_status[$i]['url'] .
                              ' TId: ' . $param->trackingId->_value);
            $error = 'HTTP error ' . $curl_status[$i]['http_code'] . ' . formatting record';
          }
          if ($error) {
            $js_obj = new stdClass();
            $js_obj->{$output_format} = new stdClass();
            $js_obj->{$output_format}->_value = new stdClass();
            $js_obj->{$output_format}->_value->error = new stdClass();
            $js_obj->{$output_format}->_namespace = $this->namespace;
            $js_obj->{$output_format}->_value->error->_value = $error;
            $js_obj->{$output_format}->_value->error->_namespace = $this->namespace;
            unset($error);
          }
          $this->cache->set($cache_key[$i], $js_obj);
          $ret[$ret_index[$i]] = $js_obj;
        }
        $curls = 0;
      }
      $next_js_server = ++$next_js_server % count($this->js_server_url);
    }
    return $ret;
  }

  /** \brief setters
   * @param $count integer
   */
  public function set_record_blocking($count) {
    $this->record_blocking = (integer)$count;
  }

  /** \brief setters
   * @param $seconds integer
   */
  public function set_timeout($seconds) {
    $this->timeout = (integer)$seconds;
  }

  /** \brief getters
   * @return array of status
   *
   */
  public function get_status() {
    return $this->rec_status;
  }

  /** \brief generate hash key from the record and the user params
   * @param $record object the records to format
   * @param $param object the usergiven parameters
   * @return string cache key for the object
   */
  private function make_cache_key(&$record, &$param) {
    if (is_array($record)) {
      foreach ($record as &$man) {
        $key .= $man->_value->identifier->_value . '_';
      }
    }
    else {
      $key = $record->_value->identifier->_value . '_';
    }
    return 'OF_' . md5($key .
                       $param->agency->_value . '_' .
                       $param->holdBackEndDate->_value . '_' .
                       $param->language->_value . '_' .
                       $param->outputFormat->_value);
  }

  /** \brief -
   * @param string $level
   * @param string $msg
   */
  private function local_verbose($level, $msg) {
    if (method_exists('VerboseJson', 'log')) {
      VerboseJson::log($level, $msg);
    }
    elseif (method_exists('verbose', 'log')) {
      verbose::log($level, $msg);
    }
  }
}
