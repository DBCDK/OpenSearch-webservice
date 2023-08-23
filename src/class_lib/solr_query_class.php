<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2013, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Finn Stausgaard - DBC
 */

/**
 * Parse a cql-search and return the corresponding solr-search
 *
 */

require_once('class_lib/cql2tree_class.php');

define('DEVELOP', FALSE);


/**
 * Class SolrQuery
 */
class SolrQuery {

  var $cql_dom;  ///< -
  // full set of escapes as seen in the solr-doc. 
  // We use those who so far has been verified and does not conflict with cql behaviour, like ? and *
  //var $solr_escapes = array('\\','+','-','&&','||','!','(',')','{','}','[',']','^','"','~','*','?',':');
  var $solr_escapes = array('+', '-', '!', '{', '}', '[', ']', '^', '~', ':');  ///< -
  var $solr_ignores = array();         ///< this should be kept empty
  var $phrase_index = array();  ///< -
  var $search_term_format = array();  ///< -
  var $profile_filters = [];   ///< -
  var $holdings_filter = '';   ///< -
  var $best_match = FALSE;  ///< -
  var $operator_translate = array();  ///< -
  var $indexes = array();  ///< -
  var $default_slop = 9999;  ///< -
  var $error = array();  ///< -
  var $interval;
  var $cqlns = array();  ///< namespaces for the search-fields
  var $v2_v3 = array(    ///< -
                         '150005' => '150005-artikel',
                         '150008' => '150008-academic',
                         '150012' => '150012-leksikon',
                         '150014' => '150014-album',
                         '150015' => '870970-basis',
                         '150016' => '870971-forfweb',
                         '150017' => '870971-faktalink',
                         '150018' => '150018-danhist',
                         '150021' => '150021-bibliotek',
                         '150023' => '150023-sicref',
                         '150025' => '150008-public',
                         '150027' => '150021-fjern',
                         '150028' => '870970-basis',
                         '150030' => '870970-spilmedier',
                         '150032' => '150018-samfund',
                         '150033' => '150033-dandyr',
                         '150034' => '150018-religion',
                         '150039' => '150015-forlag',
                         '150040' => '150033-verdyr',
                         '150043' => '150043-atlas',
                         '150048' => '870970-basis',
                         '150052' => '870970-basis',
                         '150054' => '150018-biologi',
                         '150055' => '150018-fysikkemi',
                         '150056' => '150018-geografi',
                         '159002' => '159002-lokalbibl',
                         '870971' => '870971-avis',
                         '870973' => '870973-anmeld',
                         '870976' => '870976-anmeld');    ///< translate v2 rec.id to v3 rec.id

  /** \brief constructor
   *
   * @param $repository string
   * @param $config string
   * @param $language string
   * @param $profile_filters array
   */
  public function __construct($repository, $config = '', $language = '', $profile_filters = '') {
    $this->cql_dom = new DomDocument();
    @ $this->cql_dom->loadXML($repository['cql_settings']);

    $this->best_match = ($language == 'bestMatch');
    // No boolean translate in strict cql
    //if ($language == 'cqldan') {
    //$this->operator_translate = array('og' => 'and', 'eller' => 'or', 'ikke' => 'not');
    //}
    // not strict cql  $this->set_operators($language);
    $this->set_cqlns();
    $this->set_indexes();
    //$this->ignore = array('/^prox\//');

    $this->interval = array('<' => '[* TO %s}',
                            '<=' => '[* TO %s]',
                            '>' => '{%s TO *]',
                            '>=' => '[%s TO *]');

    if ($config) {
      $this->phrase_index = $config->get_value('phrase_index', 'setup');
      $this->search_term_format = $repository['handler_format'];
    }
    $this->profile_filters = $profile_filters;
    ini_set('xdebug.max_nesting_level', 1000);  // each operator can cause a recursive call 
  }


