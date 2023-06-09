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
 * Default global options
 * Should be defined in some config-file
 * @var    mixed
 */
$curl_default_options = array(
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HEADER => FALSE,
  CURLOPT_RETURNTRANSFER => TRUE
);

/**
 * \brief Class for handling cURL
 *
 * Example:
 *
 * $curl = new curl(); \n
 * print_r($curl->get(array("http://checkip.dbc.dk/","http://no.such_domain.net"))); // returns array \n
 * $curl->close(); \n
 *
 * Example:
 *
 * $curl = new curl(); \n
 * $curl->set_url("http://checkip.dbc.dk/",0);     // returns TRUE | FALSE \n
 * $handle_id = $curl->get_next_handle(); \n
 * $curl->set_url("http://no.such_domain.net",$handle_id);  // returns TRUE | FALSE \n
 * print_r($curl->get());                          // returns array \n
 * $curl->close(); \n
 *
 * Example:
 *
 * $curl = new curl(); \n
 * print_r($curl->get("http://checkip.dbc.dk/"));     // returns string \n
 * print_r($curl->get("http://kundeservice.dbc.dk")); // returns string \n
 * print_r($curl->get("http://no.such_domain.net"));  // returns string \n
 * $curl->close(); \n
 *
 * Example:
 *
 * $curl = new curl(); \n
 * set_url("http://lxr.php.net/");                 // returns TRUE | FALSE \n
 * set_option(CURLOPT_PROXY,"dmzproxy.dbc.dk:3128"); // returns TRUE | FALSE \n
 * echo $res = $curl->get();                       // returns string \n
 * $curl->get_option();                            // returns array \n
 * $curl->get_status();                            // returns array \n
 * $curl->get_status('http_code');                 // returns string \n
 * $curl->has_error();                             // returns string | FALSE \n
 * $curl->close(); \n
 *
 * Example:
 *
 * $curl = new curl(); \n
 * $curl->set_multiple_options($options_array); // returns TRUE | FALSE \n
 * $curl->set_option($option,$value,$n);        // returns TRUE | FALSE \n
 * $curl->set_proxy("dmzproxy.dbc.dk:3128", $n); // returns TRUE | FALSE \n
 * $curl->set_url("http://lxr.php.net/");       // returns TRUE | FALSE \n
 * $res = $curl->get();                         // returns array \n
 * $curl->get_option();                         // returns array \n
 * $curl->get_option(CURLOPT_URL);              // returns array \n
 * $curl->get_option(CURLOPT_PROXY,$n);         // returns string \n
 * $curl->get_status();                         // returns array \n
 * $curl->get_status('http_code');              // returns array \n
 * $curl->get_status('http_code',$n);           // returns string \n
 * $curl->has_error();                          // returns array \n
 * $curl->has_error($n);                        // returns string | FALSE \n
 * $curl->close(); \n
 *
 *
 * Example:
 * $curl = new curl(); \n
 * $curl->set_timeout(10);                    // returns TRUE | FALSE \n
 * $curl->set_proxy("someproxy.dk:1020", $n); // returns TRUE | FALSE \n
 * $curl->set_post_xml("<xml>foobar</xml>");  // returns TRUE | FALSE \n
 * $res = $curl->get();                       // returns array \n
 * $curl->close(); \n
 *
 *
 * Example:
 * $curl = new curl(); \n
 * $curl->set_post(array("foo" => "bar"); // returns TRUE | FALSE \n
 * $res = $curl->get();                   // returns array \n
 * $curl->close(); \n
 *
 */
#[AllowDynamicProperties]
class Curl {
  ///////////////////////////////////////
  // PRIVATE VARIABLES DO NOT CHANGE!!!//
  ///////////////////////////////////////

  /**
   * The handle(s) for the current curl session.
   * @access private
   * @var    mixed
   */
  private $curl_multi_handle;

