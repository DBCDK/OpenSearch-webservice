<?php
require_once 'PHPUnit/Framework/TestCase.php';

define( 'PHPUNIT_RUNNING', TRUE );
require_once 'server.php';

/*!
    \brief Tests the class openSearch.
 
    \author 
        Sune Thomas Poulsen <stp@dbc.dk>
*/
class openSearchTest extends PHPUnit_Framework_TestCase {
    public function testConstructors() {
        self::assertEquals( 1, 1 );
    }
}

?>
