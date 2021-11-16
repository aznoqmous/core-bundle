<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Controller;

use Contao\ArticleModel;
use Contao\CoreBundle\Cache\EntityCacheTags;
use Contao\CoreBundle\Controller\SitemapController;
use Contao\CoreBundle\Event\SitemapEvent;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Tests\TestCase;
use Contao\PageModel;
use Contao\System;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class SitemapControllerTest extends TestCase
{
    use ExpectDeprecationTrait;

    public function testNoSitemapIfNoRootPageFound(): void
    {
        $pageModelAdapter = $this->mockAdapter(['findPublishedRootPages']);
        $pageModelAdapter
            ->expects($this->exactly(2))
            ->method('findPublishedRootPages')
            ->withConsecutive(
                [['dns' => 'www.foobar.com']],
                [['dns' => '']]
            )
            ->willReturn(null)
        ;

        $framework = $this->mockContaoFramework([PageModel::class => $pageModelAdapter]);
        $framework
            ->expects($this->once())
            ->method('initialize')
        ;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects($this->never())
            ->method('dispatch')
        ;

        $container = $this->getContainerWithContaoConfiguration();
        $container->set('contao.framework', $framework);
        $container->set('event_dispatcher', $eventDispatcher);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGeneratesSitemapForRegularPages(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
        ]);

        $page1
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page1.html')
        ;

        $framework = $this->mockFrameworkWithPages([42 => [$page1], 43 => null, 21 => null], [43 => null]);
        $container = $this->getContainer($framework);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));
        $this->assertSame($this->getExpectedSitemapContent(['https://www.foobar.com/en/page1.html']), $response->getContent());
    }

    public function testRecursivelyWalksThePageTree(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
        ]);

        $page1
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page1.html')
        ;

        $page2 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 44,
            'pid' => 43,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
        ]);

        $page2
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page2.html')
        ;

        $pages = [
            42 => [$page1],
            43 => [$page2],
            44 => null,
            21 => null,
        ];

        $framework = $this->mockFrameworkWithPages($pages, [43 => null, 44 => null]);
        $container = $this->getContainer($framework);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));

        $expectedUrls = [
            'https://www.foobar.com/en/page1.html',
            'https://www.foobar.com/en/page2.html',
        ];

        $this->assertSame($this->getExpectedSitemapContent($expectedUrls), $response->getContent());
    }

    public function testSkipsUnpublishedPagesButAddsChildPages(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'regular',
            'groups' => [],
            'published' => '',
        ]);

        $page1
            ->expects($this->never())
            ->method('getAbsoluteUrl')
        ;

        $page2 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 44,
            'pid' => 43,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
        ]);

        $page2
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page2.html')
        ;

        $pages = [
            42 => [$page1],
            43 => [$page2],
            44 => null,
            21 => null,
        ];

        // Only find articles for published page
        $articles = [44 => null];

        $framework = $this->mockFrameworkWithPages($pages, $articles);
        $container = $this->getContainer($framework);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));
        $this->assertSame($this->getExpectedSitemapContent(['https://www.foobar.com/en/page2.html']), $response->getContent());
    }

    public function testSkipsPagesThatRequireItemButAddsChildPages(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
            'requireItem' => '1',
        ]);

        $page1
            ->expects($this->never())
            ->method('getAbsoluteUrl')
        ;

        $page2 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 44,
            'pid' => 43,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
        ]);

        $page2
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page2.html')
        ;

        $pages = [
            42 => [$page1],
            43 => [$page2],
            44 => null,
            21 => null,
        ];

        // Only find articles for published page
        $articles = [44 => null];

        $framework = $this->mockFrameworkWithPages($pages, $articles);
        $container = $this->getContainer($framework);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));
        $this->assertSame($this->getExpectedSitemapContent(['https://www.foobar.com/en/page2.html']), $response->getContent());
    }

    public function testSkipsPagesWithoutContentComposition(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'forward',
            'groups' => [],
            'published' => '1',
            'requireItem' => '1',
        ]);

        $page1
            ->expects($this->never())
            ->method('getAbsoluteUrl')
        ;

        $page2 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 44,
            'pid' => 43,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
        ]);

        $page2
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page2.html')
        ;

        $pages = [
            42 => [$page1],
            43 => [$page2],
            44 => null,
            21 => null,
        ];

        // Only find articles for published page
        $articles = [44 => null];

        $framework = $this->mockFrameworkWithPages($pages, $articles);
        $container = $this->getContainer($framework);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));
        $this->assertSame($this->getExpectedSitemapContent(['https://www.foobar.com/en/page2.html']), $response->getContent());
    }

    public function testSkipsNonRegularPages(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'error_404',
            'groups' => [],
            'published' => '1',
        ]);

        $page1
            ->expects($this->never())
            ->method('getAbsoluteUrl')
        ;

        $page2 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 44,
            'pid' => 43,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
        ]);

        $page2
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page2.html')
        ;

        $pages = [
            42 => [$page1],
            43 => [$page2],
            44 => null,
            21 => null,
        ];

        $framework = $this->mockFrameworkWithPages($pages, [44 => null]);
        $container = $this->getContainer($framework);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));
        $this->assertSame($this->getExpectedSitemapContent(['https://www.foobar.com/en/page2.html']), $response->getContent());
    }

    public function testSkipsPagesIfTheUserDoesNotHaveAccess(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'regular',
            'protected' => '',
            'groups' => [],
            'published' => '1',
        ]);

        $page1
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page1.html')
        ;

        $page2 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 44,
            'pid' => 43,
            'type' => 'regular',
            'protected' => '1',
            'groups' => [],
            'published' => '1',
        ]);

        $page2
            ->expects($this->never())
            ->method('getAbsoluteUrl')
        ;

        $pages = [
            42 => [$page1, $page2],
            43 => null,
            21 => null,
        ];

        $framework = $this->mockFrameworkWithPages($pages, [43 => null]);
        $container = $this->getContainer($framework, [44 => false]);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));

        $expectedUrls = [
            'https://www.foobar.com/en/page1.html',
        ];

        $this->assertSame($this->getExpectedSitemapContent($expectedUrls), $response->getContent());
    }

    public function testGeneratesTheTeaserArticleUrls(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'regular',
            'protected' => '',
            'groups' => [],
            'published' => '1',
        ]);

        $page1
            ->expects($this->exactly(2))
            ->method('getAbsoluteUrl')
            ->withConsecutive([null], ['/articles/foobar'])
            ->willReturnOnConsecutiveCalls('https://www.foobar.com/en/page1.html', 'https://www.foobar.com/en/page1/articles/foobar.html')
        ;

        $article1 = $this->mockClassWithProperties(ArticleModel::class, [
            'id' => 1,
            'pid' => 43,
            'alias' => 'foobar',
        ]);

        $framework = $this->mockFrameworkWithPages([42 => [$page1], 43 => null, 21 => null], [43 => [$article1]]);
        $container = $this->getContainer($framework);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));
        $this->assertSame($this->getExpectedSitemapContent(['https://www.foobar.com/en/page1.html', 'https://www.foobar.com/en/page1/articles/foobar.html']), $response->getContent());
    }

    public function testGeneratesTheTeaserArticleUrlsByIdIfAliasIsEmpty(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'regular',
            'protected' => '',
            'groups' => [],
            'published' => '1',
        ]);

        $page1
            ->expects($this->exactly(2))
            ->method('getAbsoluteUrl')
            ->withConsecutive([null], ['/articles/1'])
            ->willReturnOnConsecutiveCalls('https://www.foobar.com/en/page1.html', 'https://www.foobar.com/en/page1/articles/1.html')
        ;

        $article1 = $this->mockClassWithProperties(ArticleModel::class, [
            'id' => 1,
            'pid' => 43,
            'alias' => '',
        ]);

        $framework = $this->mockFrameworkWithPages([42 => [$page1], 43 => null, 21 => null], [43 => [$article1]]);
        $container = $this->getContainer($framework);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));
        $this->assertSame($this->getExpectedSitemapContent(['https://www.foobar.com/en/page1.html', 'https://www.foobar.com/en/page1/articles/1.html']), $response->getContent());
    }

    public function testSkipsNoindexNofollowPages(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
            'robots' => 'index,follow',
        ]);

        $page1
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page1.html')
        ;

        $page2 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 44,
            'pid' => 42,
            'type' => 'regular',
            'groups' => [],
            'published' => '1',
            'robots' => 'noindex,nofollow',
        ]);

        $page2
            ->expects($this->never())
            ->method('getAbsoluteUrl')
        ;

        $pages = [
            42 => [$page1, $page2],
            43 => null,
            44 => null,
            21 => null,
        ];

        $framework = $this->mockFrameworkWithPages($pages, [43 => null]);
        $container = $this->getContainer($framework);

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($container);
        $response = $controller(Request::create('https://www.foobar.com/sitemap.xml'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('public, s-maxage=2592000', $response->headers->get('Cache-Control'));
        $this->assertSame($this->getExpectedSitemapContent(['https://www.foobar.com/en/page1.html']), $response->getContent());
    }

    /**
     * @group legacy
     */
    public function testCallsTheLegacyHookForEachRootPage(): void
    {
        $page1 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 43,
            'pid' => 42,
            'type' => 'regular',
            'protected' => '',
            'groups' => [],
            'published' => '1',
        ]);

        $page1
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page1.html')
        ;

        $page2 = $this->mockClassWithProperties(PageModel::class, [
            'id' => 22,
            'pid' => 21,
            'type' => 'regular',
            'protected' => '',
            'groups' => [],
            'published' => '1',
        ]);

        $page2
            ->expects($this->once())
            ->method('getAbsoluteUrl')
            ->willReturn('https://www.foobar.com/en/page2.html')
        ;

        $GLOBALS['TL_HOOKS']['getSearchablePages'] = [['FooClass', 'fooFunction'], ['BarClass', 'barFunction']];

        $hook1 = $this->mockAdapter(['fooFunction']);
        $hook1
            ->expects($this->exactly(2))
            ->method('fooFunction')
            ->withConsecutive(
                [['https://www.foobar.com/en/page1.html'], 42, true, 'en'],
                [['https://www.foobar.com/en/page2.html'], 21, true, 'de']
            )
            ->willReturnOnConsecutiveCalls(['page1.html'], ['page2.html'])
        ;

        $hook2 = $this->mockAdapter(['barFunction']);
        $hook2
            ->expects($this->exactly(2))
            ->method('barFunction')
            ->withConsecutive(
                [['page1.html'], 42, true, 'en'],
                [['page2.html'], 21, true, 'de']
            )
            ->willReturnArgument(0)
        ;

        $framework = $this->mockFrameworkWithPages(
            [42 => [$page1], 43 => null, 21 => [$page2], 22 => null],
            [43 => null, 22 => null],
            ['FooClass' => $hook1, 'BarClass' => $hook2]
        );

        $controller = new SitemapController($this->mockPageRegistry());
        $controller->setContainer($this->getContainer($framework));

        $this->expectDeprecation('Since contao/core-bundle 4.11: Using the "getSearchablePages" hook is deprecated. Use the "contao.sitemap" event instead.');

        $controller(Request::create('https://www.foobar.com/sitemap.xml'));
    }

    private function getExpectedSitemapContent(array $urls): string
    {
        $result = '<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="https://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $url) {
            $result .= "
  <url>
    <loc>$url</loc>
  </url>";
        }

        $result .= '
