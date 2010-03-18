<?php

$xml = '<result>
  <hitCount>20</hitCount>
  <collecion>
    <object>
      <title>Anders</title>
    </object>
    <object>
      <title>Benny</title>
    </object>
  </collecion>
</result>';

$o->result->hitcount->{'$'} = "20";
$o->result->collection->object[]->title->{'$'} = "Anders";
$o->result->collection->object[]->title->{'$'} = "Benny";
dump_req("Simple result with two records", "Tag content is put in $, repetitve tags in arrays", $xml, $o);

$xml = '<result>
  <hitCount>20</hitCount>
  <collecion>
    <object>
      <title lingo="danishName">Anders</title>
    </object>
    <object>
      <title lingo="englishName">Bart</title>
    </object>
  </collecion>
</result>';

$anders->{'$'} = "Anders";
$anders->{'@lingo'}->{'$'} = "danishName";
$bart->{'$'} = "Bart";
$bart->{'@lingo'}->{'$'} = "englishName";
$o->result->hitcount->{'$'} = "20";
$o->result->collection->object[]->title = $anders;
$o->result->collection->object[]->title = $bart;
dump_req("Two records with attributes", "Attributes are prefixed wih @ and placed as a (sub)-tag value", $xml, $o);

$xml = '<result xmlns:dc="http://purl.org/dc/elements/1.1/">
  <hitCount>20</hitCount>
  <collecion>
    <object>
      <dc:title lingo="danishName">Anders</title>
    </object>
    <object>
      <dc:title lingo="englishName">Bart</title>
    </object>
  </collecion>
</result>';

$anders->{'$'} = "Anders";
$anders->{'@lingo'}->{'$'} = "danishName";
$anders->{'@'} = "dc";
$bart->{'$'} = "Bart";
$bart->{'@lingo'}->{'$'} = "englishName";
$bart->{'@'} = "dc";
$o->result->hitcount->{'$'} = "20";
$o->result->collection->object[]->title = $anders;
$o->result->collection->object[]->title = $bart;
$o->{'@namespaces'}->dc = "http://purl.org/dc/elements/1.1/";
dump_req("Two records with attributes and namespace", "Namespace-prefix is put in @ and @namespaces are added the end", $xml, $o);

$xml = '<result xmlns="http://oss.dbc.dk/ns/opensearch" xmlns:dc="http://purl.org/dc/elements/1.1/">
  <hitCount>20</hitCount>
  <collecion>
    <object>
      <dc:title lingo="danishName">Anders</title>
    </object>
    <object>
      <dc:title lingo="englishName">Bart</title>
    </object>
  </collecion>
</result>';

$anders->{'$'} = "Anders";
$anders->{'@lingo'}->{'$'} = "danishName";
$anders->{'@'} = "dc";
$bart->{'$'} = "Bart";
$bart->{'@lingo'}->{'$'} = "englishName";
$bart->{'@'} = "dc";
$o->result->hitcount->{'$'} = "20";
$o->result->collection->object[]->title = $anders;
$o->result->collection->object[]->title = $bart;
$o->{'@namespaces'}->{'$'} = "http://oss.dbc.dk/ns/opensearch";
$o->{'@namespaces'}->dc = "http://purl.org/dc/elements/1.1/";
dump_req("Two records with attributes and namespace and default namespace", "Default namespace is put in @namespaces as $", $xml, $o);

$xml = '<result xmlns:xsi="http://www.w3.org\/2001/XMLSchema-instance" xmlns:dc="http://purl.org/dc/elements/1.1/">
  <hitCount>20</hitCount>
  <collecion>
    <object>
      <dc:title xsi:lingo="danishName">Anders</title>
    </object>
    <object>
      <dc:title xsi:lingo="englishName">Bart</title>
    </object>
  </collecion>
</result>';

$anders->{'$'} = "Anders";
$anders->{'@lingo'}->{'$'} = "danishName";
$anders->{'@lingo'}->{'@'} = "xsi";
$anders->{'@'} = "dc";
$bart->{'$'} = "Bart";
$bart->{'@lingo'}->{'$'} = "englishName";
$bart->{'@lingo'}->{'@'} = "xsi";
$bart->{'@'} = "dc";
$o->result->hitcount->{'$'} = "20";
$o->result->collection->object[]->title = $anders;
$o->result->collection->object[]->title = $bart;
$o->{'@namespaces'}->xsi = "http://www.w3.org\/2001/XMLSchema-instance";
$o->{'@namespaces'}->dc = "http://purl.org/dc/elements/1.1/";
dump_req("Two records with attributes containing namespace", "Attribute namespace is added in @lingo", $xml, $o);

function dump_req($s, $inf, $xml, &$obj) {
  echo '<h2>' . $s . '</h2>';
  echo '<pre>' . str_replace('<', '&lt;', $xml) . '</pre>';
  if ($_REQUEST["object"]) {
    echo '<pre>'; print_r($obj); echo '</pre>';
  }
  echo '</pre><p>' . $inf . ':</p>';
  echo json_encode($obj);
  $obj = "";
}

?>