  /** \brief Parse a cql-query and build the solr edismax search string
   *
   * @param $query string
   * @return struct
   */
  public function parse($query) {
    $parser = new CQL_parser();
    $parser->set_prefix_namespaces($this->cqlns);
    $parser->set_indexes($this->indexes);
    // not strict cql $parser->set_boolean_translate($this->operator_translate);
    $parser->parse($query);
    $tree = $parser->result();
    $diags = $parser->get_diagnostics();
    if ($diags) {
      $ret['error'] = $diags;;
    }
    else {
      $trees = self::split_tree($tree);
      $ret['edismax'] = self::trees_2_edismax($trees);
      $ret['best_match'] = self::trees_2_bestmatch($trees);
      $ret['operands'] = self::trees_2_operands($trees);
      if (count($ret['operands']) && empty($ret['edismax']['q'])) {
        $ret['edismax']['q'][] = '*';
      }
      if ($this->error) {
        $ret['error'] = $this->error;;
      }
    }
    //var_dump($query); var_dump($trees); var_dump($ret); die();
    return $ret;
  }


  /** \brief build a boost string
   * @param $boosts array - boost registers and values
   * @return string
   */
  public function make_boost($boosts) {
    $ret = '';
    if (is_array($boosts)) {
      foreach ($boosts as $idx => $val) {
        if ($idx && $val)
          $ret .= ($ret ? ' ' : '') . $idx . '^' . $val;
      }
    }
    return $ret;
  }

  // ------------------------- Private functions below -------------------------------------

  /** \brief convert all cql-trees to edismax-strings
   * @param @trees array - of trees
   * @return array
   */
  private function trees_2_edismax($trees) {
    $ret = array('q' => array(), 'fq' => array());
    $ret['handler'] = $ret;
    $add_params = array();
    foreach ($trees as $idx => $tree) {
      list($edismax, $handler, $type, $rank_field) = self::tree_2_edismax($tree, $add_params);
      if ($rank_field) {
        $ret['ranking'][$idx] = $rank_field;
      }
      $ret['handler'][$type][$idx] = $handler;
      $ret[$type][$idx] = $edismax;
    }
    self::join_handler_nodes($ret);
    if ($add_params) {
      $add_params['uf'] = '* _query_';
      $ret['add_params'] = $add_params;
    }
    //var_dump($ret); die();
    return $ret;
  }


  /** \brief locate handlers (if any) and join nodes for each handler
   * @param $solr_nodes array - one or more solr AND-nodes
   */
  private function join_handler_nodes(&$solr_nodes) {
    $found = array();
    foreach (array('q', 'fq') as $type) {
      foreach ($solr_nodes['handler'][$type] as $handler) {
        if ($handler) {
          self::apply_handler($solr_nodes, $type, $handler);
          $found[$handler][$type] = TRUE;
          break;
        }
      }
    }
    foreach ($found as $handler) {
      if (count($handler) > 1) {
        $this->error[] = self::set_error(18, 'Mixed filter use for the applied indexes');
      }
    }
  }

  /** \brief locate handlers
   * @param $solr_nodes array - one or more solr AND nodes
   * @param $type string - - q og fq
   * @param $handler string - - name of handler
   */
  private function apply_handler(&$solr_nodes, $type, $handler) {
    if ($handler && ($format = $this->search_term_format[$handler][$type])) {
      $q = array();
      foreach ($solr_nodes['handler'][$type] as $idx => $h) {
        if ($handler == $h) {
          $q[] = $solr_nodes[$type][$idx];
          unset($solr_nodes[$type][$idx]);
          $last_idx = $idx;
        }
      }
      if($this->profile_filters['holdings']) {
        $profile_filter = '(' . $this->profile_filters['holdings'] . ' AND (' . sprintf($format, '$fq_' . $handler) . '))';
        if($this->profile_filters['bibliographic']) {
          // Has both holdings-filtered and "ordinary" sources
          $profile_filter .= ' OR ' . $this->profile_filters['bibliographic'];
        }
        $solr_nodes[$type][$last_idx] = $profile_filter;

        // Add profile filter to holdings query
        $q[] = $this->profile_filters['holdings'];
        $handler_q = implode(' AND ', $q);
        $solr_nodes['handler_var'][$handler] = 'fq_' . $handler . '=' . urlencode($handler_q);
      } else { // No holdings-filtered sources in profile
        $solr_nodes[$type][$last_idx] = $this->profile_filters['bibliographic'];
      }
    }
  }

