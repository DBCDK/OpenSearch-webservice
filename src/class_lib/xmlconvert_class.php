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
 * \brief
 *
 * @author Finn Stausgaard - DBC
 */
class xmlconvert {

  /**
   * xmlconvert constructor.
   */
  public function __construct() {
  }

  /** \brief Create an ols--object out of SOAP xml
   *
   * @param $request
   * @return bool|mixed
   */
  public function soap2obj(&$request) {
    if ($request) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = FALSE;
      if (@ $dom->loadXML($request))
        return $this->xml2obj($dom);
    }
    return FALSE;
  }


  /** \brief Converts domdocument object to object.
   *
   * @param $domobj
   * @param string $force_NS
   * @param string $fallbacl_NS Namespace from root to 1st declared namespace
   * @return mixed
   */
  public function xml2obj($domobj, $force_NS = '', $fallback_NS = '') {
    $ret = new stdClass();
    foreach ($domobj->childNodes as $node) {
      $subnode = new stdClass();
      if ($node->nodeName == '#comment') {
        continue;
      }
      if ($force_NS) {
        $subnode->_namespace = htmlspecialchars($force_NS);
      }
      elseif ($node->namespaceURI) {
        $subnode->_namespace = htmlspecialchars($node->namespaceURI);
      }
      elseif ($fallback_NS) {
        $subnode->_namespace = htmlspecialchars($fallback_NS);
      }
      if ($node->nodeName == '#text' || $node->nodeName == '#cdata-section') {
        if (trim($node->nodeValue) == '') {
          continue;
        }
        $subnode->_value = trim($node->nodeValue);
        $localName = '#text';
      }
      else {
        $localName = $node->localName;
        // If a namespace has been declared, then no fallback ns below
        $subnode->_value = $this->xml2obj($node, $force_NS, $node->namespaceURI ? '' : $fallback_NS);
        if ($node->hasAttributes()) {
          $subnode->_attributes = new stdClass();
          foreach ($node->attributes as $attr) {
            $a_nodename = $attr->localName;
            $subnode->_attributes->{$a_nodename} = new stdClass();
            if ($attr->namespaceURI)
              $subnode->_attributes->{$a_nodename}->_namespace = htmlspecialchars($attr->namespaceURI);
            $subnode->_attributes->{$a_nodename}->_value = $attr->nodeValue;
          }
        }
      }
      @ $ret->{$localName}[] = $subnode;
      unset($subnode);
    }

    // Avoid return empty StdClass values for empty elements eg <open:agencyId></open:agencyId> ends up with a to string
    if( count((array)$ret) == 0 ) {
        return null;
    }

    // remove unnecessary level(s) for text-nodes and non-repeated tags
    if ($ret) {
      if (count((array)$ret) == 1 && isset($ret->{'#text'})) {
        $ret = $ret->{'#text'}[0]->_value;
      }
      else {
        foreach ($ret as $tag => $obj) {
          if (count($obj) == 1) {
            $ret->$tag = $obj[0];
          }
        }
        if (is_array($ret)) { reset($ret); }
      }
    }

    return $ret;

  }

}


