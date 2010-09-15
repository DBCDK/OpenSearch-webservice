<?php
/** include for zsearch */
require_once("includes/search_func.phpi");
require_once("includes/cql.php");

// TODO get this function out of search_func
function load_lang_tab()
{
}

  /*
   class handles zsearch for bibdk.
*/
class bib_zsearch
{
  private $TARGET;
  private $watch;
  private $config;
  private $error;
  
  public function __construct($config,$watch=null)
  {
    if(!$config )
      {
	verbose::log(ERROR,"bibd_dk :: No ini-file");
	$this->error = "Internal error could not connect";
      }

    $this->config = $config;

    if( $watch )
      $this->watch = $watch;    

    $this->TARGET = $config->get_value("bibdk","TARGET");
   
    if( !isset($this->TARGET) )
      {
	verbose::log(ERROR,"bibd_dk :: No target in ini-file");
	$this->error = "Internal error could not connect";
      }
  }

  public function response($params)
  {
    if( $this->error )
      return $this->send_error();

    return $this->get_result($params);
  }

  private function get_result($params)
  {
    // do a zsearch
    $search = $this->TARGET;
    // TODO log TIMER
    $this->zsearch($params,$search);

    if( $this->error )
      return $this->send_error();

    //setup response object
    // hitCount
    $response->searchResponse->_value->result->_value->hitCount->_value = $search['hits'];
    // get searchResult as object
    
    //  print_r($search);
    //exit;

    $searchResults = $this->get_searchResults($search['records']);
    // collectionCount
    $response->searchResponse->_value->result->_value->collectionCount->_value = count($searchResults);
    // searchResult
    $response->searchResponse->_value->result->_value->searchResult = $searchResults;

    if( $this->error )
      return $this->send_error();

    return $response;
  }

 
  private function get_searchResults($records)
  {
    $searchResults = array();

    if( isset($records) )
      foreach( $records as $key=>$val )
	{
	  $col->_value->collection->_value->resultPosition->_value = $key;
	  $col->_value->collection->_value->numberOfObjects->_value = 1;
	  
	  $col->_value->collection->_value->object = $this->get_object($val['record']);
	  
	  $searchResults[] = $col;
	  
	  unset($col);
	}
    
    return $searchResults;
  }

 
  private function get_object($xml)
  {
    // get namespaces from config
    $ns = $this->config->get_value("xmlns","setup");

    // use internal error for error-reporting
    libxml_use_internal_errors(true);
    // setup xpath
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->LoadXML(utf8_encode($xml));
    $xpath = new DOMXPath($dom);
    
    if( $record = $this->get_record($ns,$xpath,$object_id) )
      {

	$obj->_value->record->_namespace = $ns['dkabm'];
	$obj->_value->record = $record;
	
	// identifier
	$identifier->_value = $object_id;
	$obj->_value->identifier[] = $identifier;

	// formats available; hack zsearch only goes for dkabm-records
	$format->_value="dkabm:record";
	$obj->_value->formatsAvailable[] = $format;	
      }

    return $obj;
  }

  private function object_identifier($ac_identifier)
  {
    $id_arr=explode("|",$ac_identifier);
    return $id_arr[1].":".$id_arr[0];
  }

  /**
     $xpath object holds an abm-record from zsearch. 
     parse the record and return xml-response record     
   */
  private function get_record($ns,$xpath,&$object_id)
  {
    $record->_namespace=$ns['dkabm'];

    // ac:identifier ( lid, lok )
    $query = "/dkabm:record/ac:identifier";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach($nodelist as $node)
	{
	  $identifier->_value = $node->nodeValue;

	  //hack; set object_id
	  $object_id=$this->object_identifier($node->nodeValue);

	  $identifier->_namespace = $ns['ac'];
	  $record->_value->identifier[] = $identifier;
	  unset($identifier);
	}

    // dc:identifier TODO implement
    $query = "/dkabm:record/dc:identifier";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  //echo $node->nodeValue."<br />\n";
	  $identifier->_value=$node->nodeValue;
	  $identifier->_namespace=$ns['dc'];
	  
	  $this->set_attributes($ns,$identifier,$node);
	  
	  $record->_value->identifier[] = $identifier;
	  unset($identifier);
	}


    // source
    $query = "/dkabm:record/ac:source";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  //echo $node->nodeValue."<br />\n";
	  $source->_value=$node->nodeValue;
	  $source->_namespace=$ns['ac'];
	  
	  $this->set_attributes($ns,$source,$node);
	  
