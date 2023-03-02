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
 **/

/**
 * \brief AAA Authentication, Access control and Accounting
 *
 * only the first two A's are supported, since there is currently no need for accounting
 *
 * need oci_class and memcache_class to be defined
 *
 * if aaa_fors_right is defined, then data is fetched from the webservice defined by the parameter
 *
 * @author Finn Stausgaard - DBC
 **/

require_once('class_lib/memcache_class.php');
require_once('class_lib/ip_class.php');

/**
 * Class aaa
 */
#[AllowDynamicProperties]
class aaa {

  private $aaa_cache;        // cache object
  private $cache_seconds;      // number of seconds to cache
  private $cache_key_prefix;
  private $error_cache_seconds;  // number of seconds to cache answer after an error
  private $ip_rights;          // array with repeated elements: ip_list, ressource
  private $fors_credentials;    // oci login credentiales
  private $rights;        // the rights
  private $user;            // User if any
  private $group;            // Group if any
  private $password;        // Password if any
  private $ip;            // IP address
  private $fors_rights_url;     // url to forsRights server
  public $aaa_ip_groups = array();

  /**
   * aaa constructor.
   * @param array $aaa_setup
   * @param string $hash
   */
  public function __construct($aaa_setup, $hash = '') {
    $this->fors_credentials = self::set_or_not($aaa_setup, 'aaa_credentials');
    if (self::set_or_not($aaa_setup, 'aaa_cache_address')) {
      $this->aaa_cache = new cache($aaa_setup['aaa_cache_address']);
      if (!$this->cache_seconds = self::set_or_not($aaa_setup, 'aaa_cache_seconds', 0))
        $this->cache_seconds = 3600;
      $this->error_cache_seconds = 60;
    }
    $this->fors_rights_url = self::set_or_not($aaa_setup, 'aaa_fors_rights');
    $this->dbcidp_rights_url = self::set_or_not($aaa_setup, 'aaa_dbcidp_rights');
    $this->ip_rights = self::set_or_not($aaa_setup, 'aaa_ip_rights');
    $this->cache_key_prefix = md5($hash . json_encode($aaa_setup));
  }

  /**
   * \brief sets a list of ressources and the right atributes of each
   *
   * @param string $user login name
   * @param string $group login group
   * @param string $passw login password
   * @param integer|string $ip the users ip-address
   *
   * @returns TRUE if users has some rights
   **/
  public function init_rights($user, $group, $passw, $ip = 0) {
    $this->user = $user;
    $this->group = $group;
    $this->password = $passw;
    $this->ip = $ip;
    if ($this->aaa_cache) {
      $cache_key = $this->cache_key_prefix . '_' . md5($this->user . '_' . $this->group . '_' . $this->password . '_' . $this->ip);
      if ($rights = $this->aaa_cache->get($cache_key)) {
        $this->rights = json_decode($rights);
        return !empty($this->rights);
      }
    }

    if ($this->rights = $this->fetch_rights_from_ip_rights($this->ip, $this->ip_rights)) {
      return TRUE;         // do no cache when found in ip-rights (ini-file)
    }

    if ($this->dbcidp_rights_url) {
      $this->rights = $this->fetch_rights_from_dbcidp_rights_ws($this->user, $this->group, $this->password, $this->dbcidp_rights_url);
    }
    elseif ($this->fors_rights_url) {
      die('deprecated behaviour using OCI interface');
    }

    if ($this->aaa_cache)
      $this->aaa_cache->set($cache_key, json_encode($this->rights), (isset($error) ? $this->error_cache_seconds : $this->cache_seconds));
    return !empty($this->rights);
  }

  /**
   * \brief returns TRUE if user has $right to $ressource
   *
   * @param string $ressource
   * @param integer $right
   *
   * @returns boolean
   **/
  public function has_right($ressource, $right) {
    return ($this->rights->$ressource->$right == TRUE);
  }


  /**
   * \brief Register $operation on $ressource
   *
   * @param string $ressource
   * @param string $operation
   *
   * @returns boolean
   **/
  public function accounting($ressource, $operation) {
    return TRUE;
  }

  /**
   * \brief set the rights array from DBCIDP webservice
   *
   * @param $user
   * @param $group
   * @param $password
   * @param $dbcidp_rights_url
   */
  private function fetch_rights_from_dbcidp_rights_ws($user, $group, $password, $dbcidp_rights_url) {
    require_once('class_lib/curl_class.php');
    $rights = new stdClass();
    $curl = new curl();
    $curl->set_post(sprintf('{"userIdAut":"%s","agencyId":"%s","passwordAut":"%s"}', $user, $group, $password));
    $curl->set_option(CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=UTF-8'], 0);
    $json = $curl->get($dbcidp_rights_url);
    if ($curl->get_status('http_code') != 200) {
      self::local_verbose(FATAL,
          'AAA(' . __LINE__ . '):: http error ' . $curl->get_status('http_code') .
          ' fetching rights from ' . $dbcidp_rights_url);
    }
    else {
      $reply = json_decode($json);
      if ($reply->authenticated && is_array($reply->rights)) {
        foreach ($reply->rights as $right) {
          _Object::set_element($rights, strtolower($right->productName), '500', TRUE);
        }
      }
      // Need to set 'netpunkt.dk' if 'danbib' is set to mimic deprecated FORS setting
      if ($rights->{'danbib'}->{'500'}) {
        _Object::set_element($rights, 'netpunkt.dk', '500', TRUE);
      }
    }
    return $rights;
  }

  /**
   * \brief set the rights array from the ini-file
   *
   * @param string $ip
   * @param array $ip_rights
   * @return mixed
   */
  private function fetch_rights_from_ip_rights($ip, $ip_rights) {
    if ($ip && is_array($ip_rights)) {
      foreach ($ip_rights as $aaa_group => $aaa_par) {
        if (ip_func::ip_in_interval($ip, $aaa_par['ip_list'])) {
          $this->aaa_ip_groups[$aaa_group] = TRUE;
          if (isset($aaa_par['ressource'])) {
            foreach ($aaa_par['ressource'] as $ressource => $right_list) {
              $right_val = explode(',', $right_list);
              foreach ($right_val as $r) {
                $r = trim($r);
                _Object::set_element($rights, $ressource, $r, TRUE);
              }
            }
          }
        }
      }
    }
    return $rights;
  }

    /** \brief return $var[$index] if set, $empty otherwise - to remove php NOTICEs from logfile
     * @param $var
     * @param $index
     * @param string $empty
     * @return string
     */
  private function set_or_not($var, $index, $empty = '') {
      return isset($var[$index]) ? $var[$index] : $empty;
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
