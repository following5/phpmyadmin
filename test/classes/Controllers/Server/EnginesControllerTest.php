<?php
/**
 * Holds EnginesControllerTest class
 *
 * @package PhpMyAdmin-test
 */
declare(strict_types=1);

namespace PhpMyAdmin\Tests\Controllers\Server;

use PhpMyAdmin\Config;
use PhpMyAdmin\Controllers\Server\EnginesController;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Html\MySQLDocumentation;
use PhpMyAdmin\Response;
use PhpMyAdmin\StorageEngine;
use PhpMyAdmin\Template;
use PHPStan\Testing\TestCase;

/**
 * Tests for EnginesController class
 *
 * @package PhpMyAdmin-test
 */
class EnginesControllerTest extends TestCase
{
    /**
     * Prepares environment for the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $GLOBALS['PMA_Config'] = new Config();
        $GLOBALS['PMA_Config']->enableBc();

        $GLOBALS['server'] = 1;
        $GLOBALS['db'] = 'db';
        $GLOBALS['table'] = 'table';
        $GLOBALS['PMA_PHP_SELF'] = 'index.php';
        $GLOBALS['cfg']['Server']['DisableIS'] = false;
    }

    /**
     * @return void
     */
    public function testIndex(): void
    {
        $controller = new EnginesController(
            Response::getInstance(),
            $GLOBALS['dbi'],
            new Template()
        );

        $actual = $controller->index();

        $this->assertStringContainsString(
            '<th>Storage Engine</th>',
            $actual
        );
        $this->assertStringContainsString(
            '<th>Description</th>',
            $actual
        );

        $this->assertStringContainsString(
            '<td>Federated MySQL storage engine</td>',
            $actual
        );
        $this->assertStringContainsString(
            'FEDERATED',
            $actual
        );
        $this->assertStringContainsString(
            'index.php?route=/server/engines/FEDERATED',
            $actual
        );

        $this->assertStringContainsString(
            '<td>dummy comment</td>',
            $actual
        );
        $this->assertStringContainsString(
            'dummy',
            $actual
        );
        $this->assertStringContainsString(
            'index.php?route=/server/engines/dummy',
            $actual
        );
    }

    /**
     * @return void
     */
    public function testShow(): void
    {
        $controller = new EnginesController(
            Response::getInstance(),
            $GLOBALS['dbi'],
            new Template()
        );

        $actual = $controller->show([
            'engine' => 'Pbxt',
            'page' => 'page',
        ]);

        $enginePlugin = StorageEngine::getEngine('Pbxt');

        $this->assertStringContainsString(
            htmlspecialchars($enginePlugin->getTitle()),
            $actual
        );

        $this->assertStringContainsString(
            MySQLDocumentation::show($enginePlugin->getMysqlHelpPage()),
            $actual
        );

        $this->assertStringContainsString(
            htmlspecialchars($enginePlugin->getComment()),
            $actual
        );

        $this->assertStringContainsString(
            __('Variables'),
            $actual
        );
        $this->assertStringContainsString(
            'index.php?route=/server/engines/Pbxt/Documentation',
            $actual
        );
        $this->assertStringContainsString(
            $enginePlugin->getSupportInformationMessage(),
            $actual
        );
        $this->assertStringContainsString(
            'There is no detailed status information available for this '
            . 'storage engine.',
            $actual
        );
    }
}