	  $record->_value->source[] = $source;
	  unset($source);
	}

     // title
    $query = "/dkabm:record/dc:title";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  $title->_value = $node->nodeValue;
	  $title->_namespace = $ns['dc'];
	  $this->set_attributes($ns,$title,$node);
	  $record->_value->title[] = $title;
	  unset($title);
	}

    // creator
    $query = "/dkabm:record/dc:creator";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  $creator->_value=$node->nodeValue;
	  $creator->_namespace=$ns['dc'];
	  $this->set_attributes($ns,$creator,$node);
	  $record->_value->creator[] = $creator;
	  unset($creator);
	}

    // subject
    $query = "/dkabm:record/dc:subject";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  $subject->_value=$node->nodeValue;
	  $subject->_namespace=$ns['dc'];
	  
	  $this->set_attributes($ns,$subject,$node);

	  $record->_value->subject[] = $subject;
	  unset($subject);
	}

    // abstract
    $query = "/dkabm:record/dc:description";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  $abstract->_value = $node->nodeValue;
	  $abstract->_namespace = $ns['dcterms'];
	  
	  $this->set_attributes($ns,$abstract,$node);
	  
	  $record->_value->abstract[] = $abstract;
	  unset($abstract);
	}//_attributes->{$attr->localName}->_value

    // audience ??

    // version
    
    // publisher
    $query = "/dkabm:record/dc:publisher";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  $publisher->_value = $node->nodeValue;
	  $publisher->_namespace = $ns['dcterms'];
	  $this->set_attributes($ns,$publisher,$node);
	  $record->_value->publisher[] = $publisher;
	  unset($publisher);
	}

    // date
    $query = "/dkabm:record/dc:date";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  $date->_value = $node->nodeValue;
	  $date->_namespace = $ns['dcterms'];
	  $record->_value->date[] = $date;
	  unset($date);
	}
        
    // type
    $query = "/dkabm:record/dc:type";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  $type->_value = $node->nodeValue;
	  $type->_namespace = $ns['dc'];
	  $this->set_attributes($ns,$type,$node);
	  $record->_value->type[] = $type;
	  unset($type);
	}

    // extent
    $query = "/dkabm:record/dc:format";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  $extent->_value = $node->nodeValue;
	  $extent->_namespace = $ns['dcterms'];
	  $this->set_attributes($ns,$extent,$node);
	  $record->_value->extent[] = $extent;
	  unset($extent);
	}

    // language
    $query = "/dkabm:record/dc:language";
    $nodelist = $xpath->query($query);
    if( $nodelist )
      foreach( $nodelist as $node )
	{
	  $language->_value = $node->nodeValue;
	  $language->_namespace = $ns['dc'];
	  $this->set_attributes($ns,$language,$node);
	  $record->_value->language[] = $language;
	  unset($language);
	}   
    
    
    $errors = libxml_get_errors();
    if( $errors )
      {
	foreach( $errors as $error )
	  $log_txt=" :get_record : ".trim($error->message);

	verbose::log(WARNING,$log_txt);
	libxml_clear_errors();
	return false;
      }
    return $record;         
  }
  
  private function zsearch($params,&$search)
  {
    $ccl = $this->get_ccl($params);
    
    if( $this->error )
      return;

    $search['ccl']=$ccl;

    if( $start = $params->start->_value )
      $search['start'] = $start;
    
    if( $step = $params->stepValue->_value )
      $search['step'] = $step;
    
    $search["format"] = "abm";
    
    $this->watch->start("actual_zsearch");
    Zsearch($search);
    $this->watch->stop("actual_zsearch");

    if( $this->error = $search["error"] )
      {
	verbose::log(FATAL,"bib_dk :: Zsearch : ".$this->error);
	return;    
      }
  }

  private function set_attributes($ns,$obj,$node)
  {
    if ($node->hasAttributes())
      foreach ($node->attributes as $attr) 
	{
	  $obj->_attributes->{$attr->localName}->_namespace = $ns[$attr->prefix];
	  $obj->_attributes->{$attr->localName}->_value = $attr->nodeValue;
	}
  }

  private function get_ccl($params)
  {
     if( ! $cql = $params->query->_value )
      {
	$this->error = "Error in request; query not set";
	return;
      }

    $parser = new cql2dfa($cql);
    $ccl = $parser->ccl();
    $this->error = $parser->error();    

    return $ccl;
  }

 
  
  private function send_error()
  {
    $response->searchResponse->_value->error->_value = $this->error;
    return $response;       
  }
  
}

