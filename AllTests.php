<?php
define( 'PHPUNIT_RUNNING', TRUE );

require_once 'PHPUnit/Framework/TestSuite.php';
require_once 'openSearchTest.php';

class AllTests {
    
    public function AllTests() {
    }
    
    public static function suite()
    {
        $suite = new PHPUnit_Framework_TestSuite();
        $suite->addTest( new PHPUnit_Framework_TestSuite( 'openSearchTest' ) );
 
        return $suite;
    }
}

?>
