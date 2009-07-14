<?php
/**
 *
 * This file is part of OpenSearch.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * OpenSearch is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenSearch is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with OpenSearch.  If not, see <http://www.gnu.org/licenses/>.
*/

define("WSDL", "opensearch.wsdl");

try {
  $client = new SoapClient(WSDL,  array('trace' => 1, "cache_wsdl" => WSDL_CACHE_NONE));
  //$client = new SoapClient(WSDL,  array('trace' => 1));
  $options = array('proxy_host' => "phobos.dbc.dk", 'proxy_port' => 3128);
  $options = array('connection_timeout' => 2);
  //$client = new SoapClient(WSDL, $options);
  $client->__setLocation('http://vision.dbc.dk/~fvs/broend/OpenLibrary/OpenSearch/trunk/');

  $params = array("query" => "dc.title:fødes",
  //$params = array("query" => "dc.title:dan*",
  //$params = array("query" => "dc.title:oplagsbulletin",
                  "source" => "",
                  "facets" => array("number" => 10, 
                                    "facetName" => array("dc.creator", 
                                                         "creator", 
                                                         "dc.title", 
                                                         "title")),
                  "formatType" => "",
                  "start" => "1",
                  "stepValue" => "4",
                  "sort" => "");

//var_dump($client->__getFunctions());
//var_dump($client->__getTypes());
  $result = $client->search($params);
} catch (SoapFault $fault) {
  echo "Fejl: ";
  echo $fault->faultcode . ":" . $fault->faultstring;
  var_dump($fault);
}

if (FALSE) {
  $s_types = $client->__getTypes();
  foreach ($s_types as $s_type)
    var_dump($s_type);
}
//echo "Request:<br/>" . str_replace("<", "&lt;", $client->__getLastRequest()) . "<br/>";
//echo "RequestHeaders:<br/>" . str_replace("<", "&lt;", $client->__getLastRequestHeaders()) . "<br/>";
//echo "Response:<br/>" . str_replace("<", "&lt;", $client->__getLastResponse()) . "<br/>";
//echo "Result:<br/>"; var_dump($result);
//echo "Records:<br/>"; var_dump($result->result->searchResult->records->tingRecord);
echo "hitCount: " . $result->result->hitCount . "<br/>";
echo "Records:<br/>"; 

//var_dump($result->result->searchResult->collection);
foreach ($result->result->searchResult->collection->object as $rec) {
  if ($rec->dc) {
    echo "<br/>" . $rec->identifier . "<br/>";
    foreach ($rec->dc as $tag => $dc)
      if (is_array($dc))
        foreach ($dc as $key => $var)
          echo $tag . ": " . $var . "<br/>";
      else
        echo $tag . ": " . $dc . "<br/>";
  } elseif ($rec->rawData) {
    echo "RAW";
  } elseif ($rec->short) {
    echo "short";
  } else
    echo "Unknown recordType";
}
//echo "Result:<br/>"; var_dump($result->result->facetResult);
echo "<br/>Facets:<br/>"; 
foreach ($result->result->facetResult->facet as $facet) {
  echo $facet->facetName . ":<br/>";
  foreach ($facet->facetTerm as $term)
    echo "&nbsp; " . $term->frequence . " " . $term->term . "<br/>";
}

?>