  /**
   * Status information for the last executed http request.  Includes the errno and error
   * in addition to the information returned by curl_getinfo.
   *
   * The keys defined are those returned by curl_getinfo with two additional
   * ones specified, 'error' which is the value of curl_error and 'errno' which
   * is the value of curl_errno.
   *
   * @link http://www.php.net/curl_getinfo @endlink
   * @link http://www.php.net/curl_errno @endlink
   * @link http://www.php.net/curl_error @endlink
   * @access private
   * @var mixed
   */
  private $curl_status;

  /**
   * Current setting of the curl options.
   *
   * @access private
   * @var mixed
   */
  private $curl_options;
  private $cookies = array();

  ////////////////////
  // PUBLIC METHODS //
  ////////////////////

  /**
   * curl class constructor
   *
   * Initializes the curl class
   * @link http://www.php.net/curl_init @endlink
   * @param $url [optional] the URL to be accessed by this instance of the class. (string)
   * @return mixed
   */
  public function __construct($url = NULL) {
    global $curl_default_options;

    $this->curl_options = null;
    $this->curl_status = null;
    $this->wait_for_connections = PHP_INT_MAX;

    if (!function_exists('curl_init')) {
      self::local_verbose(FATAL, "PHP was not built with curl, rebuild PHP to use the curl class.");
      return FALSE;
    }

    if (!isset($curl_default_options)) {
      self::local_verbose(ERROR, '$curl_default_options is not defined. See the class description for usage');
      return FALSE;
    }
    else
      $this->curl_default_options = $curl_default_options;

    $this->curl_handle[] = curl_init();

    $this->set_multiple_options($this->curl_default_options);
    return TRUE;
  }

  /**
   * Set multiple options for a cURL transfer
   *
   * @link http://dk2.php.net/curl_setopt_array @endlink
   * @param $options - The array of curl options. See $curl_default_options (array)
   * @return bool  Returns TRUE if all options were successfully set (on all handles).
   *               If an option could not be successfully set, FALSE is immediately returned,
   *               ignoring any future options in the options array.
   */
  public function set_multiple_options($options = NULL) {

    if (!$options)
      return FALSE;

    foreach ($this->curl_handle as $key => $handle) {
      $res = curl_setopt_array($this->curl_handle[$key], $options);
      if (!$res)
        return FALSE;
    }
    reset($this->curl_handle);
    foreach ($this->curl_handle as $key => $handle) {
      foreach ($options as $option => $value) {
        $this->curl_options[$key][$option] = $value;
      }
    }
    return TRUE;
  }

