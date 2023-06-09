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


/** \brief Webservice server
 *
 * @author Finn Stausgaard - DBC
 *
 */

require_once('class_lib/curl_class.php');
require_once('class_lib/verbose_json_class.php');
require_once('class_lib/inifile_class.php');
require_once('class_lib/timer_class.php');
require_once('class_lib/aaa_class.php');
require_once('class_lib/ip_class.php');
require_once('class_lib/_object_class.php');
require_once('class_lib/restconvert_class.php');
require_once('class_lib/jsonconvert_class.php');
require_once('class_lib/xmlconvert_class.php');
require_once('class_lib/objconvert_class.php');

/**
 * Class webServiceServer
 */
abstract class webServiceServer {

  protected $config; ///< inifile object
  protected $watch; ///< timer object
  protected $aaa; ///< Authentication, Access control and Accounting object
  protected $xmldir = './'; ///< xml directory
  protected $validate = array(); ///< xml validate schemas
  protected $objconvert; ///< OLS object to xml convert
  protected $xmlconvert; ///< xml to OLS object convert
  protected $xmlns; ///< namespaces and prefixes
  protected $default_namespace; ///< -
  protected $tag_sequence; ///< tag sequence according to XSD or noame of XSD
  protected $soap_action; ///< -
  protected $dump_timer; ///< -
  protected $output_type = ''; ///< -
  protected $debug; ///< -
  protected $url_override; ///< array with special url-commands for the actual service
  protected $api_used; ///< example-soap, example-xml, raw-soap, raw-xml, file-soap, file-xml, rest or post



  /** \brief Webservice constructer
   *
   * @param $inifile string
   *
   */
  public function __construct($inifile) {
    // initialize config and verbose objects
    $this->config = new inifile($inifile);

    if ($this->config->error) {
      die('Error: ' . $this->config->error);
    }

    // service closed
    if ($http_error = $this->config->get_value('service_http_error', 'setup')) {
      header($http_error);
      die($http_error);
    }

    if ($this->config->get_value('only_https', 'setup') && empty($_SERVER['HTTPS'])) {
      header('HTTP/1.0 403.4 SSL Required');
      die('HTTP/1.0 403.4 SSL Required');
    }

    libxml_use_internal_errors(TRUE);

    if (self::in_house())
      $this->debug = isset($_REQUEST['debug']) ? $_REQUEST['debug'] : '';
    $this->version = $this->config->get_value('version', 'setup');
    VerboseJson::open($this->config->get_section('setup'));
    $this->watch = new stopwatch('', ' ', '', '%s:%01.3f');

    if ($this->config->get_value('xmldir'))
      $this->xmldir = $this->config->get_value('xmldir');
    $this->xmlns = $this->config->get_value('xmlns', 'setup');
    $this->default_namespace = $this->xmlns[$this->config->get_value('default_namespace_prefix', 'setup')];
    $this->tag_sequence = $this->config->get_value('tag_sequence', 'setup');
    $this->output_type = $this->config->get_value('default_output_type', 'setup');
    $this->dump_timer = str_replace('_VERSION_', $this->version, $this->config->get_value('dump_timer', 'setup'));
    if (!$this->url_override = $this->config->get_value('url_override', 'setup'))
      $this->url_override = array('HowRU' => 'HowRU', 'ShowInfo' => 'ShowInfo', 'Version' => 'Version', 'wsdl' => 'Wsdl');

    $this->aaa = new aaa($this->config->get_section('aaa'));
  }

  public function __destruct() {
  }

