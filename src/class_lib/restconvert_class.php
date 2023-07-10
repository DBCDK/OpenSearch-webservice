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
 * \brief Converts an url to the 'corresponding" soap-request.
 *
 * Converting is controlled by the [rest] section from the config-object (the ini-file)
 *
 * @author Finn Stausgaard - DBC
 **/
class Restconvert {

  //private $charset = 'ISO-8859-1';
  private $charset = "utf-8";
  private $soap_header;
  private $soap_footer;
  private $default_namespace = '';

  /**
   * \brief constructor
   *
   * @param $namespace string -
   */
  public function __construct($namespace = '') {
    $this->soap_header = '<?xml version="1.0" encoding="%s"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"%s><SOAP-ENV:Body>';
    $this->soap_footer = '</SOAP-ENV:Body></SOAP-ENV:Envelope>';
    if ($namespace)
      $this->default_namespace = ' xmlns="' . $namespace . '"';
  }

  /** \brief Transform REST parameters to SOAP-request
   *
   * @param $config object - the config object from the ini-file
   * @return string - Soap wrapped xml
   */
  public function rest2soap(&$config) {
    $soap_actions = $config->get_value('soapAction', 'setup');
    $action_pars = $config->get_value('action', 'rest');
    $action = $this->get_post('action');
    $all_actions = isset($action_pars['ALL']) ? $action_pars['ALL'] : array();
    if ($action
      && is_array($soap_actions) && is_array($action_pars)
      && $soap_actions[$action] && $action_pars[$action]
    ) {
      if ($this->get_post('charset')) $this->charset = $this->get_post('charset');
      if ($node_value = $this->build_xml(array_merge($all_actions, $action_pars[$action]),
                                         explode('&', $_SERVER['QUERY_STRING']))
      ) {
        return sprintf($this->soap_header, $this->charset, $this->default_namespace)
        . $this->rest_tag_me($soap_actions[$action], $node_value)
        . $this->soap_footer;
      }
    }
    return null;
  }

  /** \brief Build xml controlled by $action with data from query
   *
   * @param $action array - list of accepted parameters and the xml-structure they are to create
   * @param $query array - of parameters and values like parameter=value
   * @return string - xml
   */
  private function build_xml($action, $query) {
    $ret = null;
    foreach ($action as $key => $tag) {
      if (is_array($tag)) {
        $data = $this->build_xml($tag, $query);
        if (isset($data)) $ret .= $this->rest_tag_me($key, $data);
      }
      else {
        foreach ($query as $parval) {
          list($par, $val) = $this->par_split($parval);
          if ($tag == $par) $ret .= $this->rest_tag_me($tag, htmlspecialchars($val));
        }
      }
    }
    return $ret;
  }

  /** \brief Helper function to get a parameter from _GET or _POST
   *
   * @param $par string
   * @return string - the associated value of $par
   */
  private function get_post($par) {
    // after php 7.0 return ($_GET[$par] ?? $_POST[$par] ?? NULL);
    return (isset($_GET[$par]) ? $_GET[$par] : (isset($_POST[$par]) ? $_POST[$par] : NULL));
  }

  /** \brief Helper function to split values like parameter=value
   *
   * @param $parval string
   * @return array - of paramter and value
   */
  private function par_split($parval) {
    if($parval && str_contains($parval, '=')) {
      list($par, $val) = explode('=', $parval, 2);
      return array(preg_replace("/\[[^]]*\]/", "", urldecode($par)), urldecode($val));
    } else {
      return array(preg_replace("/\[[^]]*\]/", "", urldecode($parval)), '');
    }
  }

  /** \brief Helper function to produce balanced xml
   *
   * @param $tag string
   * @param $val string
   * @return string - balanced xml string
   */
  function rest_tag_me($tag, $val) {
    if (!isset($val)) return '';

    if ($i = strrpos($tag, '.'))
      $tag = substr($tag, $i + 1);
    return "<$tag>$val</$tag>";
  }
}
