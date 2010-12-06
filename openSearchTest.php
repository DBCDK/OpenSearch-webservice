<?php
require_once 'PHPUnit/Framework/TestCase.php';

define( 'PHPUNIT_RUNNING', TRUE );
require_once 'server.php';

/*!
    \brief Tests the class openSearch.
    
    \note
        userDefinedRanking = CQL to DISMAX: danmark -> (danmark AND _query_:"{!dismax qf='dc.title^4 cql.anyIndexes^1' pf='dc.title^8 cql.anyIndexes^1' tie=0.1}danmark ")
 
    \author 
        Sune Thomas Poulsen <stp@dbc.dk>
*/
class openSearchTest extends PHPUnit_Framework_TestCase {
    public function testBoostUrls() {
        self::assertEquals( '', openSearch::boostUrl( self::createParam( self::createBoostXml( 'danmark', null ) ) ) );
        
        $boosts[ 'sort' ] = 'title_ascending';
        $boosts[ 'fields' ] = array( array( 'name' => 'dc.title', 
                                            'type' => 'word', 
                                            'value' => 'bog', 
                                            'weight' => '4' )                                    
                                   );
        $param = self::createParam( self::createBoostXml( 'danmark', $boosts ) )->searchRequest->_value;
        self::assertEquals( 'danmark', $param->query->_value );
        self::assertEquals( ' AND ( _query_:"{!boost bq=\'dc.title:bog^4\'}bog" )', 
                            openSearch::boostUrl( $param ) );
        
        $boosts[ 'sort' ] = 'title_ascending';
        $boosts[ 'fields' ] = array( array( 'name' => 'dc.title', 
                                            'type' => 'word', 
                                            'value' => 'bog', 
                                            'weight' => '4' ),
                                     array( 'name' => 'dc.type', 
                                            'type' => 'word', 
                                            'value' => 'Periodikum', 
                                            'weight' => '2' ) 
                                   );
        $param = self::createParam( self::createBoostXml( 'danmark', $boosts ) )->searchRequest->_value;
        
        self::assertEquals( ' AND ( _query_:"{!boost bq=\'dc.title:bog^4\'}bog" _query_:"{!boost bq=\'dc.type:Periodikum^2\'}Periodikum" )', 
                            openSearch::boostUrl( $param ) );
    }
    
    public function testCreateParam() {
        self::assertEquals( null, $this->createParam( '' ) );
        self::assertEquals( null, $this->createParam( 'Dette er ikke valid XML!' ) );
    
        self::assertEquals( null, $this->createParam( '<xml><tag></tag>' ) );
        self::assertNotEquals( null, $this->createParamFromFile( 'xml/request/getObject.xml' ) );
    }
    