  /** \brief convert on cql-tree to edismax-string
   * @param $node array - of tree
   * @param $add_params - extra parameters for embedded (parent) queries ie fh0, fh1, ...
   * @param $level integer - level of recursion
   * @return array - The term, the associated search handler and the query type (q or fq)
   */
  private function tree_2_edismax($node, &$add_params, $level = 0) {
    static $q_type, $ranking;
    $term_handler = null;
    if ($level == 0) {
      $q_type = 'fq';
      $ranking = '';
    }
    if ($node['type'] == 'boolean') {
      list($left_term, $left_handler) = self::tree_2_edismax($node['left'], $add_params, $level + 1);
      list($right_term, $right_handler) = self::tree_2_edismax($node['right'], $add_params, $level + 1);
      if ($left_handler == $right_handler) {
        $term_handler = $right_handler;
      }
      else {
        $fh = 'fh' . count($add_params);
        if (@$this->search_term_format[$left_handler]) {
          $add_params[$fh] = $left_term;
          $left_term = sprintf($this->search_term_format[$left_handler]['q'], '$' . $fh);
        }
        elseif (@$this->search_term_format[$right_handler]) {
          $add_params[$fh] = $right_term;
          $right_term = sprintf($this->search_term_format[$right_handler]['q'], '$' . $fh);
        }
        else {
          $this->error[] = self::set_error(18, self::node_2_index($node['left']) . ', ' . self::node_2_index($node['right']));
        }
      }
      $ret = '(' . $left_term . ' ' . strtoupper($node['op']) . ' ' . $right_term . ')';
    }
    else {
      if (!self::is_filter_field($node['prefix'], $node['field'])) {
        $q_type = 'q';
      }
      $term_handler = self::get_term_handler($node['prefix'], $node['field']);
      $ret = self::make_solr_term($node['term'], $node['relation'], $node['prefix'], $node['field'], self::set_slop($node));
    }
    $ranking = self::use_rank($node, $ranking);
    return array($ret, $term_handler, $q_type, $ranking);
  }

  /** \brief modifies slop if word or string modifier is used
   * @param $node array - modifiers and slop for the node
   * @return string
   */
  private function set_slop($node) {
    if ($node['relation'] == 'adj') return '0';
    elseif (!empty($node['modifiers']['word'])) return '9999';
    elseif (!empty($node['modifiers']['string'])) return '0';
    else return $node['slop'];
  }

  /** \brief if relation modifier "relevant" is used, creates ranking info
   * @param $node array
   * @param $ranking string
   * @return string
   */
  private function use_rank($node, $ranking) {
    if (!empty($node['modifiers']['relevant'])) {
      if ($ranking) {
        $this->error[] = self::set_error(21, 'relation modifier relevant used more than once');
      }
      else {
        return $node['prefix'] . '.' . $node['field'];
      }
    }
    return $ranking;
  }

  /** \brief Set an error to send back to the client
   * @param $no integer
   * @param $details string
   * @return array - diagnostic structure
   */
  private function set_error($no, $details = '') {
    /* Total list at: http://www.loc.gov/standards/sru/diagnostics/diagnosticsList.html */
    static $message =
      array(18 => 'Unsupported combination of indexes',
            21 => 'Unsupported combination of relation modifers');
    return array('no' => $no, 'description' => $message[$no], 'details' => $details);  // pos is not defined
  }

  /** \brief convert all cql-trees to edismax-bestmatch-strings and set sort-scoring
   * @param $trees array - of trees
   * @return array -
   */
  private function trees_2_bestmatch($trees) {
    foreach ($trees as $tree) {
      $ret[] = self::tree_2_bestmatch($tree);
    }
    $q = implode(' OR ', $ret);
    $sort = self::make_bestmatch_sort($q);
    return array('q' => array($q), 'fq' => array(), 'sort' => $sort);
  }

  /** \brief convert all cql-trees to edismax-bestmatch-strings and set sort-scoring
   * @param $node array - of trees
   * @param $level integer - level of recursion
   * @return array -
   */
  private function tree_2_bestmatch($node, $level = 0) {
    if ($node['type'] == 'boolean') {
      $ret = self::tree_2_bestmatch($node['left'], $level + 1) . ' OR ' . self::tree_2_bestmatch($node['right'], $level + 1);
    }
    else {
      $ret = self::make_bestmatch_term($node['term'], $node['relation'], $node['prefix'], $node['field'], $node['slop']);
    }
    return $ret;
  }

