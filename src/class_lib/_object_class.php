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
 * \brief singleton class to handle object creation
 *
 * Usage: \n
 * _Object::set_value(object, tag-to-set, value); \n
 * _Object::set_namespace(object, tag-to-set, value); \n
 * _Object::set_array_value(object, array-to-set, value); \n
 * _Object::set_element(object, tag-to-set, element, value); \n
 *
 * Example:
 *   instead of:
 *     $test = new stdClass();
 *     $test->tag = new stdClass()
 *     $test->tag->_value = 19;
 *   use:
 *     _Object::set_value($test, 'tag', 19);
 *
 *   instead of:
 *     $test = new stdClass();
 *     $test->tag = new stdClass()
 *     $test->tag->_namespace = 'string';
 *   use:
 *     _Object::set_namespace($test, 'tag', 'string');
 *
 *   instead of:
 *     $test = new stdClass();
 *     $test->tag = new stdClass()
 *     $test->tag->$sub_tag = 19;
 *   use:
 *     _Object::set_element($test, 'tag', 'sub_tag', 19);
 *
 *   instead of:
 *     $test = new stdClass();
 *     $test->arr[] = 19;
 *   use:
 *     _Object::set_aray($test, 'arr', 19);
 *
 *   instead of:
 *     $test = new stdClass();
 *     $help = new stdClass();
 *     $help->_value = 19;
 *     $test->arr[] = $help;
 *   use:
 *     _Object::set_aray_value($test, 'arr', 19);
 *
 * @author Finn Stausgaard - DBC
 **/
class _Object {

  /**
   * Object constructor.
   */
  private function __construct() {
  }

  /** \brief Sets _value on object
   * @param $obj (object) - the object to set
   * @param $name (string)
   * @param $value (mixed)
   * @param $set_empty boolean
   **/
  static public function set_value(&$obj, $name, $value, $set_empty = TRUE) {
    if ($set_empty || !empty($value)) self::set_element($obj, $name, '_value', $value);
  }

  /** \brief Sets _namespace on object
   * @param obj (object) - the object to set
   * @param name (string)
   * @param value (mixed)
   **/
  static public function set_namespace(&$obj, $name, $value) {
    self::set_element($obj, $name, '_namespace', $value);
  }

  /** \brief Sets an object
   * @param obj (object) - the object to set
   * @param name (string)
   * @param value (mixed)
   **/
  static public function set_array(&$obj, $name, $value) {
    self::check_object_set($obj);
    $obj->{$name}[] = $value;
  }

  /** \brief Sets an object
   * @param obj (object) - the object to set
   * @param name (string)
   * @param value (mixed)
   **/
  static public function set_array_value(&$obj, $name, $value) {
    $help = new stdClass();
    $help->_value = $value;
    self::set_array($obj, $name, $help);
  }

  /** \brief Sets an object
   * @param obj (object) - the object to set
   * @param name (string)
   * @param value (mixed)
   **/
  static public function set(&$obj, $name, $value) {
    self::check_object_set($obj);
    $obj->$name = $value;
  }

  /** \brief Sets element on object
   * @param obj (object) - the object to set
   * @param name (string)
   * @param element (string)
   * @param value (mixed)
   **/
  static public function set_element(&$obj, $name, $element, $value) {
    self::check_object_and_name_set($obj, $name);
    $obj->$name->$element = $value;
  }

  /** \brief makes surre the object is defined
   * @param obj (object) - the object to set
   * @param name (string)
   **/
  static private function check_object_and_name_set(&$obj, $name) {
    self::check_object_set($obj);
    self::check_object_set($obj->$name);
  }

  /** \brief makes surre the object is defined
   * @param obj (object) - the object to set
   **/
  static private function check_object_set(&$obj) {
    if (!isset($obj)) $obj = new stdClass();
  }

}
