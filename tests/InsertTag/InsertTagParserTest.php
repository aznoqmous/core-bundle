<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\InsertTag;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\InsertTag\ChunkedText;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Tests\TestCase;
use Contao\System;

class InsertTagParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = $this->getContainerWithContaoConfiguration($this->getTempDir());
        $container->set('contao.security.token_checker', $this->createMock(TokenChecker::class));

        System::setContainer($container);
    }

    public function testReplace(): void
    {
        $parser = new InsertTagParser($this->createMock(ContaoFramework::class));

        $this->assertSame('<br>', $parser->replace('{{br}}'));

        $this->assertSame(
            [[ChunkedText::TYPE_RAW, '<br>']],
            iterator_to_array($parser->replaceChunked('{{br}}'))
        );
    }

    public function testRender(): void
    {
        $parser = new InsertTagParser($this->createMock(ContaoFramework::class));

        $this->assertSame('<br>', $parser->render('br'));

        $this->expectExceptionMessage('Rendering a single insert tag has to return a single raw chunk');

        $parser->render('br}}foo{{br');
    }
}