  /** \brief convert all cql-trees to edismax-bestmatch-strings and set sort-scoring
   * @param $trees array - of tree
   * @return array -
   */
  private function trees_2_operands($trees) {
    $ret = array();
    foreach ($trees as $tree) {
      $ret = array_merge($ret, self::tree_2_operands($tree));
    }
    return $ret;
  }

  /** \brief convert all cql-trees to edismax-bestmatch-strings and set sort-scoring
   * @param $node array -
   * @return array -
   */
  private function tree_2_operands($node) {
    static $ret;
    if ($node['type'] == 'boolean') {
      return array_merge(self::tree_2_operands($node['left']), self::tree_2_operands($node['right']));
    }
    else {
      return explode(' ', $node['term']);
    }
  }

  /** \brief builds: t1 = term1, t1 = term2 to be used as extra solr-parameters referenced fom the sort-parameter
   *         like "sum(query($t1, 25),query($t2,25),query($t3,25),query($t4,25)) asc" for 4 terms
   * @param $query string
   * @return array -
   */
  private function make_bestmatch_sort($query) {
    $qs = explode(' OR ', $query);
    $fraction = floor(100 / count($qs));
    $comma = $sort = '';
    foreach ($qs as $qi => $q) {
      $n = 't' . $qi;
      $ret[$n] = $q;
      $sort .= $comma . 'query($' . $n . ',' . $fraction . ')';
      $comma = ',';
    }
    $ret['sort'] = 'sum(' . $sort . ') asc';
    return $ret;
  }

  /** \brief create edismax term query for bestmatch
   * @param $term string
   * @param $relation string
   * @param $prefix string
   * @param $field string
   * @param $slop integer
   * @return string -
   */
  private function make_bestmatch_term($term, $relation, $prefix, $field, $slop) {
    $ret = array();
    if ($quote = self::is_quoted($term)) {
      $term = self::delete_quotes($term, $quote);
    }
    $terms = explode(' ', self::normalize_term($term));
    foreach ($terms as $t) {
      if ($t) {
        $ret[] = self::make_solr_term($t, $relation, $prefix, $field, $slop);
      }
    }
    return implode(' OR ', $ret);
  }

  /** \brief create edismax term query
   * @param $term string
   * @param $relation string
   * @param $prefix string
   * @param $field string
   * @param $slop integer
   * @return string -
   */
  private function make_solr_term($term, $relation, $prefix, $field, $slop) {
    $quote = self::is_quoted($term);
    $wildcard = self::has_wildcard($term);
    $term = self::normalize_term($term, $quote);
    $term = self::convert_old_recid($term, $prefix, $field);
    $term = self::handle_bool($term);
    $space = strpos($term, ' ') !== FALSE;
    $m_field = '';
    if ($field && ($field <> 'serverChoice')) {
      $m_field = self::join_prefix_and_field($prefix, $field, ':');
    }
    if (in_array($prefix, $this->phrase_index)) {
      if ($quote) {
        $term = self::delete_quotes(self::escape_solr_quoted_term($term), $quote);
      }
      if ($space) {
        $term = str_replace(' ', '\\ ', $term);
      }
      if (!$m_term = self::make_term_interval($term, $relation, $quote)) {
        $m_term = $term;
      }
    }
    else {
      if (!$m_term = self::make_term_interval($term, $relation, $quote)) {
        $m_slop = '';
        if ($space) {
          if ($relation == 'any') {
            $term = '(' . preg_replace('/\s+/', ' OR ', self::delete_quotes($term, $quote)) . ')';
          }
          elseif ($relation == 'all') {
            $term = '(' . preg_replace('/\s+/', ' AND ', self::delete_quotes($term, $quote)) . ')';
          }
          elseif ($wildcard && $quote) {
            $term = '(' . self::delete_quotes($term, $quote) . ')';
          }
          else {
            // revert change 20181002/fvs 
            // $term = self::normalize_quotes($term, $quote);
            $m_slop = '~' . $slop;
          }
        }
        elseif ($quote) {
          $term = self::delete_quotes($term, $quote);
        }
        $m_term = self::escape_solr_term($term) . $m_slop;
      }
    }
    return $m_field . $m_term;
  }

  /** \brief Change a string surrounded by ' to "
   * @param $term string
   * @param $quote char
   * @return string
   */
  private function normalize_quotes($term, $quote) {
    if ($quote == "'" && $quote == substr($term, 0, 1) && $quote == substr($term, -1, 1)) {
      $term = '"' . substr($term, 1, strlen($term) - 2) . '"';
    }
    return $term;
  }

