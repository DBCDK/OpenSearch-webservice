<?php
define("DANBIB_NEP","saba.dbc.dk:21040");
define("DEFAULT_HOST", "lakitre.dbc.dk:2105");
//define("DANBIB_NEP", "lakitre.dbc.dk:21040");

//global $TARGET;	// aht wget

$TARGET["dfa"] = array (
    "host"		=> DEFAULT_HOST,
    "database"		=> 'dfa',
    "piggyback"		=> false,
    "raw_record"	=> true,
    "authentication"	=> "webgr/dfa/hundepine",
    "fors_name"		=> "bibliotek.dk",
    "cclfields"		=> 'danbib.ccl2rpn',
    //    "format"		=> 1,
    "formats"		=> array("dc" => "xml/ws_dc",
                         "marcx" => "xml/marcxchange",
                         "abm" => "xml/abm_xml"),
    "start"		=> 1,
    "step"		=> 1,
    "filter"		=> "",
    "sort"		=> "",
    "sort_default"	=> "aar",
    "sort_max"		=> 100000,
    //"vis_max"		=> 1000000,
    "timeout"		=> 30
);

$TARGET["danbib"] = array (
    "host"		=> DANBIB_NEP,
    "database"		=> 'danbibv3',
    "piggyback"		=> false,
    "raw_record"	=> true,
    "authentication"	=> "webgr/dfa/hundepine",
    "fors_name"		=> "bibliotek.dk",
    "cclfields"		=> 'danbib.ccl2rpn',
    "format"		=> 1,
    "formats"		=> array("abm" => "xml/abm_xml"),
    "start"		=> 1,
    "step"		=> 1,
    "filter"		=> "",
    "sort"		=> "",
    "sort_default"	=> "aar",
    "sort_max"		=> 100000,
    //"vis_max"		=> 1000000,
    "timeout"		=> 30
);







?>