  /**
   * Execute the curl request and return the result.
   *
   * @link http://www.php.net/curl_multi_close @endlink \n
   * @link http://www.php.net/curl_multi_init @endlink \n
   * @link http://www.php.net/curl_multi_add_handle @endlink \n
   * @link http://www.php.net/curl_multi_exec @endlink \n
   * @link http://www.php.net/curl_multi_getcontent @endlink \n
   * @link http://www.php.net/curl_getinfo @endlink \n
   * @link http://www.php.net/curl_errno @endlink \n
   * @link http://www.php.net/curl_error @endlink \n
   * @param boolean $urls
   * @return string The contents of the page (or other interaction as defined by the
   *                settings of the various curl options).
   */
  public function get($urls = FALSE) {

    if ($urls)
      self::set_url($urls);

    // remove previous curl_multi_handle, if any
    if (is_resource($this->curl_multi_handle)) {
      if (is_array($this->curl_handle)) {
        foreach ($this->curl_handle as $key => $handle) {
          curl_multi_remove_handle($this->curl_multi_handle, $this->curl_handle[$key]);
        }
      }
    }
    else {
      //create a new multiple cURL handle
      $this->curl_multi_handle = curl_multi_init();
    }

    // set cookies and add the handles
    foreach ($this->curl_handle as $key => $handle) {
      if (array_key_exists(intval($handle), $this->cookies)) {
        if ($this->cookies[intval($handle)]) {
          self::set_option(CURLOPT_COOKIE, implode(';', $this->cookies[$handle]));
        }
      }
      curl_multi_add_handle($this->curl_multi_handle, $this->curl_handle[$key]);
    }

    // execute the handles
    do {
      /*
        curl_multi_select() should according to the manual:
        'Blocks until there is activity on any of the curl_multi connections.'
        but including the line below without an timeout, more than doubles the time used in this function???

        Has to call it with a timeout less than 1, or it will apparently default (and wait) 1 second for
        each connection????????
       */
      curl_multi_select($this->curl_multi_handle, 0.01);
      $status = curl_multi_exec($this->curl_multi_handle, $active);
      if ($info = curl_multi_info_read($this->curl_multi_handle)) {
        $multi_status[intval($info['handle'])] = $info['result'];
        if (curl_getinfo($info['handle'], CURLINFO_HTTP_CODE) == 200)
          $this->wait_for_connections--;
      }
    } while ($this->wait_for_connections && ($status === CURLM_CALL_MULTI_PERFORM || $active));
    if ($info = curl_multi_info_read($this->curl_multi_handle)) {
      $multi_status[intval($info['handle'])] = $info['result'];
    }
    foreach ($this->curl_handle as $key => $handle) {
      $this->curl_status[$key] = curl_getinfo($this->curl_handle[$key]);
      $this->curl_status[$key]['errno'] = curl_errno($this->curl_handle[$key]);
      $this->curl_status[$key]['error'] = curl_error($this->curl_handle[$key]);
      if (empty($this->curl_status[$key]['errno']) && isset($multi_status[intval($handle)])) {
        $this->curl_status[$key]['errno'] = $multi_status[intval($handle)];
      }
      if ($this->curl_status[$key]['errno'] == 28) { // CURLE_OPERATION_TIMEDOUT
        $this->curl_status[$key]['http_code'] = 504;  // Gateway timeout
      }
    }
    foreach ($this->curl_handle as $key => $handle) {
      $this->curl_content[$key] = curl_multi_getcontent($handle);
      if (method_exists('curl_recorder', 'record')) {
        $curl_recorder->record('status', $this->curl_status[$key], $key);
        $curl_recorder->record('result', $this->curl_content[$key], $key);
      }
    }

    if (sizeof($this->curl_handle) == 1)
      return $this->curl_content[0];
    else
      return $this->curl_content;
  }

  /**
   * Returns the current setting of the request option.
   * If no handle_number has been set, it return the settings of all handles.
   *
   * @param $option - One of the valid CURLOPT defines. (mixed)
   * @param $handle_no - Handle number. (integer)
   * @returns mixed
   */
  public function get_option($option = null, $handle_no = 0) {

    foreach ($this->curl_handle as $key => $handle) {
      if (!$handle_no || $key == $handle_no) {
        if (empty($option)) {
          $option_values[] = $this->curl_options[$key];
        }
        else {
          if (isset($this->curl_options[$key][$option]))
            $option_values[] = $this->curl_options[$key][$option];
          else
            $option_values[] = null;
        }
      }
    }

    if ($handle_no || sizeof($this->curl_handle) == 1)
      return $option_values[0];
    else
      return $option_values;
  }

  /**
   * Set a curl option.
   *
   * @link http://www.php.net/curl_setopt @endlink
   * @param $option - One of the valid CURLOPT defines. (mixed)
   * @param $value - The value of the curl option. (mixed)
   * @param $handle_no - Handle number. (integer)
   * @return boolean
   */
  public function set_option($option, $value, $handle_no = null) {

    if ($handle_no === null) {
      foreach ($this->curl_handle as $key => $handle) {
        $this->curl_options[$key][$option] = $value;
        $res = curl_setopt($this->curl_handle[$key], $option, $value);
        if (!$res)
          return FALSE;
      }
    }
    else {
      self::handle_check($handle_no);
      $this->curl_options[$handle_no][$option] = $value;
      $res = curl_setopt($this->curl_handle[$handle_no], $option, $value);
    }
    return $res;
  }