class cql2dfa
{
  
  private $cql;
  private $ccl;
  private $error;
 
  private $map = array("serverChoice"=>"fritekst", // Default if no index is given
		       "cql.anyIndexes"=>"fritekst", //Default and
		       "dc.creator"=>"forfatter",
		       "dc.title"=>"titel",
		       "dc.description"=>"no",
		       "dc.subject"=>"emne",
		       "dc.type"=>"ma",
		       "dc.language"=>"sp",
		       "dc.date"=>"aar",
		       "dc.source"=>"", //fx originaltitel - men hvordan søger man det? Er det evt. paralleltitel?
		       "dc.publisher"=>"fl",
		       "dc.identifier"=>"is",
		       "ac.source"=>"", //kun bibliotek.dk
		       "phrase.creator"=>"lfo",
		       "phrase.title"=>"lti",
		       "phrase.description"=>"no", //findes vist ikke som langord
		       "phrase.subject"=>"lem",
		       "phrase.type"=>"lma",
		       "phrase.language"=>"sp", //findes vist ikke som langord
		       "phrase.date"=>"aar", //findes vist ikke som langord
		       "phrase.source"=>"", //fx originaltitel - men hvordan søger man det? Er det evt. paralleltitel?
		       "phrase.publisher"=>"fl", //findes vist ikke som langord
		       "phrase.anyIndexes"=>"fritekst", //findes vist ikke som langord
		       "phrase.identifier"=>"is", //findes vist ikke som langord
		       "rec.id"=>"lid", //Skal vi lave en mapning til lid+lok fx 870970:12345678 -> lid=870970 og lok=870970
		       "facet.creator"=>"", //Vi kan ikke lave facet-indekser til bibliotek.dk
		       "facet.type"=>"",
		       "facet.subject"=>"",
		       "facet.date"=>"",
		       "facet.language"=>"",
		       "facet.geographic"=>"",
		       "facet.period"=>"",
		       "facet.fiction"=>"",
		       "facet.nonFiction"=>"",
		       "facet.music"=>"",
		       "facet.dk5"=>"",
		       );
  
  public function __construct($cql)
  {
    $this->cql=$cql;
    $this->parse_cql();    
  }

  public function ccl()
  {
    return $this->ccl;
  }

  public function error()
  {
    return $this->error;
  }

  /**
     use included cql class to make a cql-array. parse the array and set ccl.
   */
  private function parse_cql()
  {
    $cql = new CQL_parser($srw);
    $cql->define_prefix("dc","dublin core","dc");
    $cql->define_prefix("phrase", "phrase","phrase");
    $cql->define_prefix("ac", "ac","ac");
    $cql->define_prefix("facet", "facet","facet");
    $cql->define_prefix("rec", "rec","rec");
    $cql->define_prefix("cql", "cql","cql");
    
    $ok=$cql->parse($this->cql);

    if( !$ok )
      {
	verbose::log(ERROR,"bib_dk :: parse_cql failed for: ".$this->cql." parser error-message: ".$cql->error);
	$this->error = " Cannot decode query : '".$this->cql."' . Parser exit with error: ".$cql->error;
	return;
      }

    $result=$cql->result();    
    
    $this->parse_array($result,$ret);

    $this->ccl=$ret;
    verbose::log(TRACE,"bibdk :: cql: ".$this->cql." -> ccl: ".$this->ccl);
    
  }

  /**
     recursive. parse given array and set ccl
   */
  private function parse_array($arr,&$ret)
  {
    if (!isset($arr['type'])) 
      return;

    if( isset($arr['left']) )
      $ret.='(';

    if( $arr['type']=='searchClause' )
      $ret.=$this->map_field($arr);

    elseif ($arr['type'] == 'boolean') 
      {
	$ret .= $this->parse_array($arr['left'],$ret);

	$ret .=  ' '.$arr['op'].' ' ;
	
	$ret .= $this->parse_array($arr['right'],$ret);	
      }

    if( isset($arr['right']) )
      $ret.=')';
  }  

  /**
     map from cql field to corresponding ccl.
   */
  private function map_field($arr)
  {
    if( $arr['field']=='serverChoice' )
      $ccl=$this->map[$arr['field']].' = '.$arr['term'];
    else
      {
	$key = $arr['fielduri'].".".$arr['field'];
	$ccl = $this->map[$key].' '.$arr['relation'].' '.$arr['term'];
      }
    return $ccl;
  }    
}
?>