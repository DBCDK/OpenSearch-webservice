<?php
/**
*
* This file is part of Open Library System.
* Copyright � 2009, Dansk Bibliotekscenter a/s,
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


/******************************************************
* J�rgen Nielsen, 12/2 2009:                         *
******************************************************/

/*
* Reads an .ini file and parses it into a multidimensional array
* Ex.: $cfg = new inifile("config.ini");
*       print_r($cfg->get());
* Ex. outputs:
* Array (
*     [section1] => Array (
*         [date] => 2009-02-12
*         [foo]  => bar
*      )
*     [section2] => Array (
*         [int]   => 9223372036854775807
*         [float] => 42.7078083332
*      )
*)
*
* quotes force typecasting to strings.
* constants integrated into the results.
* support [] syntax for arrays
* integer strings are typecast to integer if less than PHP_INT_MAX.
* true and false string are automatically converted in booleans.
* Floats typecast to, well, floats.
* null typecast to NULL.
*
* Example ini file:
*
* [Foo]
* lastmodified = 2009-02-12
* max_int = PHP_INT_MAX
* not_integer = 9223372036854775808
* integer = 9223372036854775807
* float   = 42.7078083332
* Bond = "007"
*
* [Multiples]
* more_tests[0] = more_tests
* more_tests[][] = this is a test too
* more_tests[][][] = this is a test 3
* more_tests[foo][bar][doh][dah] = this is a test 4
*
* [Using constants]
* ; Constants can be concatenated with strings, but the string segments must be enclosed in quotes.
* ; Note to users, no joining symbol is used:
* output = "bla bla bla "TEST_TXT" bla bla bla"
*
*/

class inifile {

  /**
  * The .ini file.
  * @var    string
  */
  private $ini_filename = '' ;

  /**
  * The .ini file converted to array.
  * @var    array
  */
  private $ini_file_array = array() ;

  private $inifile_hash = NULL;

  public $error = "";     ///< -


  /**
  *  provides a multidimensional array from the INI file
  *
  * @param $filename string  The .ini file to be processed. string
  * @retval boolean
  **/
  public function __construct($filename) {
    $this->ini_filename = $filename;
    if ($this->ini_file_array = self::parse_ini($filename)) {
      if (self::get_value('use_environment_vars', 'setup')) {
        self::fetch_env_vars();
      }
      return true;
    }
    else {
      $this->error = "Empty or no ini-file found";
      return false;
    }
  }

  /**
  * returns the complete section
  *
  * @param $section string  Section key string
  * @retval array
  **/
  public function get_section($section=NULL) {
    if (is_null($section)) {
      return $this->ini_file_array;
    }
    else {
      if (isset($this->ini_file_array[$section])) {
        return $this->ini_file_array[$section];
      }
    }
    // raise error flag?
    return null;
  }

  /**
  * @retval string
  **/
  public function error() {
    return $this->error();
  }

  /**
  * returns a value from a section
  * if $section is omitted, returns the value of first $key found
  *
  * @param $key string     Value for key in section string
  * @param $section string Section key string
  * @retval mixed
  **/
  public function get_value($key, $section=NULL) {
    if ($section) {
      if (!isset($this->ini_file_array[$section][$key])) {
        return false;
      }
      return $this->ini_file_array[$section][$key];
    }
    else {
      foreach($this->ini_file_array as $section => $section_keys) {
        if (isset($section_keys[$key])) {
          return $section_keys[$key];
        }
      }
    }
    // raise error flag?
    return null;
  }

  /**
  * returns the value of a section or the whole section
  *
  * @param $key string     Value for key in section string
  * @param $section string Section key string
  * @retval mixed
  **/
  public function get($key=NULL, $section=NULL) {
    if (is_null($key)) {
      return $this->get_section($section);
    }
    return $this->get_value($key,$section);
  }

