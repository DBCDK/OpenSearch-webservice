<?php
/*
   Copyright (C) 2004 Index Data Aps, www.indexdata.dk

   This file is part of SRW/PHP

   This program is free software; you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation; version 2 dated June, 1991.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   A copy of the GNU General Public License is also available at
   <URL:http://www.gnu.org/copyleft/gpl.html>.  You may also obtain
   it by writing to the Free Software Foundation, Inc., 59 Temple
   Place - Suite 330, Boston, MA 02111-1307, USA.

   $Id: cql.php,v 1.1 2005-12-22 10:38:14 fvs Exp $
*/

class CQL_parser 
{
  // public
    
    function CQL_parser(&$srw_response)	
    {
	if (is_object($srw_response))
	    $this->r_srw_response = &$srw_response;
    }
    
    function define_prefix($prefix, $title, $uri) 
    {
	$this->std_prefixes = $this->add_prefix(
	    $this->std_prefixes, $prefix, $title, $uri);
    }
    
    function parse($query) 
    {
	$this->qs = $query;
	$this->ql = strlen($query);
	$this->qi = 0;
	$this->look = TRUE;
	$this->move();
	$this->tree = $this->cqlQuery("cql.serverChoice", "scr",
				      $this->std_prefixes,
				      array());
	if ($this->look != FALSE)
	    $this->add_diagnostic(10, "$this->qi");
	
	return $this->parse_ok;
    }
    
    function result() 
    {
	return $this->tree;
    }
    
    function result2xml($ar) 
    {
	return $this->tree2xml_r($ar, 0);
    }
    
    // private
    var $r_srw_response;      //handle to SRW_response object
    var $qs;     // query string
    var $ql;     // query string length
    var $qi;     // position in qs when parsing
    var $look;   // last seen token
    var $val;    // attribute value for token
    var $lval;   // lower case of value when string
    var $tree=array();
    var $std_prefixes=array(); 
    var $debug_level = 0;  // debug level, from 0 to 3
    var $diags = '';  // diagnostics array to be passed to SRW-response
    var $parse_ok = TRUE;  // cql parsing went ok

    function move() 
    {
	while ($this->qi < $this->ql && strchr(" \t\r\n", $this->qs[$this->qi]))
	    $this->qi++;
	if ($this->qi == $this->ql)
	{
	    $this->look = FALSE;
	    return;
	}
	$c = $this->qs[$this->qi];
	if (strchr("()/", $c)) {
	    $this->look = $c;
	    $this->qi++;
	} elseif (strchr("<>=", $c)) {
	    $this->look = $c;
	    $this->qi++;
	    while ($this->qi < $this->ql && 
		   strchr("<>=", $this->qs[$this->qi]))
	    {
		$this->look .= $this->qs[$this->qi];
		$this->qi++;
	    }
	} elseif (strchr("\"'", $c)) {
	    $this->look = 'q';
	    $mark = $c;
	    $this->qi++;
	    $this->val = '';
	    while ($this->qi < $this->ql && $this->qs[$this->qi] != $mark)
	    {
		if ($this->qs[$this->qi] == '\\' && $this->qi < $this->ql-1)
		    $this->qi++;
		$this->val .= $this->qs[$this->qi];
		$this->qi++;
	    }
	    $this->lval = strtolower($this->val);
	    if ($this->qi < $this->ql)
		$this->qi++;
	} else {
	    $this->look = 's';
	    $start_q = $this->qi;
	    while ($this->qi < $this->ql && !strchr("()/<>= \t\r\n", $this->qs[$this->qi]))
		$this->qi++;
	    $this->val = substr($this->qs, $start_q, $this->qi - $start_q);
	    $this->lval = strtolower($this->val);
	}
    }
    
    function modifiers($context) {
	$ar = array();
	while ($this->look == '/') {
	    $this->move();
	    if ($this->look != 's' && $this->look != 'q')
	    {
		$this->add_diagnostic(10,"$this->qi");
		return $ar;
	    }
	    $name = $this->lval;
	    $this->move();
	    if (strchr("<>=", $this->look[0])) {
		$rel = $this->look;
		$this->move();
		if ($this->look != 's' && $this->look != 'q')
		{
		    $this->add_diagnostic(10, "$this->qi");
		    return $ar;
		}
		$ar[$name] = array('value' => $this->lval,
				   'relation' => $rel);
		$this->move();
	    }
	    else
		$ar[$name] = TRUE;
	}
	return $ar;
    }
    
