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


/** \brief Convert ols-object to json, xml, php
 *
 * An OLS object contains the data in _value
 * It may have a namespace in _namespace
 * and it may have attributes in _attributes
 * and it may have a cdata-flag set in _cdata (only used in xml-output)
 *
 * Example:
 *   $obj->tagname->_value = "A&A";
 *   will convert to a xml doc like: <tagname>A&amp;A</tagname>
 *                   json like: {"tagname":{"$":"A&A"},"@namespaces":null}
 *
 * Example:
 *   $obj->tagname->_value = "A&A";
 *   $obj->tagname->_namespace = "http://some.namespace.com/";
 *   will convert to a xml doc like: <ns1:tagname xmlns:ns1="http://some.namespace.com/">A&amp;A</ns1:tagname>
 *                   json like: {"tagname":{"$":"A&A","@":"ns1"},"@namespaces":{"ns1":"http:\/\/some.namespace.com\/"}}
 *
 * Example:
 *   $obj->tagname->_value = "A&A";
 *   $obj->tagname->_attributes->attr->_value = "ATTR";
 *   will convert to a xml doc like: <tagname attr="ATTR">A&amp;A</tagname>
 *                   json like: {"tagname":{"$":"A&A","@attr":{"$":"ATTR"}},"@namespaces":null}
 *
 * Example:
 *   $obj->tagname->_value = "A&A";
 *   $obj->tagname->_attributes->attr->_value = "ATTR";
 *   $obj->tagname->_attributes->attr->_namespace = "http://some.namespace.com/";
 *   will convert to a xml doc like: <tagname ns1:attr="ATTR" xmlns:ns1="http://some.namespace.com/">A&amp;A</tagname>
 *                   json like: {"tagname":{"$":"A&A","@attr":{"$":"ATTR","@":"ns1"}},"@namespaces":{"ns1":"http:\/\/some.namespace.com\/"}}
 *
 * Example:
 *   $obj->tagname->_value = "A&A";
 *   $obj->tagname->_cdata = TRUE;
 *   will convert to a xml doc like: <tagname><![CDATA[A&A]]></tagname>
 *                   json like: {"tagname":{"$":"A&A"},"@namespaces":null}
 *
 * @author Finn Stausgaard - DBC
 */

define('NO_PREFIX', '_NO_PREFIX_');

/**
 * Class objconvert
 */
class objconvert {

  private $tag_sequence = array();
  private $namespaces = array();
  private $used_namespaces = array();
  private $default_namespace;
  private $timer;

  /** \brief -
   * @param $xmlns string|array -
   * @param $tag_seq string -
   * @param $timer string -
   */
  public function __construct($xmlns = '', $tag_seq = '', $timer = NULL) {
    if ($xmlns) {
      foreach ($xmlns as $prefix => $ns) {
        if ($prefix == 'NONE' || $prefix == '0')
          $prefix = '';
        $this->add_namespace($ns, $prefix);
      }
    }
    $this->tag_sequence = $tag_seq;
    $this->timer = $timer;
  }

  /** \brief -
   * @param $ns string -
   */
  public function set_default_namespace($ns) {
    $this->default_namespace = $ns;
    if ($this->namespaces[$ns] == '') {
      unset($this->namespaces[$ns]);         // remove deprecated setup
    }
  }

  /** \brief Convert ols-object to json
   * @param $obj object -
   * @return string
   */
  public function obj2json($obj) {
    if ($this->timer) $this->timer->start('obj2json');
    $o_ns = new stdClass();
    foreach ($this->namespaces as $ns => $prefix) {
      if ($prefix)
        self::set_object_value($o_ns, $prefix, $ns);
      else
        self::set_object_value($o_ns, '$', $ns);
    }
    $json_obj = $this->obj2badgerfish_obj($obj);
    self::set_object_value($json_obj, '@namespaces', $o_ns);
    if ($this->timer) $this->timer->stop('obj2json');
    return json_encode($json_obj);
  }

  /** \brief compress ols object to badgerfish-inspired object
   * @param $obj object -
   * @return object
   */
  private function obj2badgerfish_obj($obj) {
    if ($obj) {
      $ret = new stdClass();
      foreach ($obj as $key => $o) {
        if (is_array($o)) {
          foreach ($o as $o_i) {
            $ret->{$key}[] = $this->build_json_obj($o_i);
          }
        }
        else
          self::set_object_value($ret, $key, $this->build_json_obj($o));
      }
      return $ret;
    } else {
      return null;
    }
  }

