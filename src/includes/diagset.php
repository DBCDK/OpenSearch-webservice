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

   $Id: diagset.php,v 1.1 2005-12-22 10:38:14 fvs Exp $
*/

/*
 * Official list at:
 * http://www.loc.gov/z3950/agency/zing/srw/diagnostics-list.html
 * Depricated ones marked with 'dep'
 */
function srw_diag_message($id)
{
    $message = array (
	1 => "Permanent system error", 
	2 => "System temporarily unavailable", 
	3 => "Authentication error", 
	4 => "Unsupported operation",
	5 => "Unsupported version",
	6 => "Unsupported parameter value",
	7 => "Mandatory parameter not supplied",
	8 => "Unsupported parameter",
/* Diagnostics Relating to CQL */
	10 => "Query syntax error", 
	11 => "Unsupported query type", 
	12 => "Too many characters in query", 
	13 => "Invalid or unsupported use of parentheses", 
	14 => "Invalid or unsupported use of quotes", 
	15 => "Unsupported context set", 
	16 => "Unsupported index", 
	17 => "Unsupported combination of index and context set", /* dep */
	18 => "Unsupported combination of indexes", 
	19 => "Unsupported relation", 
	20 => "Unsupported relation modifier", 
	21 => "Unsupported combination of relation modifers", 
	22 => "Unsupported combination of relation and index", 
	23 => "Too many characters in term", 
	24 => "Unsupported combination of relation and term", 
	25 => "Special characters not quoted in term", /* dep */
	26 => "Non special character escaped in term", 
	27 => "Empty term unsupported", 
	28 => "Masking character not supported", 
	29 => "Masked words too short", 
	30 => "Too many masking characters in term", 
	31 => "Anchoring character not supported", 
	32 => "Anchoring character in unsupported position", 
	33 => "Combination of proximity/adjacency and masking characters not supported", 
	34 => "Combination of proximity/adjacency and anchoring characters not supported", 
	35 => "Terms only exclusion stopwords", 
	36 => "Term in invalid format for index or relation", 
	37 => "Unsupported boolean operator", 
	38 => "Too many boolean operators in query", 
	39 => "Proximity not supported", 
	40 => "Unsupported proximity relation", 
	41 => "Unsupported proximity distance", 
	42 => "Unsupported proximity unit", 
	43 => "Unsupported proximity ordering", 
	44 => "Unsupported combination of proximity modifiers", 
	45 => "Prefix assigned to multiple identifiers", /* dep */
	46 => "Unsupported boolean modifier",
	47 => "Cannot process query; reason unknown",
	48 => "Query feature unsupported",
	49 => "Masking character in unsupported position",
/* Diagnostics Relating to Result Sets */
	50 => "Result sets not supported", 
	51 => "Result set does not exist", 
	52 => "Result set temporarily unavailable", 
	53 => "Result sets only supported for retrieval", 
	54 => "Retrieval may only occur from an existing result set", /* dep */
	55 => "Combination of result sets with search terms not supported", 
	56 => "Only combination of single result set with search terms supported",  /* dep */
	57 => "Result set created but no records available", /* dep */
	58 => "Result set created with unpredictable partial results available", 
	59 => "Result set created with valid partial results available", 
	60 => "Result set no created: too many records retrieved", 
/* Diagnostics Relating to Records */
	61 => "First record position out of range", 
	62 => "Negative number of records requested", /* dep */
	63 => "System error in retrieving records", /* dep */
	64 => "Record temporarily unavailable", 
	65 => "Record does not exist", 
	66 => "Unknown schema for retrieval", 
	67 => "Record not available in this schema", 
	68 => "Not authorised to send record", 
	69 => "Not authorised to send record in this schema", 
	70 => "Record too large to send", 
	71 => "Unsupported record packing",
	72 => "XPath retrieval unsupported",
	73 => "XPath expression contains unsupported feature",
	74 => "Unable to evaluate XPath expression",
/* Diagnostics Relating to Sorting */
	80 => "Sort not supported", 
	81 => "Unsupported sort type", /* dep */
	82 => "Unsupported sort sequence", 
	83 => "Too many records to sort", 
	84 => "Too many sort keys to sort", 
	85 => "Duplicate sort keys", /* dep */
	86 => "Cannot sort: incompatible record formats", 
	87 => "Unsupported schema for sort", 
	88 => "Unsupported path for sort", 
	89 => "Path unsupported for schema", 
	90 => "Unsupported direction value", 
	91 => "Unsupported case value", 
	92 => "Unsupported missing value action", 
/* Diagnostics Relating to Explain */
	100 => "Explain not supported", /* dep */ /* use 4 */
	101 => "Explain request type not supported (SOAP vs GET)", /* dep */
	102 => "Explain record temporarily unavailable", /* dep */ /* use 64 */
/* Diagnostics Relating to Stylesheets */
	110 => "Stylesheets not supported",
	111 => "Unsupported stylesheet",
/* Diagnostics relating to Scan */
	120 => "Response position out of range",
	121 => "Too many terms requested"
	);

    return $message[$id];
}

