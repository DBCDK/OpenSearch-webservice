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
 * \brief ip-related functions
 *
 * Example usage:
 *  if (ip_func::ip_in_interval("1.2.3.4", "1.2.2.2-1.2.2.9;1.2.3.1-1.2.3.8")) ...
 *
 *
 * @author Finn Stausgaard - DBC
 **/
class ip_func {

  private static $_instance;

  /**
   * ip_func constructor.
   */
  private function __construct() {
  }

  /**
   * \brief returns true if ip is found in intervals
   *
   * @param $ip         string
   *        the ip-address to check (string)
   * @param $intervals  string
   *        ip-intervals (string)
   *        one or more intervals separated by ;
   *        each interval as n.n.n.n or n.n.n.n-m.m.m.m
   * @return boolean
   **/
  public static function ip_in_interval($ip, $intervals) {

    if (empty($intervals) || inet_pton($ip) === FALSE) {
      return FALSE;
    }

    if (strpos($intervals, ";")) { // multiple ip addresses or ranges.
      foreach (explode(";", $intervals) as $interval) {
        $result = self::handle_single_input($ip, $interval);
        if ($result) { return TRUE; }
      }
    } else {
      $result = self::handle_single_input($ip, $intervals);
      if ($result) { return TRUE; }
    }
    return FALSE;
  }

  /**
   * Check if an ip address is in a single range
   *
   * @param $ip string
   * @param $range string This is "192.168.1.1-192.168.1.255". NOT 192.168.1.1/24
   * @return bool
   */
  private static function check_single_ip_range($ip, $range) {
    list($from, $to) = explode("-", $range);
    return self::check_single_ip_address($ip, $from, $to);
  }

  /**
   * Check if an ip address is between two other ip addresses.
   *
   * @param $ip string
   * @param $from string
   * @param $to string
   * @return bool
   */
  private static function check_single_ip_address($ip, $from, $to) {
    $ip_int = @ inet_pton($ip);
    $from_int = $to_int = @ inet_pton(trim($from));
    $to_int = @ inet_pton(trim($to));
    return $ip_int >= $from_int && $ip_int <= $to_int;
  }

  /**
   * Run differently if range or single ip address.
   *
   * @param $ip string
   * @param $interval string
   * @return bool
   */
  private static function handle_single_input($ip, $interval) {
    return (strpos($interval, "-")) ? self::check_single_ip_range($ip, $interval)
                                           : self::check_single_ip_address($ip, $interval, $interval);
  }

}
