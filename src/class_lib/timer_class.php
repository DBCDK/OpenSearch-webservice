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


/** \brief Stopwatch for code timing
 *
 * $watch = new stopwatch();
 * $watch->format('perl');
 *
 * $watch->start('a');
 * $watch->start('w');
 *
 * $watch->stop('a');
 * #$watch->start('a');
 *
 * echo $watch->dump();
 * echo "...\n";
 * $watch->format('screen');
 * echo $watch->dump();
 * echo "...\n";
 *
 * $watch->log("foo.log");
 *
 */
class stopwatch {
  var $timers;        ///< Currently running timers
  var $sums;        ///< Sums of completed timers
  var $prefix;        ///< Prefix of Output
  var $delim;        ///< Delimitor of Output
  var $postfix;        ///< Postfix of Output
  var $format;        ///< Format of Output

  /**
   * \brief stopwatch constructor
   * @param mixed $prefix Output prefix
   * @param mixed $delim Output delimitor
   * @param mixed $postfix Output postfix remember newline
   * @param mixed $format Output format ("%s => %01.6f")
   *************/
  function __construct($prefix = null, $delim = null, $postfix = null, $format = null) {
    $this->prefix = $prefix;
    $this->delim = $delim;
    $this->postfix = $postfix;
    $this->format = $format;
    $this->timers = array();
    $this->sums = array();
    $this->start('Total');
  }

  /**
   *  \brief start a timer
   * @param string $s - Name of timer to start
   * @param boolean $ignore - Ignore already started timer (default true)
   */
  function start($s, $ignore = TRUE) {
    if (!isset($this->timers[$s])) {
      $this->timers[$s] = microtime();
      if (!isset($this->sums[$s])) $this->sums[$s] = 0;
    }
    elseif (!$ignore) {
      die("FATAL: Cannot start timer $s... already running");
    }
  }

  /**
   * \brief stop a timer
   * @param string $s - Name of timer to stop
   * @param boolean $ignore - Ignore not running timer (default true)
   */
  function stop($s, $ignore = TRUE) {
    if (isset($this->timers[$s])) {
      list($usec_stop, $sec_stop) = explode(" ", microtime());
      list($usec_start, $sec_start) = explode(" ", $this->timers[$s]);
      $this->timers[$s] = null;
      $this->sums[$s] += ((float)$usec_stop - (float)$usec_start) + (float)($sec_stop - $sec_start);
    }
    elseif (!$ignore) {
      die("FATAL: Cannot stop timer $s... not running");
    }
  }


  /**
   * \brief splittime
   * @param string $s - Name of timer
   * @return mixed splittime
   */
  function splittime($s) {
    $add = 0;
    if ($this->timers[$s]) {
      list($usec_stop, $sec_stop) = explode(" ", microtime());
      list($usec_start, $sec_start) = explode(" ", $this->timers[$s]);
      $add = ((float)$usec_stop - (float)$usec_start) + (float)($sec_stop - $sec_start);
    }
    return ($this->sums[$s] + $add);
  }

  /**
   * \brief format
   * @param string $format - name of default format (file, screen or perl);
   */
  function format($format) {
    if ($format == "perl") {
      $this->prefix = "{ 'url' => '" . urlencode($_SERVER["PHP_SELF"]) . "', 'ts' => " . time() . ", ";
      $this->delim = ", ";
      $this->postfix = " }";
      $this->format = "'%s' => %0.6f";
    }
    else if ($format == "file") {
      if (isset($_SERVER["REQUEST_URI"])) {
        $this->prefix = urlencode($_SERVER["REQUEST_URI"]) . ": ";
      }
      else {
        $this->prefix = $_SERVER["PHP_SELF"] . ": ";
      }
      $this->delim = " ";
      $this->postfix = "";
      $this->format = "%s => %0.6f";
    }
    else if ($format == "screen") {
      if (isset($_SERVER["REQUEST_URI"])) {
        $this->prefix = "<pre>\nTimings for: " . urlencode($_SERVER["REQUEST_URI"]) . ":\n";
      }
      else {
        $this->prefix = "<pre>\nTimings for: " . $_SERVER["PHP_SELF"] . ":\n";
      }
      $this->delim = "\n";
      $this->postfix = "\n</pre>";
      $this->format = "%20s => %0.6f";
    }
    else {
      die("FATAL: Unknown format in stopwatch");
    }
  }

  function get_timers() {
    $ret = $this->sums;
    foreach ($this->timers as $k => $v) {
      if (!is_null($v))
        $ret[$k] = self::splittime($k);
    }
    return $ret;
  }

  /**
   * \brief dump all stoptimers
   * @param mixed $delim delimitor
   * @return string Dump of timers;
   */
  function dump($delim = null) {
    foreach ($this->timers as $k => $v) {
      if (!is_null($v))
        $this->stop($k);
    }

    $prefix = $this->prefix;
    $postfix = $this->postfix;
    $format = $this->format;
    if (is_null($delim)) $delim = $this->delim;        // Get delimitor or constructor delimitor
    // If unset: get defalut values
    if (is_null($delim)) $delim = "\n\t";
    if (is_null($format)) $format = "%s => %01.6f";
    if (is_null($prefix)) $prefix = "Timings for: " . $_SERVER['REQUEST_URI'] . (preg_match("/\n/", $delim) ? $delim : " ");
    if (is_null($postfix)) $postfix = "\n";
    if (!preg_match("/\n\$/", $postfix)) $postfix .= "\n";        // Make sure postfix ends in a newline

    $ret = array();
    //natcasesort($keys = array_keys($this->sums));
    $keys = array_keys($this->sums);
    foreach ($keys as $k) {
      array_push($ret, sprintf($format, $k, $this->sums[$k]));
    }
    return $prefix . join($delim, $ret) . $postfix;
  }

  /**
   * \brief log
   * @param string $file filename to log in
   * @param string $logformat format to use for log
   * @return  boolean
   */
  function log($file, $logformat = "perl") {
    $prefix = $this->prefix;              // Backup format
    $postfix = $this->postfix;
    $format = $this->format;
    $delim = $this->delim;

    if (!is_null($logformat))
      $this->format($logformat);
    if ($fd = fopen($file, "a")) {
      fwrite($fd, $this->dump());
      fclose($fd);
    }

    $this->prefix = $prefix;              // Restore format
    $this->postfix = $postfix;
    $this->format = $format;
    $this->delim = $delim;
    return !!$fd;                // Boolize $fd
  }
}

;