  /** \brief convert one object
   * @param $obj object -
   * @return object
   */
  private function build_json_obj($obj) {
    $ret = null;
    if (isset($obj->_value)) {
      if (is_scalar($obj->_value))
        self::set_object_value($ret, '$', html_entity_decode($obj->_value));
      else
        $ret = $this->obj2badgerfish_obj($obj->_value);
      if (!empty($obj->_attributes)) {
        foreach ($obj->_attributes as $aname => $aval) {
          self::set_object_value($ret, '@' . $aname, $this->build_json_obj($aval));
        }
      }
      if (!empty($obj->_namespace))
        self::set_object_value($ret, '@', $this->get_namespace_prefix($obj->_namespace));
    }
    return $ret;
  }

  /** \brief experimental php serialized
   * @param $obj object -
   * @return string
   */
  public function obj2phps($obj) {
    return serialize($obj);
  }

  /** \brief Convert ols-object to xml with namespaces
   * @param $obj object -
   * @return string
   */
  public function obj2xmlNs($obj) {
    $this->used_namespaces = array();
    $xml = $this->obj2xml($obj);
    $used_ns = $this->get_used_namespaces_as_header();
    if ($used_ns && $i = strpos($xml, '>'))
      $xml = substr($xml, 0, $i) . $used_ns . substr($xml, $i);
    return $this->xml_header() . $xml;
  }

  /** \brief Convert ols-object to soap
   * @param $obj object -
   * @param $soap_ns string
   * @return string
   */
  public function obj2soap($obj, $soap_ns = 'http://schemas.xmlsoap.org/soap/envelope/') {
    $this->used_namespaces = array();
    $xml = $this->obj2xml($obj);
    return $this->xml_header() .
    '<SOAP-ENV:Envelope xmlns:SOAP-ENV="' . $soap_ns . '"' .
    $this->get_used_namespaces_as_header() . '><SOAP-ENV:Body>' .
    $xml . '</SOAP-ENV:Body></SOAP-ENV:Envelope>';
  }

  /** \brief
   * @return string
   */
  private function get_used_namespaces_as_header() {
    $used_ns = '';
    foreach ($this->namespaces as $ns => $prefix) {
      if (isset($this->used_namespaces[$ns]) || empty($prefix))
        $used_ns .= ' xmlns' . ($prefix ? ':' . $prefix : '') . '="' . $ns . '"';
    }
    if ($this->default_namespace && $this->used_namespaces[NO_PREFIX]) {
      $used_ns .= ' xmlns="' . $this->default_namespace . '"';
    }
    return $used_ns;
  }

  /** \brief UTF-8 header
   * @return string
   */
  private function xml_header() {
    return '<?xml version="1.0" encoding="UTF-8"?>';
  }

  /** \brief Convert ols-object to xml
   *
   * used namespaces are returned in this->namespaces
   * namespaces can be preset with add_namespace()
   *
   * @param $obj object -
   * @return string
   */
  public function obj2xml($obj) {
    if ($this->timer) $this->timer->start('obj2xml');
    $ret = '';
    if ($obj) {
      foreach ($obj as $tag => $o) {
        if (is_array($o)) {
          foreach ($o as $o_i) {
            $ret .= $this->build_xml($tag, $o_i);
          }
        }
        else
          $ret .= $this->build_xml($tag, $o);
      }
    }
    if ($this->timer) $this->timer->stop('obj2xml');
    return $ret;
  }

  /** \brief handles one node
   * @param $tag string -
   * @param $obj object -
   * @return string
   */
  private function build_xml($tag, $obj) {
    $attr = $prefix = $ret = '';
    if (!empty($obj->_attributes)) {
      foreach ($obj->_attributes as $a_name => $a_val) {
        if (!empty($a_val->_namespace))
          $a_prefix = $this->set_prefix_separator($this->get_namespace_prefix($a_val->_namespace));
        else {
          $this->used_namespaces[NO_PREFIX] = TRUE;
          $a_prefix = '';
        }
        $attr .= ' ' . $a_prefix . $a_name . '="' . htmlspecialchars($a_val->_value) . '"';
// prefix in value hack
        $this->set_used_prefix($a_val->_value);
      }
    }
    if (!empty($obj->_namespace))
      $prefix = $this->set_prefix_separator($this->get_namespace_prefix($obj->_namespace));
    else
      $this->used_namespaces[NO_PREFIX] = TRUE;
    if (isset($obj->_value) && is_scalar($obj->_value))
      if (!empty($obj->_cdata))
        return $this->tag_me($prefix . $tag, $attr, '<![CDATA[' . $obj->_value . ']]>');
      else
        return $this->tag_me($prefix . $tag, $attr, htmlspecialchars($obj->_value));
    else
      return @ $this->tag_me($prefix . $tag, $attr, $this->obj2xml($obj->_value));
  }

  /** \brief Updates used_namespaces from prefix in $val
   * @param $val string -
   */
  private function set_used_prefix($val) {
    if ($p = strpos($val, ':')) {
      foreach ($this->namespaces as $ns => $prefix) {
        if ($prefix == substr($val, 0, $p)) {
          $this->used_namespaces[$ns] = TRUE;
          break;
        }
      }
    }
    else {
      $this->used_namespaces[NO_PREFIX] = TRUE;
    }
  }