  /**
  * returns the hash value of the inifile
  *
  * @retval integer - md5 sum of the inifile
  **/
  public function get_inifile_hash() {
    return $this->inifile_hash;
  }

  /**
  * set a section in accordance with the specified key
  *
  * @param $section string Section key string
  * @param $array array    Array of keys/values (array)
  * @retval mixed
  **/
  public function set_section($section, $array) {
    if (!is_array($array)) {
      return false;
    }
    return $this->ini_file_array[$section] = $array;
  }

  /**
  * sets a new value in a section
  *
  * @param $section string Section key
  * @param $key string     Key
  * @param $value mixed    Array of keys/values
  * @retval mixed
  **/
  public function set_value($key, $section, $value) {
    if ($this->ini_file_array[$section][$key] = $value) {
      return true;
    }
  }

  /**
  * sets a new value in a section or an entire, new section
  *
  * @param $section string Section key
  * @param $key string     Key
  * @param $value mixed    Array of keys/values
  * @retval boolean
  **/
  public function set($key, $section, $value=NULL) {
    if (is_array($key) && is_null($value)) {
      return $this->set_section($section, $key);
    }
    return $this->set_value($key, $section, $value);
  }

  /**
  * saves the entire array into the INI file
  *
  * @param $filename string
  * @retval boolean
  **/
  public function save($filename = null) {
    if ($filename == null) {
      $filename = $this->ini_filename;
    }
    if (is_writeable($filename)) {
      $file_handle = fopen($filename, "w");
      foreach($this->ini_file_array as $section => $array) {
        fwrite($file_handle, "[" . $section . "]\n");
        if (is_array($array)) {
          foreach($array as $key => $value) {
            $this->write_value($file_handle, $key, $value);
          }
        }
        fwrite($file_handle, "\n");
      }
      fclose($file_handle);
      return true;
    }
    else {
      return false;
    }
  }

  /**
  * write line in .ini file
  *
  * @param $file_handle resource
  * @param $key string
  * @param $value mixed
  * @param $prefix string
  **/
  private function write_value($file_handle, $key, $value, $prefix = '') {
    if (is_array($value)) {
      $key = $prefix.$key;
      foreach($value as $n => $arr_value) {
        $this->write_value($file_handle, "[$n]", $arr_value, $key);
      }
    }
    else {
      $key = $prefix.$key;
      if      (is_null($value))   fwrite($file_handle, $key. ' = null'."\n");
      else if ($value === none)    fwrite($file_handle, "$key = none\n");
      else if ($value === 0)       fwrite($file_handle, "$key = 0\n");
      else if ($value === 1)       fwrite($file_handle, "$key = 1\n");
      else if ($value === false)   fwrite($file_handle, "$key = false\n");
      else if ($value === true)    fwrite($file_handle, "$key = true\n");
      else if (is_string($value))  fwrite($file_handle, "$key = \"$value\"\n");
      else                           fwrite($file_handle, "$key = $value\n");
    }
  }


  /**
  * parse .ini file, and return array
  *
  * @param $filepath string
  * @retval array
  **/
  private function parse_ini ($filepath) {
    $ini = @ file($filepath);
    if (!$ini) {
      return false;
    }
    $this->inifile_hash = md5(dirname($_SERVER['SCRIPT_FILENAME']) . $filepath . implode('', $ini));
    $sections = array();
    $values = array();
    $globals = array();
    $result = array();
    $i = 0;
    foreach($ini as $line) {
      $line = trim($line);
      // Comments
      if ($line == '' || $line[0] == ';') {
        continue;
      }
      // Sections
      if ($line[0] == '[') {
        $sections[] = substr($line, 1, -1);
        $i++;
        continue;
      }
      // Key-value pair
        if (strpos($line, '=')) {
          list($key, $value) = explode('=', $line, 2);
        }
        else {
          $key = $line;
          $value = '';
        }
      $key = trim($key);
      $value = trim($value);

      if ($i == 0) {
        $globals = self::parse_ini_array($globals,$key,$value);
      }
      else {
        if (! array_key_exists($i-1,$values)) $values[$i-1] = array();
        $values[ $i-1 ] = self::parse_ini_array($values[$i-1],$key,$value);
      }
    }

    for ($j=0; $j<$i; $j++) {
      if (isset($values[ $j ]))
        $result[ $sections[ $j ] ] = $values[ $j ];
    }

    return $result + $globals;
  }