  /** \brief Return the quote used or empty string (FALSE)
   * @param $str string
   * @return mixed - the quote or empty string
   */
  private function is_quoted($str) {
    foreach (array('"', "'") as $ch) {
      $p = strpos($str, $ch);
      if (($p !== FALSE) && ($p === 0 || trim(substr($str, 0, ($p - 1))) == '')) {
        return $ch;
      }
    }
    return '';
  }

  /** \brief remove unescaped quotes from string
   * @param $str string
   * @param $quote character (' or ")
   * @return string
   */
  private function delete_quotes($str, $quote) {
    static $US = '\037';
    $ret = str_replace('\\' . $quote, $US, $str);
    $ret = str_replace($quote, '', $ret);
    return str_replace($US, '\\' . $quote, $ret);
  }

  /** \brief remove first and last quote from string - not used
   * @param $str string
   * @param $quote character (' or ")
   * @return string
  // this one trims as well, and performs many tests
  private function delete_first_and_last_quote($str, $quote) {
    $first = strpos($str, $quote);
    $last = strrpos($str, $quote);
    if ($first !== FALSE &&
      $first < $last &&
      ($first == 0 || trim(substr($str, 0, $first)) == '') &&
      (trim(substr($str, ($last + 1))) == '') &&
      (substr($str, ($last - 1), 1) != '\\')
    ) {
      return substr(substr($str, 0, $last), ($first + 1));
    }
    return $str;
  }
   */


  /** \brief Return TRUE if * or ? is used as wildcard
   * @param $str string
   * @return boolean
   */
  private function has_wildcard($str) {
    for ($i = 0; $i < strlen($str); $i++) {
      if ($str[$i] == '?' || $str[$i] == '*') {
        return TRUE;
      }
      if ($str[$i] == '\\') {
        $i++;
      }
    }
    return FALSE;
  }

  /** \brief Normalize spaces in term. Remove multiple spaces and space next to $quote
   * @param $term string
   * @param $quote string
   * @return string
   */
  private function normalize_term($term, $quote = '') {
    return preg_replace('/(^' . $quote . ' )|( ' . $quote . '$)/', $quote, preg_replace('/\s\s+/', ' ', trim($term)));
  }

  /** \brief Create full search code from a search tree node
   * @param $node array
   * @return string
   */
  private function node_2_index($node) {
    return self::join_prefix_and_field($node['prefix'], $node['field']);
  }

  /** \brief Create full search code
   * @param $prefix string
   * @param $field string
   * @param $colon string
   * @return string
   */
  private function join_prefix_and_field($prefix, $field, $colon = '') {
    if ($prefix == 'cql' && $field == 'keywords') {
      return '';
    }
    else {
      return ($prefix ? $prefix . '.' : '') . $field . $colon;
    }
  }

  /** \brief convert old rec.id's to version 3 rec.id's
   * @param $term string
   * @param $prefix string
   * @param $field string
   * @return string
   */
  private function convert_old_recid($term, $prefix, $field) {
    if ($prefix == 'rec' && $field == 'id' && preg_match("/^([1-9][0-9]{5})(:.*)$/", $term, $match)) {
      return (array_key_exists($match[1], $this->v2_v3) ? $this->v2_v3[$match[1]] : '870970-basis') . $match[2];
    }
    else {
      return $term;
    }
  }

  /** \brief Translate cql booleans to lowercase
   * @param $term string
   * @return string
   */
  private function handle_bool($term) {
    return in_array(preg_replace('/[\'"]/', '', $term), array('AND', 'OR', 'NOT')) ? strtolower($term) : $term;
  }

  /** \brief Escape character and remove characters to be ignored. And escape ( and )
   * @param $term string
   * @return string
   */
  private function escape_solr_quoted_term($term) {
    static $from = array('(', ')');
    static $to = array('\\(', '\\)');
    $str = self::escape_solr_term($term);
    return str_replace($from, $to, $str);
  }

