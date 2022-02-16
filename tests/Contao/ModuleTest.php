<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Contao;

use Contao\Config;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Tests\TestCase;
use Contao\Database;
use Contao\Database\Result;
use Contao\Database\Statement;
use Contao\DcaExtractor;
use Contao\DcaLoader;
use Contao\Model;
use Contao\Model\Registry;
use Contao\Module;
use Contao\PageModel;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;
use Symfony\Component\Filesystem\Filesystem;

class ModuleTest extends TestCase
{
    use ExpectDeprecationTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $platform = $this->createMock(AbstractPlatform::class);
        $platform
            ->method('getIdentifierQuoteCharacter')
            ->willReturn('\'')
        ;

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('getDatabasePlatform')
            ->willReturn($platform)
        ;

        $connection
            ->method('quoteIdentifier')
            ->willReturnArgument(0)
        ;

        $container = $this->getContainerWithContaoConfiguration();
        $container->set('database_connection', $connection);
        $container->set('contao.security.token_checker', $this->createMock(TokenChecker::class));
        $container->setParameter('contao.resources_paths', $this->getTempDir());

        (new Filesystem())->mkdir($this->getTempDir().'/languages/en');

        System::setContainer($container);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_MIME']);

        $this->resetStaticProperties([Registry::class, DcaExtractor::class, DcaLoader::class, Database::class, Model::class, System::class, Config::class]);

        parent::tearDown();
    }

    /**
     * @group legacy
     */
    public function testGetPublishedSubpagesWithoutGuestsByPid(): void
    {
        $this->expectDeprecation('Since contao/core-bundle 4.10: Not registering table "tl_page" in $GLOBALS[\'TL_MODELS\'] has been deprecated %s.');

        $databaseResultFirstQuery = [
            ['id' => '1', 'hasSubpages' => '0'],
            ['id' => '2', 'hasSubpages' => '1'],
            ['id' => '3', 'hasSubpages' => '1'],
        ];

        $databaseResultSecondQuery = [
            ['id' => '1', 'alias' => 'alias1'],
            ['id' => '2', 'alias' => 'alias2'],
            ['id' => '3', 'alias' => 'alias3'],
        ];

        $statement = $this->createMock(Statement::class);
        $statement
            ->method('execute')
            ->willReturnOnConsecutiveCalls(new Result($databaseResultFirstQuery, ''), new Result($databaseResultSecondQuery, ''))
        ;

        $database = $this->createMock(Database::class);
        $database
            ->method('prepare')
            ->willReturn($statement)
        ;

        $this->mockDatabase($database);

        $moduleInstance = new class() extends Module {
            public function __construct()
            {
            }

            protected function compile(): void
            {
            }

            public function execute(): ?array
            {
                return self::getPublishedSubpagesByPid(1);
            }
        };

        $pages = $moduleInstance->execute();

        $this->assertIsArray($pages);
        $this->assertCount(3, $pages);

        $this->assertSame($databaseResultSecondQuery[0]['id'], $pages[0]['page']->id);
        $this->assertSame($databaseResultSecondQuery[0]['alias'], $pages[0]['page']->alias);
        $this->assertFalse($pages[0]['hasSubpages']);
        $this->assertSame($databaseResultSecondQuery[1]['id'], $pages[1]['page']->id);
        $this->assertSame($databaseResultSecondQuery[1]['alias'], $pages[1]['page']->alias);
        $this->assertTrue($pages[1]['hasSubpages']);
        $this->assertSame($databaseResultSecondQuery[2]['id'], $pages[2]['page']->id);
        $this->assertSame($databaseResultSecondQuery[2]['alias'], $pages[2]['page']->alias);
        $this->assertTrue($pages[2]['hasSubpages']);

        // Get from model registry
        $page2 = PageModel::findByPk(2);

        $this->assertSame($databaseResultSecondQuery[1]['id'], $page2->id);
        $this->assertSame($databaseResultSecondQuery[1]['alias'], $page2->alias);
        $this->assertNull($page2->hasSubpages, 'The hasSubpages values should not be set in the model registry as it is contains generated data based on the query');
    }

    private function mockDatabase(Database $database): void
    {
        $property = (new \ReflectionClass($database))->getProperty('arrInstances');
        $property->setAccessible(true);
        $property->setValue([md5(implode('', [])) => $database]);

        $this->assertSame($database, Database::getInstance());
    }
}