  /**
   * Set CURLOPT_URL value(s).
   * @param $value (s)   - The value of the curl option. (mixed)
   * @param $handle_no - Handle number. Default 0. (integer)
   * @return void
   */
  public function set_url($value, $handle_no = 0) {
    if (is_array($value)) {
      foreach ($value as $key => $url) {
        self::set_option(CURLOPT_URL, $url, $key);
        if (method_exists('curl_recorder', 'record_url')) {
          $curl_recorder->record_url($url, $key);
        }
      }
    }
    else {
      self::set_option(CURLOPT_URL, $value, $handle_no);
      if (method_exists('curl_recorder', 'record_url')) {
        $curl_recorder->record_url($value, $handle_no);
      }
    }
  }

  /**
   * Set HTTP proxy value(s).
   * @param $value - HTTP proxy
   * @param $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function set_proxy($value, $handle_no = null) {
    if ($ret = self::set_option(CURLOPT_HTTPPROXYTUNNEL, TRUE, $handle_no))
      $ret = self::set_option(CURLOPT_PROXY, $value, $handle_no);
    return $ret;
  }

  /**
   * Set using cookies
   * @param $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function use_cookies($handle_no = null) {
    return self::set_option(CURLOPT_HEADERFUNCTION, array($this, 'callback_save_cookies'), $handle_no);
  }

  /**
   * Set HTTP authentication value(s).
   * @param $user - HTTP user
   * @param $passwd - HTTP password
   * @param $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function set_authentication($user, $passwd, $handle_no = null) {
    return self::set_option(CURLOPT_USERPWD, $user . ':' . $passwd, $handle_no);
  }

  /**
   * Set HTTP proxy authentication value(s).
   * @param $user - HTTP proxy user
   * @param $passwd - HTTP proxy password
   * @param $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function set_proxy_authentication($user, $passwd, $handle_no = null) {
    return self::set_option(CURLOPT_PROXYUSERPWD, '[' . $user . ']:[' . $passwd . ']', $handle_no);
  }

  /**
   * Set timeout
   * @param $seconds - timeout ind seconds
   * @param $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function set_timeout($seconds, $handle_no = null) {
    return self::set_option(CURLOPT_TIMEOUT, $seconds, $handle_no);
  }

  /**
   * Set number of connections to wait for
   * @param $wait_for_connections - max connections to wait for
   * @return boolean
   */
  public function set_wait_for_connections($wait_for_connections) {
    $this->wait_for_connections = $wait_for_connections;
    return TRUE;
  }

  /**
   * Set POST value(s).
   * @param $value - The value(s) to post
   * @param $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function set_post($value, $handle_no = null) {
    if ($ret = self::set_option(CURLOPT_POST, 1, $handle_no))
      $ret = self::set_option(CURLOPT_POSTFIELDS, $value, $handle_no);
    if (method_exists('curl_recorder', 'record_parameters')) {
      $curl_recorder->record_parameters($value, $handle_no);
    }
    return $ret;
  }

  /**
   * Set POST value(s) with Content-Type: text/xml.
   * @param $value - The value(s) to post
   * @param $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function set_post_xml($value, $handle_no = null) {
    return self::set_post_with_header($value, 'Content-Type: text/xml', $handle_no);
  }

  /**
   * Set POST value(s) with Content-Type: application/xml.
   * @param $value - The value(s) to post
   * @param $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function set_post_application_xml($value, $handle_no = null) {
    return self::set_post_with_header($value, 'Content-Type: application/xml', $handle_no);
  }

  /**
   * Set POST value(s).
   * @param $header_line - Like 'Content-Type: ...'
   * @param string $value - The value(s) to post
   * @param string $header_line - Like 'Content-Type: ...'
   * @param integer $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function set_post_with_header($value, $header_line, $handle_no = null) {
    $headers = $this->get_option(CURLOPT_HTTPHEADER, $handle_no);
    $headers[] = $header_line;
    if ($ret = self::set_option(CURLOPT_HTTPHEADER, $headers, $handle_no))
      $ret = self::set_post($value, $handle_no);
    return $ret;
  }

  /**
   * Set SOAP Action
   * @param string $action - The soap-action
   * @param integer $handle_no - Handle number. Default all handle numbers. (integer)
   * @return boolean
   */
  public function set_soap_action($action, $handle_no = null) {
    $headers = $this->get_option(CURLOPT_HTTPHEADER, $handle_no);
    $headers[] = "SOAPAction: " . $action;
    return self::set_option(CURLOPT_HTTPHEADER, $headers, $handle_no);
  }