  /** \brief Escape character and remove characters to be ignored
   * @param $term string
   * @return string
   */
  private function escape_solr_term($term) {
    static $solr_escapes_from;
    static $solr_escapes_to;
    if (!isset($solr_escapes_from)) {
      foreach ($this->solr_escapes as $ch) {
        $solr_escapes_from[] = $ch;
        $solr_escapes_to[] = '\\' . $ch;
      }
      foreach ($this->solr_ignores as $ch) {
        $solr_escapes_from[] = $ch;
        $solr_escapes_to[] = '';
      }
    }
    return str_replace($solr_escapes_from, $solr_escapes_to, $term);
  }

  /** \brief Detects fields which can go into solrs fq=
   * @param $term string
   * @param $relation string
   * @param $quot char
   * @return string
   */
  private function make_term_interval($term, $relation, $quot) {
    if (isset($this->interval[$relation])) {
      return sprintf($this->interval[$relation], $term);
      //return sprintf($interval, $quot . $term . $quot);
    }
    return NULL;
  }

  /** \brief Detects fields which can go into solrs fq=
   * @param $prefix string
   * @param $field string
   * @return boolean
   */
  private function is_filter_field($prefix, $field) {
    return !empty($this->indexes[$field][$prefix]['filter']);
  }

  /** \brief gets the handler for the term - depending od the search handler being used
   * @param $prefix  string
   * @param $field  string
   * @return string - the name of the handler or ''
   */
  private function get_term_handler($prefix, $field) {
    return $this->indexes[$field][$prefix]['handler'] ?? null;
  }

  /** \brief Split cql tree into several and-trees
   * @param $tree array
   * @return array - of tree
   */
  private function split_tree($tree) {
    if ($tree['type'] == 'boolean' && strtolower($tree['op']) == 'and') {
      return array_merge(self::split_tree($tree['left']), self::split_tree($tree['right']));
    }
    else {
      return array($tree);
    }

  }

  /** \brief sets clq namespaces
   */
  private function set_cqlns() {
    foreach ($this->cql_dom->getElementsByTagName('indexInfo') as $info_item) {
      foreach ($info_item->getElementsByTagName('set') as $set) {
        $this->cqlns[$set->getAttribute('name')] = $set->getAttribute('identifier');
      }
    }
  }

  /** \brief Sets index info
   */
  private function set_indexes() {
    foreach ($this->cql_dom->getElementsByTagName('indexInfo') as $info_item) {
      foreach ($info_item->getElementsByTagName('index') as $index_item) {
        if ($map_item = $index_item->getElementsByTagName('map')->item(0)) {
          if (!self::xs_boolean($map_item->getAttribute('hidden')) && $name_item = $map_item->getElementsByTagName('name')->item(0)) {
            $filter = self::xs_boolean($name_item->getAttribute('filter'));
            if (NULL == ($slop = $name_item->getAttribute('slop'))) {
              $slop = $this->default_slop;
            }
            if (NULL == ($handler = $name_item->getAttribute('searchHandler'))) {
              //$handler = 'edismax';
            }
            $this->indexes[$name_item->nodeValue][$name_item->getAttribute('set')] = array('filter' => $filter,
                                                                                           'slop' => $slop,
                                                                                           'handler' => $handler);
            foreach ($map_item->getElementsByTagName('alias') as $alias_item) {
              if (NULL == ($slop = $alias_item->getAttribute('slop'))) {
                $slop = $this->default_slop;
              }
              $this->indexes[$alias_item->nodeValue][$alias_item->getAttribute('set')]['alias'] =
                array('slop' => $slop,
                      'handler' => $handler,
                      'prefix' => $name_item->getAttribute('set'),
                      'field' => $name_item->nodeValue);
            }
          }
        }
      }
    }
  }

  /** \brief Get list of valid operators
   * @param $str string
   * @return boolean
   */
  private function xs_boolean($str) {
    return ($str == 1 || $str == 'true');
  }

  /** \brief Get list of valid operators
   * @param $language string
   */
  /* not used any more - operators are given in cql
    private function set_operators($language) {
      $this->operators = array();
      $boolean_lingo = ($language == 'cqldan' ? 'dan' : 'eng');
      foreach ($this->cql_dom->getElementsByTagName('supports') as $support_item) {
        $type = $support_item->getAttribute('type');
        if (in_array($type, array('relation', 'booleanChar', $boolean_lingo . 'BooleanModifier'))) {
          $this->operators[] = $support_item->nodeValue;
        }
      }
    }
  */

}

