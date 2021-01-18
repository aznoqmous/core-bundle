<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\EventListener\Widget;

use Contao\CoreBundle\EventListener\Widget\HttpUrlListener;
use Contao\CoreBundle\ServiceAnnotation\Hook;
use Contao\CoreBundle\Tests\TestCase;
use Contao\Widget;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Contracts\Translation\TranslatorInterface;

class HttpUrlListenerTest extends TestCase
{
    public function testServiceAnnotation(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->never())
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $listener = new HttpUrlListener($translator);

        $annotationReader = new AnnotationReader();
        $annotation = $annotationReader->getClassAnnotation(new \ReflectionClass($listener), Hook::class);

        $this->assertSame('addCustomRegexp', $annotation->value);
        $this->assertSame(0, (int) $annotation->priority);
    }

    public function testReturnsFalseIfNotHttpurlType(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->never())
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $listener = new HttpUrlListener($translator);

        $this->assertFalse($listener('foobar', 'input', $this->createMock(Widget::class)));
    }

    public function testReturnsTrueIfNoString(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->never())
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $listener = new HttpUrlListener($translator);

        $this->assertTrue($listener(HttpUrlListener::RGXP_NAME, [], $this->createMock(Widget::class)));
    }

    public function testAddsErrorIfInputIsNotAbsoluteUrl(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $widget = $this->createMock(Widget::class);
        $widget
            ->expects($this->once())
            ->method('addError')
            ->with('ERR.invalidHttpUrl')
        ;

        $listener = new HttpUrlListener($translator);

        $this->assertTrue($listener(HttpUrlListener::RGXP_NAME, 'example.com', $widget));
    }

    public function testAddsErrorIfInputIsNotValidUrl(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $widget = $this->createMock(Widget::class);
        $widget
            ->expects($this->once())
            ->method('addError')
            ->with('ERR.url')
        ;

        $listener = new HttpUrlListener($translator);

        $this->assertTrue($listener(HttpUrlListener::RGXP_NAME, 'https://example.com\\', $widget));
    }

    public function testDoesNotAddErrorIfInputIsAbsoluteUrl(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->never())
            ->method('trans')
            ->willReturnArgument(0)
        ;

        $widget = $this->createMock(Widget::class);
        $widget
            ->expects($this->never())
            ->method('addError')
            ->with('ERR.invalidHttpUrl')
        ;

        $listener = new HttpUrlListener($translator);

        $this->assertTrue($listener(HttpUrlListener::RGXP_NAME, 'https://example.com', $widget));
    }
}