  /**
  * parse .ini line values for array structures and return array, if any.
  *
  * @param $res_array array
  * @param $key string
  * @param $value string
  * @retval array
  **/
  private function parse_ini_array($res_array, $key, $value) {

    if ($pos = strpos($key,'[')) {
      $key_suffix = substr($key,$pos);
      if (strpos($key_suffix, "'") === FALSE) {
        $key_suffix = str_replace('[', "['", $key_suffix);
        $key_suffix = str_replace(']', "']", $key_suffix);
        $key_suffix = str_replace("['']", "[]", $key_suffix);
      }
      $key = substr($key,0,$pos);
      if (!isset($res_array[$key]))
        $res_array[$key] = array();
      $eval_this = '$res_array[\''.$key.'\']'.$key_suffix.' = self::parse_constants(self::parse_reserved_words(\''.$value.'\'));';
//                echo "**********\n key_suffix:$key_suffix\n" . $eval_this . "\n parse_const: " . self::parse_constants(self::parse_reserved_words("'".$value."'")) . "\n";
      eval($eval_this);
//                exit;
    }
    else {
      $value = self::parse_reserved_words($value);
      $value = self::parse_constants($value);
      $res_array[$key] = $value;
    }
    return $res_array;

  }



  /**
  * Apply typecasting to values
  * Values enclosed by quotes are typecast to strings
  * Values null, no and false results in "" (bool false), yes and true results in "1" (bool true)
  * Integers are typecast to (int), if less than, or equal to PHP_INT_MAX
  * Floats are typecast to (float)
  *
  * @param $val string
  * @retval mixed
  **/
  private function parse_reserved_words($val) {
    if (substr($val,0,1) == '"' && substr($val,-1,1) == '"')
      return (string)$val;
    else if (strtolower($val) === 'null')      return null;
    else if (strtolower($val) === 'no')        return (bool)false;
    else if (strtolower($val) === 'yes')       return (bool)true;
    else if (strtolower($val) === 'false')     return (bool)false;
    else if (strtolower($val) === 'true')      return (bool)true;
    else if ((string)floatval($val) === $val)  return floatval($val);
    else if ((string)intval($val) === $val)     return intval($val);
    else return (string)$val;
  }



  /**
  * Constants may also be parsed in the ini file so if you define a constant as an
  * ini value before running parse_ini_file(), it will be integrated into the results.
  * Constants can be concatenated with strings, but the string segments must be enclosed
  * in quotes (note: no joining symbol is used)
  *
  * @param $val string
  * @retval string
  **/
  private function parse_constants($val) {

    if (defined($val))
      return constant($val);

    $pos = strpos($val, '"');
    if ($pos === FALSE)
      return str_replace('%22', '"', $val);

    $parts = explode('"', $val);
    for ($j=0; $j < sizeof($parts); $j++)
      if (defined($parts[$j]))
        $parts[$j] = constant($parts[$j]);

    $val = implode('',$parts);

    return (string)$val;

  }

  /**
  * Overwrite inifile setting in corresponding env var is set
  *
  **/
  private function fetch_env_vars() {
    foreach ($this->ini_file_array as &$section) {
      foreach ($section as $key => &$value) {
        if ($new = getenv($key)) {
          if (is_scalar($value)) {
            $value = $new;
          }
          if (is_array($value)) {
            $value = explode('\n', $new);
          }
        }
      }
    }
  }



}