  /** \brief returns prefixes and store namespaces
   * @param $ns string -
   * @return string
   */
  private function get_namespace_prefix($ns) {
    if (empty($this->namespaces[$ns])) {
      $i = 1;
      while (in_array('ns' . $i, $this->namespaces)) $i++;
      $this->namespaces[$ns] = 'ns' . $i;
    }
    $this->used_namespaces[$ns] = TRUE;
    return $this->namespaces[$ns];
  }

  /** \brief Separator between prefix and tag-name in xml
   * @param $prefix string -
   * @return string
   */
  private function set_prefix_separator($prefix) {
    if ($prefix) return $prefix . ':';
    else return $prefix;
  }

  /** \brief Adds known namespaces
   * @param $namespace string -
   * @param $prefix string -
   */
  public function add_namespace($namespace, $prefix) {
    $this->namespaces[$namespace] = $prefix;
    asort($this->namespaces);
  }

  /** \brief Returns used namespaces
   * @return array
   */
  public function get_namespaces() {
    return $this->namespaces;
  }

  /** \brief Set namespace on all object nodes
   * @param $obj mixed -
   * @param $ns string -
   * @return mixed
   */
  public function set_obj_namespace($obj, $ns) {
    if (empty($obj) || is_scalar($obj))
      return $obj;
    if (is_array($obj)) {
      $ret = array();
      foreach ($obj as $key => $val) {
        $ret[$key] = $this->set_obj_namespace($val, $ns);
      }
    }
    else {
      $ret = new stdClass();
      foreach ($obj as $key => $val) {
        $ret->$key = $this->set_obj_namespace($val, $ns);
        if ($key === '_value')
          $ret->_namespace = $ns;
      }
    }
    return $ret;
  }

  /** \brief Set namespace on all object nodes but attributes
   * @param $obj mixed -
   * @param $ns string -
   * @param $in_attributes boolean -
   * @return mixed
   */
  public function set_obj_namespace_on_tags($obj, $ns, $in_attributes = FALSE) {
    if (empty($obj) || is_scalar($obj))
      return $obj;
    if (is_array($obj)) {
      $ret = array();
      foreach ($obj as $key => $val) {
        $ret[$key] = $this->set_obj_namespace_on_tags($val, $ns, $in_attributes);
      }
    }
    else {
      $ret = new stdClass();
      foreach ($obj as $key => $val) {
        $ret->$key = $this->set_obj_namespace_on_tags($val, $ns, $in_attributes || $key == '_attributes');
        if ($key === '_value' && !$in_attributes)
          $ret->_namespace = $ns;
      }
    }
    return $ret;
  }


    /**
     * @param $obj
     * @param $ns
     * @param bool $force
     * @param bool $in_attributes
     * @return array|stdClass
     */
  public function set_obj_namespace_v2($obj, $ns, $force = FALSE, $in_attributes = FALSE) {
    if (empty($obj) || is_scalar($obj)) return $obj;

    if (is_array($obj)) {
      $ret = array();
      foreach ($obj as $key => $val) {
        $ret[$key] = $this->set_obj_namespace_v2($val, $ns, $force, $in_attributes);
      }
    }
    else {
      $ret = new stdClass();
      foreach ($obj as $key => $val) {
        if (($key === '_attributes') && !$in_attributes) {
          $ret->$key = $val;
        }
        elseif ($key === '_namespace') {
          $ret->$key = ($force || empty($val)) ? $ns : $val;
        }
        else {
          $ret->$key = $this->set_obj_namespace_v2($val, $ns, $force, $in_attributes);
          if ($key === '_value' && (empty($ret->_namespace) || $force)) {
            $ret->_namespace = $ns;
          }
        }
      }
    }
    return $ret;
  }


    /** \brief produce balanced xml
   * @param $tag string -
   * @param $attr string -
   * @param $val string -
   * @return string
   */
  public function tag_me($tag, $attr, $val) {
    if ($tag == '#text') {
      return $val;
    }
    else {
      $space = ($attr && $attr[0] <> ' ') ? ' ' : '';
      return '<' . $tag . $space . $attr . '>' . $val . '</' . $tag . '>';
    }
  }

  /** \brief makes sure the object is defined and set value
   * @param obj (object) - the object to set
   * @param name (string)
   * @param $val string -
   **/
  private function set_object_value(&$obj, $name, $val) {
    self::ensure_object_set($obj);
    self::ensure_object_set($obj->$name);
    $obj->$name = $val;
  }

  /** \brief makes sure the object is defined
   * @param obj (object) - the object to set
   **/
   private function ensure_object_set(&$obj) {
    if (!isset($obj)) $obj = new stdClass();
  }

}

