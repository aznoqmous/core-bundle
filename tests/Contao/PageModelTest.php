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
use Contao\Input;
use Contao\Model;
use Contao\Model\Collection;
use Contao\Model\Registry;
use Contao\PageModel;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;
use Symfony\Component\Filesystem\Filesystem;

class PageModelTest extends TestCase
{
    use ExpectDeprecationTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TL_MODELS']['tl_page'] = PageModel::class;

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
        unset($GLOBALS['TL_MODELS'], $GLOBALS['TL_LANG'], $GLOBALS['TL_MIME']);

        PageModel::reset();

        $this->resetStaticProperties([Registry::class, Model::class, DcaExtractor::class, DcaLoader::class, Database::class, Input::class, System::class, Config::class]);

        parent::tearDown();
    }

    public function testCreatingEmptyPageModel(): void
    {
        $pageModel = new PageModel();

        $this->assertNull($pageModel->id);
        $this->assertNull($pageModel->alias);
    }

    public function testCreatingPageModelFromArray(): void
    {
        $pageModel = new PageModel(['id' => '1', 'alias' => 'alias']);

        $this->assertSame('1', $pageModel->id);
        $this->assertSame('alias', $pageModel->alias);
    }

    public function testCreatingPageModelFromDatabaseResult(): void
    {
        $pageModel = new PageModel(new Result([['id' => '1', 'alias' => 'alias']], 'SELECT * FROM tl_page WHERE id = 1'));

        $this->assertSame('1', $pageModel->id);
        $this->assertSame('alias', $pageModel->alias);
    }

    public function testFindByPk(): void
    {
        $statement = $this->createMock(Statement::class);
        $statement
            ->method('execute')
            ->willReturn(new Result([['id' => '1', 'alias' => 'alias']], ''))
        ;

        $database = $this->createMock(Database::class);
        $database
            ->expects($this->once())
            ->method('prepare')
            ->willReturn($statement)
        ;

        $this->mockDatabase($database);

        $pageModel = PageModel::findByPk(1);

        $this->assertSame('1', $pageModel->id);
        $this->assertSame('alias', $pageModel->alias);
    }

    /**
     * @group legacy
     * @dataProvider similarAliasProvider
     */
    public function testFindSimilarByAlias(array $page, string $alias, array $rootData): void
    {
        PageModel::reset();

        $database = $this->createMock(Database::class);
        $database
            ->expects($this->once())
            ->method('execute')
            ->with("SELECT urlPrefix, urlSuffix FROM tl_page WHERE type='root'")
            ->willReturn(new Result($rootData, ''))
        ;

        $aliasStatement = $this->createMock(Statement::class);
        $aliasStatement
            ->expects($this->once())
            ->method('execute')
            ->with('%'.$alias.'%', $page['id'])
            ->willReturn(new Result([['id' => 42]], ''))
        ;

        $database
            ->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM tl_page WHERE tl_page.alias LIKE ? AND tl_page.id!=?')
            ->willReturn($aliasStatement)
        ;

        $this->mockDatabase($database);

        $sourcePage = $this->mockClassWithProperties(PageModel::class, $page);
        $result = PageModel::findSimilarByAlias($sourcePage);

        $this->assertInstanceOf(Collection::class, $result);

        /** @var PageModel $pageModel */
        $pageModel = $result->first();

        $this->assertSame(42, $pageModel->id);
    }

    public function similarAliasProvider(): \Generator
    {
        yield 'Use original alias without prefix and suffix' => [
            [
                'id' => 1,
                'alias' => 'foo',
                'urlPrefix' => '',
                'urlSuffix' => '',
            ],
            'foo',
            [],
        ];

        yield 'Strips prefix' => [
            [
                'id' => 1,
                'alias' => 'de/foo',
                'urlPrefix' => '',
                'urlSuffix' => '',
            ],
            'foo',
            [
                ['urlPrefix' => 'de', 'urlSuffix' => ''],
            ],
        ];

        yield 'Strips multiple prefixes' => [
            [
                'id' => 1,
                'alias' => 'switzerland/german/foo',
                'urlPrefix' => '',
                'urlSuffix' => '',
            ],
            'foo',
            [
                ['urlPrefix' => 'switzerland', 'urlSuffix' => ''],
                ['urlPrefix' => 'switzerland/german', 'urlSuffix' => ''],
            ],
        ];

        yield 'Strips the current prefix' => [
            [
                'id' => 1,
                'alias' => 'de/foo',
                'urlPrefix' => 'de',
                'urlSuffix' => '',
            ],
            'foo',
            [
                ['urlPrefix' => 'en', 'urlSuffix' => ''],
            ],
        ];

        yield 'Strips suffix' => [
            [
                'id' => 1,
                'alias' => 'foo.html',
                'urlPrefix' => '',
                'urlSuffix' => '',
            ],
            'foo',
            [
                ['urlPrefix' => '', 'urlSuffix' => '.html'],
            ],
        ];

        yield 'Strips multiple suffixes' => [
            [
                'id' => 1,
                'alias' => 'foo.php',
                'urlPrefix' => '',
                'urlSuffix' => '',
            ],
            'foo',
            [
                ['urlPrefix' => '', 'urlSuffix' => '.html'],
                ['urlPrefix' => '', 'urlSuffix' => '.php'],
            ],
        ];

        yield 'Strips the current suffix' => [
            [
                'id' => 1,
                'alias' => 'foo.html',
                'urlPrefix' => '',
                'urlSuffix' => '.html',
            ],
            'foo',
            [
                ['urlPrefix' => '', 'urlSuffix' => '.php'],
            ],
        ];
    }

    public function testDoesNotFindSimilarIfAliasIsEmpty(): void
    {
        PageModel::reset();

        $database = $this->createMock(Database::class);
        $database
            ->expects($this->never())
            ->method('execute')
        ;

        $database
            ->expects($this->never())
            ->method('execute')
        ;

        $this->mockDatabase($database);

        $sourcePage = $this->mockClassWithProperties(PageModel::class, [
            'id' => 1,
            'alias' => '',
        ]);

        $sourcePage
            ->expects($this->never())
            ->method('loadDetails')
        ;

        $result = PageModel::findSimilarByAlias($sourcePage);

        $this->assertNull($result);
    }

    /**
     * @param bool|string $expectedLayout
     *
     * @dataProvider layoutInheritanceParentPagesProvider
     */
    public function testInheritingLayoutFromParentsInLoadDetails(array $parents, $expectedLayout): void
    {
        $page = new PageModel();
        $page->pid = 42;

        $numberOfParents = \count($parents);

        // The last page has to be a root page for this test method to prevent
        // running into the check of TL_MODE in PageModel::loadDetails()
        $parents[$numberOfParents - 1][0]['type'] = 'root';

        $statement = $this->createMock(Statement::class);
        $statement
            ->method('execute')
            ->willReturnCallback(
                static function () use (&$parents) {
                    return !empty($parents) ? new Result(array_shift($parents), '') : new Result([], '');
                }
            )
        ;

        $database = $this->createMock(Database::class);
        $database
            ->expects($this->exactly($numberOfParents + 1))
            ->method('prepare')
            ->willReturn($statement)
        ;

        $this->mockDatabase($database);
        $page->loadDetails();

        $this->assertSame($expectedLayout, $page->layout);
    }

    public function layoutInheritanceParentPagesProvider(): \Generator
    {
        yield 'no parent with an inheritable layout' => [
            [
                [['id' => '1', 'pid' => '2']],
                [['id' => '2', 'pid' => '3', 'includeLayout' => '', 'layout' => '1', 'subpageLayout' => '2']],
                [['id' => '3', 'pid' => '0']],
            ],
            false,
        ];

        yield 'inherit layout from parent page' => [
            [
                [['id' => '1', 'pid' => '2']],
                [['id' => '2', 'pid' => '3', 'includeLayout' => '1', 'layout' => '1', 'subpageLayout' => '']],
                [['id' => '3', 'pid' => '0']],
            ],
            '1',
        ];

        yield 'inherit subpages layout from parent page' => [
            [
                [['id' => '1', 'pid' => '2']],
                [['id' => '2', 'pid' => '3', 'includeLayout' => '1', 'layout' => '1', 'subpageLayout' => '2']],
                [['id' => '3', 'pid' => '0']],
            ],
            '2',
        ];

        yield 'multiple parents with layouts' => [
            [
                [['id' => '1', 'pid' => '2', 'includeLayout' => '', 'layout' => '1', 'subpageLayout' => '1']],
                [['id' => '2', 'pid' => '3', 'includeLayout' => '1', 'layout' => '2', 'subpageLayout' => '3']],
                [['id' => '3', 'pid' => '0', 'includeLayout' => '1', 'layout' => '4', 'subpageLayout' => '']],
            ],
            '3',
        ];
    }

    private function mockDatabase(Database $database): void
    {
        $property = (new \ReflectionClass($database))->getProperty('arrInstances');
        $property->setValue([md5(implode('', [])) => $database]);

        $this->assertSame($database, Database::getInstance());
    }
}