    function cqlQuery($field, $relation, $context, $modifiers) 
    {
	$left = $this->searchClause($field, $relation, $context,
				    $modifiers);
	while ($this->look == 's' && (
		   $this->lval == 'and' ||
		   $this->lval == 'or' ||
		   $this->lval == 'not' ||
		   $this->lval == 'prox'))
	{
	    $op = $this->lval;
	    $this->move();
	    $mod = $this->modifiers($context);
	    $right = $this->searchClause($field, $relation, $context,
					 $modifiers);
	    $left= array ( 'type' => 'boolean',
			   'op' => $op,
			   'modifiers' => $mod,
			   'left' => $left,
			   'right' => $right);
	}
	return $left;
    }

    function searchClause($field, $relation, $context, $modifiers) 
    {
	if ($this->look == '(') {
	    $this->move();
	    $b = $this->cqlQuery($field, $relation, $context, $modifiers);
	    if ($this->look == ')')
	    {
		$this->move();
	    }
	    else
	    {
		$this->add_diagnostic(13, "$this->qi");
	    }
	    return $b;
	} elseif ($this->look == 's' || $this->look == 'q') {
	    $first = $this->val;   // dont know if field or term yet
	    $this->move();
	    
	    if ($this->look == 'q' ||
		($this->look == 's' &&
		 $this->lval != 'and' &&
		 $this->lval != 'or' &&
		 $this->lval != 'not' &&
		 $this->lval != 'prox'))
	    {
		$rel = $this->val;    // string relation
		$this->move();
		return $this->searchClause($first, $rel, $context,
					   $this->modifiers($context));
	    } elseif (strchr("<>=", $this->look[0])) {
		$rel = $this->look;   // other relation <, = ,etc
		$this->move();
		return $this->searchClause($first, $rel, $context,
					   $this->modifiers($context));
	    } else {
		// it's a search term
		
		$pos = strpos($field, '.');
		if ($pos == FALSE)
		    $pre = '';
		else {
		    $pre = substr($field, 0, $pos);
		    $field = substr($field, $pos+1, 100);
		}
		$uri = '';
		for ($i = 0; $i < sizeof($context); $i++) {
		    if ($context[$i]['prefix'] == $pre)
			$uri = $context[$i]['uri'];
		}			
		
		$pos = strpos($relation, '.');
		if ($pos == FALSE)
		    $pre = 'cql';
		else {
		    $pre = substr($relation, 0, $pos);
		    $relation = substr($relation, $pos+1, 100);
		}
		$reluri = '';
		for ($i = 0; $i < sizeof($context); $i++) {
		    if ($context[$i]['prefix'] == $pre)
			    $reluri = $context[$i]['uri'];
		}			
		return array ('type' => 'searchClause',	
			      'field' => $field,
			      'fielduri' => $uri,
			      'relation' => $relation,
			      'relationuri' => $reluri,
			      'modifiers' => $modifiers,
			      'term' => $first);
	    }
	} elseif ($this->look == '>') {
	    $this->move();
	    if ($this->look != 's' && $this->look != 'q')
		return array();
	    $first = $this->lval;
	    $this->move();
	    if ($this->look == '=')
	    {
		$this->move();
		if ($this->look != 's' && $this->look != 'q')
		    return array();
		$context = $this->add_prefix($context, 
					     $first, '', $this->lval);
		$this->move();
		return $this->cqlQuery($field, $relation, $context,
				       $modifiers);
	    } else {
		$context = $this->add_prefix($context,
					     '', '', $first);
		return $this->cqlQuery($field, $relation, $context,
				       $modifiers);
	    }
	} else {
	    $this->add_diagnostic(10, "$this->qi");
	}
    }

    function add_prefix($ar, $prefix, $title, $uri) 
    {
	if (!is_array($ar))
	    $ar = array();
	for ($i = 0; $i<sizeof($ar); $i++)
	    if ($ar[$i]['prefix'] == $prefix) 
		break;
	$ar[$i] = array (
	    'prefix' => $prefix,
	    'title' => $title,
	    'uri' => $uri
	    );
	return $ar;
    }
    
