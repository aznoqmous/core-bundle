<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\MarkdownController;
use Contao\CoreBundle\Framework\Adapter;
use Contao\FilesModel;
use Contao\FrontendTemplate;
use Contao\Input;
use Contao\TestCase\ContaoTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MarkdownControllerTest extends ContaoTestCase
{
    public function testWithCodeInput(): void
    {
        $container = $this->mockContainer('<h1>Headline</h1>'."\n");

        $contentModel = $this->mockClassWithProperties(ContentModel::class);
        $contentModel->markdownSource = 'sourceText';
        $contentModel->code = '# Headline';

        $controller = new MarkdownController();
        $controller->setContainer($container);
        $controller(new Request(), $contentModel, 'main');
    }

    public function testDisallowedTagsAreCorrectlyStripped(): void
    {
        $expectedHtml = <<<'HTML'
            <h1>Headline</h1>
            &lt;iframe src&#61;&#34;https://example.com&#34;&#62;&lt;/iframe&#62;
            &lt;script&#62;I might be evil.&lt;/script&#62;
            <img>
            <video controls="">
                <source src="contao.mp4" type="video/mp4">
            </video>
            <p>Foobar.</p>

            HTML;

        $container = $this->mockContainer($expectedHtml);

        $contentModel = $this->mockClassWithProperties(ContentModel::class);
        $contentModel->markdownSource = 'sourceText';
        $contentModel->code = <<<'MARKDOWN'
            # Headline

            <iframe src="https://example.com"></iframe>
            <script>I might be evil.</script>
            <img onerror="I might be evil">
            <video controls>
                <source src="contao.mp4" type="video/mp4">
            </video>

            Foobar.
            MARKDOWN;

        $controller = new MarkdownController();
        $controller->setContainer($container);
        $controller(new Request(), $contentModel, 'main');
    }

    public function testWithFileInput(): void
    {
        $fs = new Filesystem();
        $tempTestFile = $fs->tempnam($this->getTempDir(), '');
        $fs->dumpFile($tempTestFile, '# Headline');

        $filesModel = $this->mockClassWithProperties(FilesModel::class);
        $filesModel
            ->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn($tempTestFile)
        ;

        $filesAdapter = $this->mockConfiguredAdapter(['findByPk' => $filesModel]);
        $container = $this->mockContainer('<h1>Headline</h1>'."\n", [FilesModel::class => $filesAdapter]);

        $contentModel = $this->mockClassWithProperties(ContentModel::class);
        $contentModel->markdownSource = 'sourceFile';
        $contentModel->singleSRC = 'uuid';

        $controller = new MarkdownController();
        $controller->setContainer($container);
        $controller(new Request(), $contentModel, 'main');

        $fs->remove($tempTestFile);
    }

    private function mockContainer(string $expectedMarkdown, array $frameworkAdapters = []): Container
    {
        $template = $this->createMock(FrontendTemplate::class);
        $template
            ->expects($this->once())
            ->method('getResponse')
            ->willReturn(new Response())
        ;

        $template
            ->method('__set')
            ->withConsecutive(
                [$this->equalTo('headline'), $this->isNull()],
                [$this->equalTo('hl'), $this->equalTo('h1')],
                [$this->equalTo('class'), $this->equalTo('ce_markdown')],
                [$this->equalTo('cssID'), $this->equalTo('')],
                [$this->equalTo('inColumn'), $this->equalTo('main')],
                [$this->equalTo('content'), $this->equalTo($expectedMarkdown)],
            )
        ;

        if (!isset($frameworkAdapters[Input::class])) {
            $frameworkAdapters[Input::class] = new Adapter(Input::class);
        }

        $framework = $this->mockContaoFramework($frameworkAdapters);
        $framework
            ->expects($this->once())
            ->method('createInstance')
            ->with(FrontendTemplate::class, ['ce_markdown'])
            ->willReturn($template)
        ;

        $container = $this->getContainerWithContaoConfiguration();
        $container->set('contao.framework', $framework);

        return $container;
    }
}
