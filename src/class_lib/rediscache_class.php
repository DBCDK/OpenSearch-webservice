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

/**
 *
 * Wrapper for using redis as a cache.
 *
 * Information about the redis distributed db module for PHP can be
 * found at https://github.com/phpredis/phpredis
 *
 * ex.
 *   $cache = new RedisCache("localhost", "11211", 1200);
 *   $my_settings = $cache->get("my_settings");
 *   .
 *   .
 *   $cache->set("my_settings", $my_settings);
 *
 * @author Mads Bondo Dydensborg - DBC
 */
require_once('class_lib/verbose_json_class.php');

class RedisCache {
  private $redis = null;
  private $expire = 600;

  /**
   * \brief constructor
   * @param host (string)
   * @param port (integer)
   * @param expire (integer)
   **/
  function __construct($host, $port = null, $expire = null) {
    $this->redis = new Redis();
    if (empty($port) && strpos($host, ":")) {
      list($host, $port) = explode(":", $host, 2);
    }
    if (!@$this->redis->connect($host, $port)) {
      $this->redis = null;
    }
    if ($expire) {
      $this->expire = $expire;
    }
  }

  function __destruct() {
  }


  /**
   * \brief Gets data store with key in the redis server
   * @param key (string)
   * @return boolean
   **/
  public function get($key) {
    if ($key && is_object($this->redis) && (empty($_GET['rediscache']) || ($_GET['rediscache'] != 'SkipCache'))) {
      return unserialize($this->redis->get($key), ['allowed_classes' => false]);
    }
    return FALSE;
  }

  /**
   * \brief store data with key in the redis-server
   * @param key (string)
   * @param data (string)
   * @param expire (mixed)
   * @return boolean
   **/
  public function set($key, $data, $expire = NULL) {
    if (is_object($this->redis)) {
      if (empty($expire)) {
        $expire = $this->expire;
      }
      if ($this->redis->setEx($key, $expire, serialize($data))) {
        return TRUE;
      }
    }
    else {
      return FALSE;
    }
  }

  /**
   * \brief Delete data store with key in the redis server
   * @param key (string)
   * @return boolean
   **/
  public function delete($key) {
    if (is_object($this->redis)) {
      return $this->redis->del($key);
    }
    return FALSE;
  }

  /**
   * \brief mark all items in cache as expired
   * This method returns false when used with redis.
   * TODO: Do we need this?
   **/
  public function flush() {
    return FALSE;
  }

  /**
   * \brief set expire
   * @param expire (mixed)
   **/
  public function set_expire($expire) {
    $this->expire = $expire;
  }

  /**
   * \brief
   **/
  public function check() {
    return isset($this->redis);
  }
}