  /**
   * Get next available handle ID.
   * @return integer
   */
  public function get_next_handle() {
    $next_handle_no = 0;
    foreach ($this->curl_handle as $key => $handle) {
      if ($key > $next_handle_no)
        $next_handle_no = $key;
    }
    return $next_handle_no + 1;
  }

  /**
   * Return the status information of the last curl request.
   *
   * @param string $field [optional] the particular portion (string)
   *                     of the status information desired.
   *                     If omitted the array of status
   *                     information is returned.  If a non-existent
   *                     status field is requested, FALSE is returned.
   * @param integer $handle_no  Handle number. (integer)
   * @return mixed
   */
  public function get_status($field = null, $handle_no = 0) {

    foreach ($this->curl_handle as $key => $handle) {
      if (!$handle_no || $key == $handle_no) {
        if (empty($field)) {
          $status[] = $this->curl_status[$key];
        }
        else {
          if (isset($this->curl_status[$key][$field])) {
            $status[] = $this->curl_status[$key][$field];
          }
          else
            return FALSE;
        }
      }
    }

    if ($handle_no || sizeof($this->curl_handle) == 1)
      return $status[0];
    else
      return $status;
  }

  /**
   * Did the last curl exec operation have an error?
   *
   * @param $handle_no - Handle number. (integer)
   * @return mixed  The error message associated with the error if an error
   *                occurred, FALSE otherwise.
   */
  public function has_error($handle_no = 0) {

    foreach ($this->curl_handle as $key => $handle) {
      if (!$handle_no || $key == $handle_no) {
        if (isset($this->curl_status[$key]['error'])) {
          $has_error[] = (empty($this->curl_status[$key]['error']) ? FALSE : $this->curl_status[$key]['error']);
        }
        else
          $has_error[] = FALSE;
      }
    }

    if ($handle_no || sizeof($this->curl_handle) == 1)
      return $has_error[0];
    else
      return $has_error;
  }

  /**
   * Free the resources associated with the curl session.
   *
   * @link http://www.php.net/curl_close @endlink
   * $return void
   */
  public function close() {
    foreach ($this->curl_handle as $key => $handle) {
      curl_multi_remove_handle($this->curl_multi_handle, $this->curl_handle[$key]);
      curl_close($this->curl_handle[$key]);
    }
    $this->curl_handle = array();
        $this->curl_content = null;
        $this->curl_status = null;
// keep the multihandle in order to reuse sockets
    //curl_multi_close($this->curl_multi_handle);
    //$this->curl_multi_handle = null ;
  }

  /////////////////////
  // PRIVATE METHODS //
  /////////////////////

  /**
   * Check if there's a handle for the handle number, and if not, create the handle
   * and assign default values.
   * @param $handle_no - Handle number. (integer)
   * @return void
   */
  private function handle_check($handle_no) {
    if (!isset($this->curl_handle[$handle_no])) {
      $this->curl_handle[$handle_no] = curl_init();
      if (!is_array($this->curl_default_options)) {
        return;
      }
      foreach ($this->curl_default_options as $option => $option_value) {
        self::set_option($option, $option_value, $handle_no);
      }
    }
  }

  /**
   * Callback function to catch header-lines and store cookies for later use
   *
   * @param $ch (ressource)       - cURL handle
   * @param $header_line (string) - a header line
   *
   * @return string  - the callback function has to return the length of the line
   */
  private function callback_save_cookies($ch, $header_line) {
    if (substr($header_line, 0, 11) == 'Set-Cookie:') {
      $parts = explode(';', substr($header_line, 11));
      $cookie = trim($parts[0]);
      if (empty($this->cookies[$ch]) || !in_array($cookie, $this->cookies[$ch])) {
        $this->cookies[$ch][] = $cookie;
      }
    }
    return strlen($header_line);
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
    elseif (function_exists('verbose')) {
      verbose($level, $msg);
    }
  }

}