</urlset>
';

        return $result;
    }

    /**
     * @return PageRegistry&MockObject
     */
    private function mockPageRegistry(): PageRegistry
    {
        $pageRegistry = $this->createMock(PageRegistry::class);
        $pageRegistry
            ->method('supportsContentComposition')
            ->willReturnCallback(
                static fn (PageModel $pageModel) => empty($pageModel->type) || 'regular' === $pageModel->type
            )
        ;

        return $pageRegistry;
    }

    /**
     * @return ContaoFramework&MockObject
     */
    private function mockFrameworkWithPages(array $pages, array $articles, array $hooks = null): ContaoFramework
    {
        /** @var PageModel $rootPage1 */
        $rootPage1 = $this->mockClassWithProperties(PageModel::class);
        $rootPage1->id = 42;
        $rootPage1->dns = 'www.foobar.com';
        $rootPage1->urlPrefix = 'en';
        $rootPage1->language = 'en';
        $rootPage1->fallback = '1';

        /** @var PageModel $rootPage2 */
        $rootPage2 = $this->mockClassWithProperties(PageModel::class);
        $rootPage2->id = 21;
        $rootPage2->dns = 'www.foobar.com';
        $rootPage2->urlPrefix = 'de';
        $rootPage2->language = 'de';
        $rootPage2->fallback = '';

        $pageModelAdapter = $this->mockAdapter(['findPublishedRootPages', 'findByPid']);
        $pageModelAdapter
            ->expects($this->once())
            ->method('findPublishedRootPages')
            ->with(['dns' => 'www.foobar.com'])
            ->willReturn([$rootPage1, $rootPage2])
        ;

        $pageModelAdapter
            ->expects($this->exactly(\count($pages)))
            ->method('findByPid')
            ->withConsecutive(...array_map(
                static fn ($parentId) => [$parentId, ['order' => 'sorting']],
                array_keys($pages)
            ))
            ->willReturnOnConsecutiveCalls(...$pages)
        ;

        $articleModelAdapter = $this->mockAdapter(['findPublishedWithTeaserByPid']);
        $articleModelAdapter
            ->expects($this->exactly(\count($articles)))
            ->method('findPublishedWithTeaserByPid')
            ->withConsecutive(...array_map(
                static fn ($pageId) => [$pageId, ['ignoreFePreview' => true]],
                array_keys($articles)
            ))
            ->willReturnOnConsecutiveCalls(...$articles)
        ;

        $systemAdapter = $this->mockAdapter(['importStatic']);

        if (null === $hooks) {
            $systemAdapter
                ->expects($this->never())
                ->method('importStatic')
            ;
        } else {
            // Each hook is called twice (for each root page)
            $systemAdapter
                ->expects($this->exactly(\count($hooks) * 2))
                ->method('importStatic')
                ->withConsecutive(...array_map(
                    static fn ($objectName) => [$objectName],
                    [...array_keys($hooks), ...array_keys($hooks)]
                ))
                ->willReturnOnConsecutiveCalls(...[...array_values($hooks), ...array_values($hooks)])
            ;
        }

        $framework = $this->mockContaoFramework([
            PageModel::class => $pageModelAdapter,
            ArticleModel::class => $articleModelAdapter,
            System::class => $systemAdapter,
        ]);

        $framework
            ->expects($this->once())
            ->method('initialize')
        ;

        return $framework;
    }

    /**
     * @param array<bool> $isGranted
     */
    private function getContainer(ContaoFramework $framework, array $isGranted = null): ContainerBuilder
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                function (SitemapEvent $event) {
                    $this->assertSame('https://www.foobar.com/sitemap.xml', $event->getRequest()->getUri());
                    $this->assertSame([42, 21], $event->getRootPageIds());

                    return true;
                }
            ))
        ;

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);

        if (null === $isGranted) {
            $authorizationChecker
                ->method('isGranted')
                ->willReturn(true)
            ;
        } else {
            $authorizationChecker
                ->expects($this->exactly(\count($isGranted)))
                ->method('isGranted')
                ->willReturnOnConsecutiveCalls(...$isGranted)
            ;
        }

        $entityCacheTags = $this->createMock(EntityCacheTags::class);
        $entityCacheTags
            ->expects($this->once())
            ->method('tagWith')
            ->with([
                'contao.sitemap',
                'contao.sitemap.42',
                'contao.sitemap.21',
            ])
        ;

        $container = $this->getContainerWithContaoConfiguration();
        $container->set('contao.framework', $framework);
        $container->set('event_dispatcher', $eventDispatcher);
        $container->set('security.authorization_checker', $authorizationChecker);
        $container->set(EntityCacheTags::class, $entityCacheTags);

        return $container;
    }
}