  /** \brief Handles request from webservice client
   *
   * @return mixed
   */
  public function handle_request() {
    foreach ($this->url_override as $query_par => $function_name) {
      if (strpos($_SERVER['QUERY_STRING'], $query_par) === 0 && method_exists($this, $function_name)) {
        return $this->{$function_name}();
      }
    }
    if (isset($_POST['xml'])) {
      $this->api_used = 'example';
      $xml = trim(stripslashes($_POST['xml']));
      self::soap_request($xml);
    }
    elseif (!empty($GLOBALS['HTTP_RAW_POST_DATA'])) {
      $this->api_used = 'raw';
      self::soap_request($GLOBALS['HTTP_RAW_POST_DATA']);
    }
    // pjo 11/9/17 for php 7. @see http://php.net/manual/en/reserved.variables.httprawpostdata.php.
    elseif ($xml = file_get_contents("php://input")) {
      $this->api_used = 'file';
      self::soap_request($xml);
    }
    elseif (!empty($_SERVER['QUERY_STRING']) && ($_REQUEST['action'] || $_REQUEST['json'])) {
      $this->api_used = 'rest';
      self::rest_request();
    }
    elseif (!empty($_POST)) {
      $this->api_used = 'post';
      foreach ($_POST as $k => $v) {
        $_SERVER['QUERY_STRING'] .= ($_SERVER['QUERY_STRING'] ? '&' : '') . $k . '=' . $v;
      }
      self::rest_request();
    }
    elseif (self::in_house()
      || $this->config->get_value('show_samples', 'setup')
      || ip_func::ip_in_interval(self::get_client_ip(), $this->config->get_value('show_samples_ip_list', 'setup'))
    ) {
      self::create_sample_forms();
    }
    else {
      header('HTTP/1.0 404 Not Found');
    }
  }

  /** \brief Handles and validates soap request
   *
   * @param $xml string
   */
  private function soap_request($xml) {
    // Debug VerboseJson::log(TRACE, array('request' => $xml));

    // validate request
    $this->validate = $this->config->get_value('validate');

    if (!empty($this->validate['soap_request']) || !empty($this->validate['request']))
      $error = !self::validate_soap($xml, $this->validate, 'request');

    if (empty($error)) {
      // parse to object
      $this->xmlconvert = new xmlconvert();
      $xmlobj = $this->xmlconvert->soap2obj($xml);
      // soap envelope?
      $xml_or_soap = array('example', 'raw', 'file');
      if (isset($xmlobj->Envelope)) {
        if (in_array($this->api_used, $xml_or_soap)) $this->api_used .= '-soap';
        @ $request_xmlobj = &$xmlobj->Envelope->_value->Body->_value;
        @ $soap_namespace = $xmlobj->Envelope->_namespace;
      }
      else {
        if (in_array($this->api_used, $xml_or_soap)) $this->api_used .= '-xml';
        $request_xmlobj = &$xmlobj;
        $soap_namespace = 'http://www.w3.org/2003/05/soap-envelope';
        $this->output_type = 'xml';
      }

      // initialize objconvert and load namespaces
      $timer = '';
      if (isset($_GET['marshal']) && self::in_house()) $timer = &$this->watch;
      $this->objconvert = new objconvert($this->xmlns, $this->tag_sequence, $timer);
      $this->objconvert->set_default_namespace($this->default_namespace);

      // handle request
      if ($response_xmlobj = self::call_xmlobj_function($request_xmlobj)) {
        // validate response
        if (isset($this->validate['soap_response']) || isset($this->validate['response'])) {
          $response_xml = $this->objconvert->obj2soap($response_xmlobj, $soap_namespace);
          $error = !self::validate_soap($response_xml, $this->validate, 'response');
        }

        if (empty($error)) {
          // Branch to outputType
          $req = current((Array)$request_xmlobj);
          if (empty($this->output_type) || $req->_value->outputType->_value)
            $this->output_type = isset($req->_value->outputType) ? $req->_value->outputType->_value : '';
          VerboseJson::set_verbose_element('output', $this->output_type);
          switch ($this->output_type) {
            case 'json':
              header('Content-Type: application/json');
              if (isset($req->_value->callback)) {
                $callback = &$req->_value->callback->_value;
              }
              if (!empty($callback) && preg_match("/^\w+$/", $callback))
                echo $callback . ' && ' . $callback . '(' . $this->objconvert->obj2json($response_xmlobj) . ')';
              else
                echo $this->objconvert->obj2json($response_xmlobj);
              break;
            case 'php':
              header('Content-Type: application/php');
              echo $this->objconvert->obj2phps($response_xmlobj);
              break;
            case 'xml':
              header('Content-Type: text/xml');
              echo $this->objconvert->obj2xmlNS($response_xmlobj);
              break;
            default:
              if (empty($response_xml))
                $response_xml = $this->objconvert->obj2soap($response_xmlobj, $soap_namespace);
              if ($soap_namespace == 'http://www.w3.org/2003/05/soap-envelope' && empty($_POST['xml']))
                header('Content-Type: application/soap+xml');   // soap 1.2
              else
                header('Content-Type: text/xml; charset=utf-8');
              echo $response_xml;
          }
          // request done and response send, dump timer
          if ($this->dump_timer) {
            VerboseJson::log(TIMER, array_merge(array('operation' => $this->soap_action), $this->watch->get_timers()));
          }
        }
        else
          self::soap_error('Error in response validation.');
      }
      else
        self::soap_error('Incorrect SOAP envelope or wrong/unsupported request');
    }
    else
      self::soap_error('Error in request validation.');

    if ($duplicate_request_to = $this->config->get_value('duplicate_request_to')) {
      reset($request_xmlobj);
      $request = key($request_xmlobj);
      if (is_null($request_xmlobj->$request->_value->authentication->_value)) {
        unset($request_xmlobj->$request->_value->authentication);
      }
      $request = $this->objconvert->obj2soap($request_xmlobj, $soap_namespace);
      $curl = new curl();
      foreach ($duplicate_request_to as $no => $uri) {
        $curl->set_option(CURLOPT_TIMEOUT_MS, 10, $no);
        $curl->set_url($uri, $no);
        if ($soap_namespace == 'http://www.w3.org/2003/05/soap-envelope') {
          $curl->set_post_with_header($request, 'Content-Type: application/soap+xml', $no);
        }
        else {
          $curl->set_post_with_header($request, 'Content-Type: text/xml; charset=utf-8', $no);
        }
      }
      $reply = $curl->get();
    }
  }

