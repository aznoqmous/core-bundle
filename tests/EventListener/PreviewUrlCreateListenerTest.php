<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\EventListener;

use Contao\ArticleModel;
use Contao\CoreBundle\Event\PreviewUrlCreateEvent;
use Contao\CoreBundle\EventListener\PreviewUrlCreateListener;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\TestCase\ContaoTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class PreviewUrlCreateListenerTest extends ContaoTestCase
{
    public function testCreatesThePreviewUrlForPages(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $event = new PreviewUrlCreateEvent('page', 42);

        /** @var PageModel&MockObject $pageModel */
        $pageModel = $this->mockClassWithProperties(PageModel::class);

        $adapters = [
            PageModel::class => $this->mockConfiguredAdapter(['findByPk' => $pageModel]),
        ];

        $framework = $this->mockContaoFramework($adapters);

        $listener = new PreviewUrlCreateListener($requestStack, $framework);
        $listener($event);

        $this->assertSame('page=42', $event->getQuery());
    }

    public function testCreatesThePreviewUrlForArticles(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $event = new PreviewUrlCreateEvent('article', 3);

        /** @var ArticleModel&MockObject $articleModel */
        $articleModel = $this->mockClassWithProperties(ArticleModel::class, ['pid' => 42]);

        /** @var PageModel&MockObject $pageModel */
        $pageModel = $this->mockClassWithProperties(PageModel::class);

        $adapters = [
            ArticleModel::class => $this->mockConfiguredAdapter(['findByPk' => $articleModel]),
            PageModel::class => $this->mockConfiguredAdapter(['findByPk' => $pageModel]),
        ];

        $framework = $this->mockContaoFramework($adapters);

        $listener = new PreviewUrlCreateListener($requestStack, $framework);
        $listener($event);

        $this->assertSame('page=42', $event->getQuery());
    }

    /**
     * @dataProvider getValidDoParameters
     */
    public function testDoesNotCreateAnyPreviewUrlIfTheFrameworkIsNotInitialized(string $do): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework
            ->method('isInitialized')
            ->willReturn(false)
        ;

        $event = new PreviewUrlCreateEvent($do, 42);

        $listener = new PreviewUrlCreateListener(new RequestStack(), $framework);
        $listener($event);

        $this->assertNull($event->getQuery());
    }

    /**
     * @dataProvider getInvalidDoParameters
     */
    public function testDoesNotCreateThePreviewUrlIfNeitherPageNorArticleParameterIsSet(string $do): void
    {
        $framework = $this->mockContaoFramework();
        $event = new PreviewUrlCreateEvent($do, 1);

        $listener = new PreviewUrlCreateListener(new RequestStack(), $framework);
        $listener($event);

        $this->assertNull($event->getQuery());
    }

    /**
     * @dataProvider getValidDoParameters
     */
    public function testDoesNotCreateThePreviewUrlIfThereIsNoId(string $do): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $framework = $this->mockContaoFramework();
        $event = new PreviewUrlCreateEvent($do, 0);

        $listener = new PreviewUrlCreateListener($requestStack, $framework);
        $listener($event);

        $this->assertNull($event->getQuery());
    }

    /**
     * @dataProvider getValidDoParameters
     */
    public function testDoesNotCreateThePreviewUrlIfThereIsNoPageItem(string $do): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        /** @var ArticleModel&MockObject $articleModel */
        $articleModel = $this->mockClassWithProperties(ArticleModel::class, ['pid' => 42]);

        $adapters = [
            PageModel::class => $this->mockConfiguredAdapter(['findByPk' => null]),
            ArticleModel::class => $this->mockConfiguredAdapter(['findByPk' => $articleModel]),
        ];

        $framework = $this->mockContaoFramework($adapters);
        $event = new PreviewUrlCreateEvent($do, 1);

        $listener = new PreviewUrlCreateListener($requestStack, $framework);
        $listener($event);

        $this->assertNull($event->getQuery());
    }

    public function getValidDoParameters(): \Generator
    {
        yield ['page'];
        yield ['article'];
    }

    public function getInvalidDoParameters(): \Generator
    {
        yield [''];
        yield ['news'];
        yield ['calendar'];
    }
}