    public function testCreateBoostXml() {
        $s = "";
        $s .= '<?xml version="1.0" encoding="UTF-8"?>';
        $s .= '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://oss.dbc.dk/ns/opensearch"><SOAP-ENV:Body>';
        $s .= '    <ns1:searchRequest>';
        $s .= sprintf('    <ns1:query>%s</ns1:query>', 'danmark' );
        $s .= '    <ns1:start>1</ns1:start>';
        $s .= '    <ns1:stepValue>2</ns1:stepValue>';
        $s .= '</ns1:searchRequest>';
        $s .= '</SOAP-ENV:Body></SOAP-ENV:Envelope>';
        
        self::assertEquals( $s, self::createBoostXml( 'danmark', null ) );
    
        $s = "";
        $s .= '<?xml version="1.0" encoding="UTF-8"?>';
        $s .= '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://oss.dbc.dk/ns/opensearch"><SOAP-ENV:Body>';
        $s .= '    <ns1:searchRequest>';
        $s .= sprintf('    <ns1:query>%s</ns1:query>', 'danmark' );
        $s .= '    <ns1:start>1</ns1:start>';
        $s .= '    <ns1:stepValue>2</ns1:stepValue>';
        $s .= '    <ns1:sortWithBoost>';
        $s .= sprintf( '        <ns1:sort>%s</ns1:sort>', 'title_ascending' );
        $s .= '        <ns1:userDefinedBoost>';
        $s .= '            <ns1:boostField>';
        $s .= sprintf( '                <ns1:fieldName>%s</ns1:fieldName>', 'dc.title' );
        $s .= sprintf( '                <ns1:fieldType>%s</ns1:fieldType>', 'word' );
        $s .= sprintf( '                <ns1:fieldValue>%s</ns1:fieldValue>', 'bog' );
        $s .= sprintf( '                <ns1:weight>%s</ns1:weight>', '4' );
        $s .= '            </ns1:boostField>';
        $s .= '            <ns1:boostField>';
        $s .= sprintf( '                <ns1:fieldName>%s</ns1:fieldName>', 'dc.subject' );
        $s .= sprintf( '                <ns1:fieldType>%s</ns1:fieldType>', 'word' );
        $s .= sprintf( '                <ns1:fieldValue>%s</ns1:fieldValue>', 'USA' );
        $s .= sprintf( '                <ns1:weight>%s</ns1:weight>', '18' );
        $s .= '            </ns1:boostField>';
        $s .= '        </ns1:userDefinedBoost>';
        $s .= '    </ns1:sortWithBoost>';
        $s .= '</ns1:searchRequest>';
        $s .= '</SOAP-ENV:Body></SOAP-ENV:Envelope>';
        
        
        $boosts[ 'sort' ] = 'title_ascending';
        $boosts[ 'fields' ] = array( array( 'name' => 'dc.title', 'type' => 'word', 'value' => 'bog', 'weight' => '4' ),
                                     array( 'name' => 'dc.subject', 'type' => 'word', 'value' => 'USA', 'weight' => '18' ) );
        self::assertEquals( $s, self::createBoostXml( 'danmark', $boosts ) );
    }
    
    //!\name Test helpers
    //@{
    private static function createParam( $xml ) {
        $conv = new xmlconvert();
        $xmlobj = $conv->soap2obj( $xml );
        
        if( $xmlobj->Envelope ) {
            $req = &$xmlobj->Envelope->_value->Body->_value;
        }
        else {
            $req = &$xmlobj;
        }
        return $req;
    }
    
    private static function createParamFromFile( $filename ) {
        $xml = file_get_contents( $filename );
        if( $xml == FALSE ) {
            return null;
        }
        
        return self::createParam( $xml );
    }
    
    private static function createBoostXml( $query, $boosts ) {
        $s = "";
        $s .= '<?xml version="1.0" encoding="UTF-8"?>';
        $s .= '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://oss.dbc.dk/ns/opensearch"><SOAP-ENV:Body>';
        $s .= '    <ns1:searchRequest>';
        $s .= sprintf('    <ns1:query>%s</ns1:query>', $query );
        $s .= '    <ns1:start>1</ns1:start>';
        $s .= '    <ns1:stepValue>2</ns1:stepValue>';
        if( $boosts != null ) {
            $s .= '    <ns1:sortWithBoost>';
            $s .= sprintf( '        <ns1:sort>%s</ns1:sort>', $boosts[ 'sort' ] );
            $s .= '        <ns1:userDefinedBoost>';
            foreach( $boosts[ 'fields' ] as $field ) {
                $s .= '            <ns1:boostField>';
                $s .= sprintf( '                <ns1:fieldName>%s</ns1:fieldName>', $field[ 'name' ] );
                $s .= sprintf( '                <ns1:fieldType>%s</ns1:fieldType>', $field[ 'type' ] );
                $s .= sprintf( '                <ns1:fieldValue>%s</ns1:fieldValue>', $field[ 'value' ] );
                $s .= sprintf( '                <ns1:weight>%s</ns1:weight>', $field[ 'weight' ] );
                $s .= '            </ns1:boostField>';
            }
            $s .= '        </ns1:userDefinedBoost>';
            $s .= '    </ns1:sortWithBoost>';
        }
        $s .= '</ns1:searchRequest>';
        $s .= '</SOAP-ENV:Body></SOAP-ENV:Envelope>';
        
        return $s;        
    }
    //@}
}

?>
