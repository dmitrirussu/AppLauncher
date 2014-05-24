<?php
/**
 * Created by Dumitru Russu.
 * Date: 21.05.2014
 * Time: 22:59
 * ${NAMESPACE}${NAME} 
 */

class OmlManagerEntitiesGeneratorTest extends PHPUnit_Framework_TestCase {

	/**
	 * Generate Database Entities
	 */
	public function testGenerateDbEntities() {

		$result = (bool)system('php console/generator.php create:app:db:entities demo launch');

		echo "Generate Db Entities\n";

		$this->assertTrue($result);
	}
} 