  /** \brief Handles rest request, converts it to xml and calls soap_request()
   *
   */
  private function rest_request() {
    // convert to soap
    if (!empty($_REQUEST['json'])) {
      $json = new jsonconvert($this->default_namespace);
      $xml = $json->json2soap($this->config);
    }
    else {
      $rest = new restconvert($this->default_namespace);
      $xml = $rest->rest2soap($this->config);
    }
    self::soap_request($xml);
  }

  /** \brief Show the service version
   *
   * @noinspection PhpUnusedPrivateMethodInspection
   */
  private function version() {
    die($this->version);
  }

  /** \brief Show wsdl file for the service replacing __LOCATION__ with ini-file setting or current location
   *
   * @noinspection PhpUnusedPrivateMethodInspection
   */
  private function Wsdl() {
    if ($wsdl = $this->config->get_value('wsdl', 'setup')) {
      if (!$location = $this->config->get_value('service_location', 'setup')) {
        $location = $_SERVER['SERVER_NAME'] . dirname($_SERVER['SCRIPT_NAME']) . '/';
      }
      if (strpos($location, '://') < 1) {
        if (!empty($_SERVER['HTTPS']) || (array_key_exists('HTTP_X_FORWARDED_PROTO', $_SERVER) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')) {
          $protocol = 'https://';
        }
        else {
          $protocol = 'http://';
        }
      }
      if (($text = file_get_contents($wsdl)) !== FALSE) {
        header('Content-Type: text/xml; charset="utf-8"');
        die(str_replace('__LOCATION__', $protocol . $location, $text));
      }
      else {
        die('ERROR: Cannot open the wsdl file - error in ini-file?');
      }
    }
    else {
      die('ERROR: wsdl not defined in the ini-file');
    }
  }

  /** \brief Show selected parts of the ini-file
   *
   * @noinspection PhpUnusedPrivateMethodInspection
   */
  private function ShowInfo() {
    if (($showinfo = $this->config->get_value('showinfo', 'showinfo')) && self::in_house()) {
      foreach ($showinfo as $line) {
        echo self::showinfo_line($line) . "\n";
      }
      die();
    }
  }

  /** \brief expands __var__ to the corresponding setting
   *
   * @param $line string
   * @return mixed|string
   */
  private function showinfo_line($line) {
    while (($s = strpos($line, '__')) !== FALSE) {
      $line = substr($line, 0, $s) . substr($line, $s + 2);
      if (($e = strpos($line, '__')) !== FALSE) {
        $var = substr($line, $s, $e - $s);
        list($key, $section) = explode('.', $var, 2);
        $val = $this->config->get_value($key, $section);
        if (is_array($val)) {
          $val = self::implode_ini_array($val);
        }
        $line = str_replace($var . '__', $val, $line);
      }
    }
    return $line;
  }

  /** \brief Helper function to showinfo_line()
   *
   * @param $arr array
   * @param $prefix string
   * @return mixed
   */
  private function implode_ini_array($arr, $prefix = '') {
    $ret = "\n";
    foreach ($arr as $key => $val) {
      if (is_array($val)) {
        $val = self::implode_ini_array($val, ' - ' . $prefix);
      }
      $ret .= $prefix . ' - [' . $key . '] ' . $val . "\n";
    }
    return str_replace("\n\n", "\n", $ret);
  }

  /** \brief
   *  Return TRUE if the IP is in in_house_ip_list, or if in_house_ip_list is not set, if the name is in in_house_domain
   *  NB: gethostbyaddr() can take some time or even time out, is the remote name server is slow or wrongly configured
   */
  protected function in_house() {
    static $homie;
    if (!isset($homie)) {
      if (FALSE !== ($in_house_ip_list = $this->config->get_value('in_house_ip_list', 'setup'))) {
        $homie = ip_func::ip_in_interval(self::get_client_ip(), $in_house_ip_list);
      }
      else {
        if (!$domain = $this->config->get_value('in_house_domain', 'setup'))
          $domain = '.dbc.dk';
        @ $remote = gethostbyaddr(self::get_client_ip());
        $domains = explode(';', $domain);
        foreach ($domains as $dm) {
          $dm = trim($dm);
          if ($homie = (strpos($remote, $dm) + strlen($dm) == strlen($remote)))
            if ($homie = (gethostbyname($remote) == self::get_client_ip())) // paranoia check
              break;
        }
      }
    }
    return $homie;
  }

  /** \brief RegressionTest tests the webservice
   *
   * @param $arg string
   *
   * @noinspection PhpUnusedPrivateMethodInspection
   */
  private function RegressionTest($arg = '') {
    if (!is_dir($this->xmldir . '/regression'))
      die('No regression catalouge');

    if ($dh = opendir($this->xmldir . '/regression')) {
      chdir($this->xmldir . '/regression');
      while (($file = readdir($dh)) !== false)
        if (!is_dir($file) && preg_match('/xml$/', $file, $matches))
          $fnames[] = $file;
      if (count($fnames)) {
        asort($fnames);
        $curl = new curl();
        $curl->set_option(CURLOPT_POST, 1);
        foreach ($fnames as $fname) {
          $contents = str_replace("\r\n", PHP_EOL, file_get_contents($fname));
          $curl->set_post_xml($contents);
          $reply = $curl->get($_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
          echo $reply;
        }
      }
      else
        die('No files found for regression test');
    }
    else
      die('Cannot open regression catalouge: ' . $this->xmldir . '/regression');
  }

  /** \brief HowRU tests the webservice and answers "Gr8" if none of the tests fail. The test cases resides in the inifile.
   *
   *  Handles zero or more set of tests.
   *  Each set, can contain one or more tests, where just one of them has to succeed
   *  If all tests in a given set fails, the corresponding error will be displayed
   *
   * @noinspection PhpUnusedPrivateMethodInspection
   */
  private function HowRU() {
    $tests = $this->config->get_value('test', 'howru');
    if ($tests) {
      $curl = new curl();

      $proxy = $this->config->get_section('proxy');
      if ($proxy && isset($proxy['domain_and_port'])) {
        $curl->set_proxy($proxy['domain_and_port']);
      }

      $reg_matchs = $this->config->get_value('preg_match', 'howru');
      $reg_errors = $this->config->get_value('error', 'howru');
      if (!$server_name = $this->config->get_value('server_name', 'howru')) {
        if (!$server_name = $_SERVER['SERVER_NAME']) {
          $server_name = $_SERVER['HTTP_HOST'];
        }
      }
      $url = $server_name . $_SERVER['PHP_SELF'];
      if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
        $url = 'https://' . $url;
      } else {
        $url = 'http://' . $url;
      }
      foreach ($tests as $i_test => $test) {
        if (is_array($test)) {
          $reg_match = $reg_matchs[$i_test];
        }
        else {
          $test = array($test);
          $reg_match = array($reg_matchs[$i_test]);
        }
        $error = $reg_errors[$i_test];
        foreach ($test as $i => $t) {
          $reply = $curl->get($url . '?action=' . $t);
          $preg_match = $reg_match[$i];
          if (preg_match("/$preg_match/", $reply)) {
            unset($error);
            break;
          }
        }
        if (!empty($error)) {
          header('HTTP/1.0 503 Service Unavailable');
          die($error);
        }
      }
      $curl->close();
    }
    die('Gr8');
  }

  /** \brief Validates soap and xml
   *
   * @param $soap string
   * @param $schemas array
   * @param $validate_schema string
   * @return bool
   */
  protected function validate_soap($soap, $schemas, $validate_schema) {
    $validate_soap = new DomDocument;
    $validate_soap->preserveWhiteSpace = FALSE;
    @ $validate_soap->loadXml($soap);
    if (($sc = $schemas['soap_' . $validate_schema]) && !@ $validate_soap->schemaValidate($sc))
      return FALSE;

    if ($sc = $schemas[$validate_schema]) {
      if ($validate_soap->firstChild->localName == 'Envelope'
        && $validate_soap->firstChild->hasChildNodes()
      ) {
        foreach ($validate_soap->firstChild->childNodes as $soap_node) {
          if ($soap_node->localName == 'Body') {
            $xml = &$soap_node->firstChild;
            $validate_xml = new DOMdocument;
            @ $validate_xml->appendChild($validate_xml->importNode($xml, TRUE));
            break;
          }
        }
      }
      if (empty($validate_xml))
        $validate_xml = &$validate_soap;

      if (!@ $validate_xml->schemaValidate($sc))
        return FALSE;
    }

    return TRUE;
  }

  /** \brief send an error header and soap fault
   *
   * @param $err string
   *
   */
  protected function soap_error($err) {
    $xml_err = '';
    $elevel = array(LIBXML_ERR_WARNING => "\n Warning",
                    LIBXML_ERR_ERROR => "\n Error",
                    LIBXML_ERR_FATAL => "\n Fatal");
    if ($errors = libxml_get_errors()) {
      foreach ($errors as $error) {
        $xml_err .= $elevel[$error->level] . ": " . trim($error->message) .
          ($error->file ? " in file " . $error->file : " on line " . $error->line);
      }
    }
    header('HTTP/1.0 400 Bad Request');
    header('Content-Type: text/xml; charset="utf-8"');
    echo '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Body>
    <SOAP-ENV:Fault>
    <faultcode>SOAP-ENV:Server</faultcode>
    <faultstring>' . htmlspecialchars($err . $xml_err) . '</faultstring>
    </SOAP-ENV:Fault>
    </SOAP-ENV:Body>
    </SOAP-ENV:Envelope>';
  }

  /** \brief Validates xml
   *
   * @param $xml string
   * @param $schema_filename string
   * @param $resolve_externals boolean
   * @return bool
   */
  protected function validate_xml($xml, $schema_filename, $resolve_externals = FALSE) {
    $validateXml = new DomDocument;
    $validateXml->resolveExternals = $resolve_externals;
    $validateXml->loadXml($xml);
    if (@ $validateXml->schemaValidate($schema_filename)) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /** \brief Find operation in object created from xml and and calls this function defined by developer in extended class.
   * Authentication is by default found in the authentication node, in userIdAut, groupIdAut and passwordAut
   * These names can be changed by doing so in the aaa-section, like:
   * userIdAut = theNameOfUserIdInThisService
   *
   * @param $xmlobj object
   * @return bool - from the service entry point called
   */
  private function call_xmlobj_function($xmlobj) {
    if ($xmlobj) {
      $soapActions = $this->config->get_value('soapAction', 'setup');
      $request = key(get_mangled_object_vars($xmlobj));
      if ($this->soap_action = array_search($request, $soapActions)) {
        $params = $xmlobj->$request->_value;
        if (method_exists($this, $this->soap_action)) {
          VerboseJson::set_verbose_element('operation', $this->soap_action);
          VerboseJson::set_verbose_element('apiused', $this->api_used);
          VerboseJson::set_verbose_element('buildnumber', self::get_buildnumber());
          if (isset($params->trackingId)) {
            VerboseJson::set_tracking_id($this->config->get_value('default_namespace_prefix', 'setup'), $params->trackingId->_value);
          }
          if (is_object($this->aaa)) {
            foreach (array('authentication', 'userIdAut', 'groupIdAut', 'passwordAut') as $par) {
              if (!$$par = $this->config->get_value($par, 'aaa')) {
                $$par = $par;
              }
            }
            $auth = isset($params->$authentication) ? $params->$authentication->_value : new stdClass();
            $this->aaa->init_rights(isset($auth->$userIdAut) ? $auth->$userIdAut->_value : '',
                                    isset($auth->$groupIdAut) ? $auth->$groupIdAut->_value : '',
                                    isset($auth->$passwordAut) ? $auth->$passwordAut->_value : '',
                                    self::get_client_ip());
          }
          return $this->{$this->soap_action}($params);
        }
      }
    }

    return FALSE;
  }

  /**
   * @return string
   */
  private function get_client_ip() {
    $client_ip =  isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
    return ($client_ip == '::1') ? '127.0.0.1' : $client_ip;
  }

  /** \brief Find buildnumber
   *
   * @return integer|string
   */
  private function get_buildnumber() {
    $buildnumber = $this->config->get_value('buildnumber', 'setup');
    if (empty($buildnumber) && file_exists('BUILDNUMBER')) {
      $buildnumber = trim(file_get_contents('BUILDNUMBER'));
    }
    if (empty($buildnumber)) {
      $buildnumber = '0';
    }
    return $buildnumber;
  }
  /** \brief Create sample form for testing webservice. This is called of no request is send via browser.
   *
   *
   */

  private function create_sample_forms() {
    if ($sample_header = $this->config->get_value('sample_header', 'setup')) {
      $header_warning = '<p>Ensure that the character set of the request match your browser settings</p>';
    }
    else {
      $sample_header = 'Content-type: text/html; charset=utf-8';
    }
    header($sample_header);

    // Open a known directory, and proceed to read its contents
    if (is_dir($this->xmldir . '/request')) {
      if ($dh = opendir($this->xmldir . '/request')) {
        chdir($this->xmldir . '/request');
        $fnames = $reqs = array();
        while (($file = readdir($dh)) !== false) {
          if (!is_dir($file)) {
            if (preg_match('/html$/', $file, $matches)) $info = file_get_contents($file);
            if (preg_match('/xml$/', $file, $matches)) $fnames[] = $file;
          }
        }
        closedir($dh);

        $html = strpos($info, '__REQS__') ? $info : str_replace('__INFO__', $info, self::sample_form());

        $debug = '';
        $header_warning = '';
        $error = '';
        if ($info || count($fnames)) {
          natsort($fnames);
          foreach ($fnames as $fname) {
            $contents = str_replace("\r\n", PHP_EOL, file_get_contents($fname));
            $contents = addcslashes(str_replace("\n", '\n', $contents), '"');
            $reqs[] = $contents;
            $names[] = $fname;
          }

          $options = '';
          foreach ($reqs as $key => $req)
            $options .= '<option value="' . $key . '">' . $names[$key] ? : '' . '</option>' . "\n";
          if (isset($_GET['debug']) && self::in_house())
            $debug = '<input type="hidden" name="debug" value="' . $_GET['debug'] . '">';

          $html = str_replace('__REQS__', implode("\",\n\"", $reqs), $html);
          $html = str_replace('__XML__', htmlspecialchars(isset($_REQUEST['xml']) ? $_REQUEST['xml'] : ''), $html);
          $html = str_replace('__OPTIONS__', $options, $html);
        }
        else {
          $error = 'No example xml files found...';
        }
        $html = str_replace('__ERROR__', $error, $html);
        $html = str_replace('__DEBUG__', $debug, $html);
        $html = str_replace('__HEADER_WARNING__', $header_warning, $html);
        $html = str_replace('__VERSION__', $this->version, $html);
      }
    }
    echo $html;
  }

  /**
   * @return string
   */
  private function sample_form() {
    return
      '<html><head>
__HEADER_WARNING__
<script language="javascript">
  function useTab() {
    document.f.target=(document.f.usetab.checked ? "tab" : "_blank");
  }
  var reqs = Array("__REQS__");
</script>
</head><body>
  <form target="_blank" name="f" method="POST" accept-charset="utf-8">
    <textarea name="xml" rows=20 cols=90>__XML__</textarea>
    <br /> <br />
    <select name="no" onChange="if (this.selectedIndex) document.f.xml.value = reqs[this.options[this.selectedIndex].value];">
      <option>Pick a test-request</option>
      __OPTIONS__
    </select>
    <input type="submit" name="subm" value="Try me"/>
    &nbsp; Reuse tab <input id="usetab" type="checkbox" name="usetab/" onClick="useTab()">
    __DEBUG__
  </form>
  __INFO__
  __ERROR__
  <p style="font-size:0.6em">Version: __VERSION__</p>
</body></html>';
  }

}