    function tree2xml_modifiers($ar, $level) 
    {
	if (sizeof($ar) == 0) {
	    return "";
	}
	$s = str_repeat(' ', $level);
	$s .= "<modifiers>\n";
	
	$k = array_keys($ar);
	    
	foreach ($k as $no => $key) {
	    $s .= str_repeat(' ', $level+1);
	    $s .= "<modifier>\n";
	    
	    $s .= str_repeat(' ', $level+2);
	    $s .= '<name>' . htmlspecialchars($key) . "</name\n";
	    
	    if (isset($ar[$key]['relation'])) {
		$s .= str_repeat(' ', $level+2);
		$s .= '<relation>' . htmlspecialchars($ar[$key]['relation']) . "</relation>\n";
	    }
	    if (isset($ar[$key]['value'])) {
		    $s .= str_repeat(' ', $level+2);
		    $s .= '<value>' . htmlspecialchars($ar[$key]['value']) . "</value>\n";
	    }
	    $s .= str_repeat(' ', $level+1);
	    $s .= "</modifier>\n";
	}
	$s .= str_repeat(' ', $level);
	$s .= "</modifiers>\n";
	return $s;
    }
    
    function tree2xml_indent($level) 
    {
	return str_repeat(' ', $level*2);
    }
    
    function tree2xml_r($ar, $level) 
    {
	$s = '';
	if (!isset($ar['type'])) {
	    return $s;
	}
	if ($ar['type'] == 'searchClause') {
	    $s .= $this->tree2xml_indent( $level);
	    $s .= "<searchClause>\n";


	    if (strlen($ar['fielduri'])) {
		$s .= $this->tree2xml_indent($level+1);
		$s .= "<prefixes>\n";
		$s .= $this->tree2xml_indent($level+2);
		$s .= "<prefix>\n";
		$s .= $this->tree2xml_indent($level+3);
		$s .= "<identifier>" . $ar['fielduri'] . "</identifier>\n";
		$s .= $this->tree2xml_indent($level+2);
		$s .= "</prefix>\n";
		$s .= $this->tree2xml_indent($level+1);
		$s .= "</prefixes>\n";
	    }
	    $s .= $this->tree2xml_indent( $level+1);
	    $s .= '<index>' . htmlspecialchars($ar['field']) . "</index>\n";
	    
	    $s .= $this->tree2xml_indent( $level + 1);
	    $s .= "<relation>\n";
	    if (strlen($ar['relationuri'])) {
		$s .= $this->tree2xml_indent( $level + 2);
	        $s .= '<identifier>' . $ar['relationuri'] . "</identifier>\n";
	    }
	    $s .= $this->tree2xml_indent( $level + 2);
	    $s .= "<value>" . htmlspecialchars($ar['relation']) . "</value>\n";
	    $s .= $this->tree2xml_modifiers($ar['modifiers'], $level+3);

	    $s .= $this->tree2xml_indent( $level + 1);
	    $s .= "</relation>\n";

	    $s .= $this->tree2xml_indent( $level+1);
	    $s .= '<term>' . htmlspecialchars($ar['term']) . "</term>\n";

	    $s .= $this->tree2xml_indent( $level);
	    $s .= "</searchClause>\n";
	} elseif ($ar['type'] == 'boolean') {
	    $s .= $this->tree2xml_indent( $level);
	    $s .= "<triple>\n";

	    $s .= $this->tree2xml_indent( $level+1);
	    $s .= "<boolean>\n";

	    $s .= $this->tree2xml_indent( $level+2);
	    $s .= "<value>" . htmlspecialchars($ar['op']) .  "</value>\n";

	    $s .= $this->tree2xml_modifiers($ar['modifiers'], $level+2);
	    
	    $s .= $this->tree2xml_indent( $level+1);
	    $s .= "</boolean>\n";

	    $s .= $this->tree2xml_indent( $level+1);
	    $s .= "<leftOperand>\n";
	    $s .= $this->tree2xml_r($ar['left'], $level+2);
	    $s .= $this->tree2xml_indent( $level+1);
	    $s .= "</leftOperand>\n";

	    $s .= $this->tree2xml_indent( $level+1);
	    $s .= "<rightOperand>\n";
	    $s .= $this->tree2xml_r($ar['right'], $level+2);
	    $s .= $this->tree2xml_indent( $level+1);
	    $s .= "</rightOperand>\n";

	    $s .= $this->tree2xml_indent( $level);
	    $s .= "</triple>\n";
	}
	return $s;
    }   
    
    function debug($level, $string) 
    {
	$this->r_srw_response->debug($level, $string);
    }
    
    function add_diagnostic($id, $string) 
    {
	$this->parse_ok = FALSE;
	//	$this->r_srw_response->add_diagnostic($id, $string);
    }
}

