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

use Contao\Config;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\EventListener\RequestTokenListener;
use Contao\CoreBundle\Exception\InvalidRequestTokenException;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Tests\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

class RequestTokenListenerTest extends TestCase
{
    public function testValidatesTheRequestToken(): void
    {
        $request = Request::create('/account.html', 'POST');
        $request->setMethod('POST');
        $request->request->set('REQUEST_TOKEN', 'foo');
        $request->cookies = new InputBag(['unrelated-cookie' => 'to-activate-csrf']);

        $this->validateRequestTokenForRequest($request);
    }

    public function testValidatesTheRequestTokenUponAuthenticatedRequest(): void
    {
        $request = Request::create('/account.html');
        $request->setMethod('POST');
        $request->request->set('REQUEST_TOKEN', 'foo');
        $request->headers->set('PHP_AUTH_USER', 'user');

        $this->validateRequestTokenForRequest($request);
    }

    public function testValidatesTheRequestTokenUponRunningSession(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session
            ->method('isStarted')
            ->willReturn(true)
        ;

        $request = Request::create('/account.html');
        $request->setMethod('POST');
        $request->request->set('REQUEST_TOKEN', 'foo');
        $request->setSession($session);

        $this->validateRequestTokenForRequest($request);
    }

    public function testDoesNotValidateTheRequestTokenWithoutCookies(): void
    {
        $request = Request::create('/account.html');
        $request->setMethod('POST');

        $this->validateRequestTokenForRequest($request, false);
    }

    public function testDoesNotValidateTheRequestTokenWithCsrfCookiesOnly(): void
    {
        $request = Request::create('/account.html');
        $request->setMethod('POST');
        $request->cookies = new InputBag(['csrf_contao_csrf_token' => 'value']);

        $this->validateRequestTokenForRequest($request, false);
    }

    /**
     * @dataProvider getAttributeAndRequest
     */
    public function testValidatesTheRequestTokenDependingOnTheRequest(bool $setAttribute, ?bool $tokenCheck, bool $isContaoRequest, bool $isValidToken): void
    {
        $config = $this->mockConfiguredAdapter(['get' => false]);
        $framework = $this->mockContaoFramework([Config::class => $config]);

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $scopeMatcher
            ->method('isContaoRequest')
            ->willReturn($isContaoRequest)
        ;

        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);
        $csrfTokenManager
            ->expects($isValidToken ? $this->once() : $this->never())
            ->method('isTokenValid')
            ->willReturn(true)
        ;

        $request = Request::create('/account.html');
        $request->setMethod('POST');
        $request->request->set('REQUEST_TOKEN', 'foo');
        $request->cookies = new InputBag(['unrelated-cookie' => 'to-activate-csrf']);

        if ($setAttribute) {
            $request->attributes->set('_token_check', $tokenCheck);
        }

        $event = $this->createMock(RequestEvent::class);
        $event
            ->expects($this->once())
            ->method('getRequest')
            ->willReturn($request)
        ;

        $event
            ->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true)
        ;

        $listener = new RequestTokenListener($framework, $scopeMatcher, $csrfTokenManager, 'contao_csrf_token');
        $listener($event);
    }

    public static function getAttributeAndRequest(): \Generator
    {
        yield 'no attribute, Contao request' => [false, false, true, true];
        yield 'no attribute, not a Contao request' => [false, false, false, false];
        yield 'attribute, Contao request' => [true, true, true, true];
        yield 'attribute, not a Contao request' => [true, true, false, true];
        yield 'falsey attribute, not a Contao request' => [true, null, false, false];
    }

    public function testFailsIfTheRequestTokenIsInvalid(): void
    {
        $config = $this->mockConfiguredAdapter(['get' => false]);
        $framework = $this->mockContaoFramework([Config::class => $config]);

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $scopeMatcher
            ->method('isContaoRequest')
            ->willReturn(true)
        ;

        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);
        $csrfTokenManager
            ->expects($this->once())
            ->method('isTokenValid')
            ->willReturn(false)
        ;

        $request = Request::create('/account.html');
        $request->setMethod('POST');
        $request->request->set('REQUEST_TOKEN', 'foo');
        $request->cookies = new InputBag(['unrelated-cookie' => 'to-activate-csrf']);

        $event = $this->createMock(RequestEvent::class);
        $event
            ->expects($this->once())
            ->method('getRequest')
            ->willReturn($request)
        ;

        $event
            ->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true)
        ;

        $listener = new RequestTokenListener($framework, $scopeMatcher, $csrfTokenManager, 'contao_csrf_token');

        $this->expectException(InvalidRequestTokenException::class);
        $this->expectExceptionMessage('Invalid CSRF token');

        $listener($event);
    }

    public function testDoesNotValidateTheRequestTokenUponNonPostRequests(): void
    {
        $framework = $this->mockContaoFramework();
        $framework
            ->expects($this->never())
            ->method('getAdapter')
        ;

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);

        $request = Request::create('/account.html');
        $request->setMethod('GET');
        $request->cookies = new InputBag(['unrelated-cookie' => 'to-activate-csrf']);

        $event = $this->createMock(RequestEvent::class);
        $event
            ->expects($this->once())
            ->method('getRequest')
            ->willReturn($request)
        ;

        $event
            ->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true)
        ;

        $listener = new RequestTokenListener($framework, $scopeMatcher, $csrfTokenManager, 'contao_csrf_token');
        $listener($event);
    }

    public function testDoesNotValidateTheRequestTokenUponAjaxRequests(): void
    {
        $framework = $this->mockContaoFramework();
        $framework
            ->expects($this->never())
            ->method('getAdapter')
        ;

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);

        $request = Request::create('/account.html');
        $request->setMethod('POST');
        $request->cookies = new InputBag(['unrelated-cookie' => 'to-activate-csrf']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $event = $this->createMock(RequestEvent::class);
        $event
            ->expects($this->once())
            ->method('getRequest')
            ->willReturn($request)
        ;

        $event
            ->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true)
        ;

        $listener = new RequestTokenListener($framework, $scopeMatcher, $csrfTokenManager, 'contao_csrf_token');
        $listener($event);
    }

    public function testDoesNotValidateTheRequestTokenIfTheRequestAttributeIsFalse(): void
    {
        $framework = $this->mockContaoFramework();
        $framework
            ->expects($this->never())
            ->method('getAdapter')
        ;

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);

        $request = Request::create('/account.html');
        $request->setMethod('POST');
        $request->cookies = new InputBag(['unrelated-cookie' => 'to-activate-csrf']);
        $request->attributes->set('_token_check', false);

        $event = $this->createMock(RequestEvent::class);
        $event
            ->expects($this->once())
            ->method('getRequest')
            ->willReturn($request)
        ;

        $event
            ->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true)
        ;

        $listener = new RequestTokenListener($framework, $scopeMatcher, $csrfTokenManager, 'contao_csrf_token');
        $listener($event);
    }

    public function testDoesNotValidateTheRequestTokenIfNoRequestAttributeAndNotAContaoRequest(): void
    {
        $framework = $this->mockContaoFramework();
        $framework
            ->expects($this->never())
            ->method('getAdapter')
        ;

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $scopeMatcher
            ->expects($this->once())
            ->method('isContaoRequest')
            ->willReturn(false)
        ;

        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);

        $request = Request::create('/account.html');
        $request->setMethod('POST');
        $request->cookies = new InputBag(['unrelated-cookie' => 'to-activate-csrf']);

        $event = $this->createMock(RequestEvent::class);
        $event
            ->expects($this->once())
            ->method('getRequest')
            ->willReturn($request)
        ;

        $event
            ->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true)
        ;

        $listener = new RequestTokenListener($framework, $scopeMatcher, $csrfTokenManager, 'contao_csrf_token');
        $listener($event);
    }

    public function testDoesNotValidateTheRequestTokenIfNotAMainRequest(): void
    {
        $framework = $this->mockContaoFramework();
        $framework
            ->expects($this->never())
            ->method('getAdapter')
        ;

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);

        $event = $this->createMock(RequestEvent::class);
        $event
            ->expects($this->never())
            ->method('getRequest')
        ;

        $event
            ->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(false)
        ;

        $listener = new RequestTokenListener($framework, $scopeMatcher, $csrfTokenManager, 'contao_csrf_token');
        $listener($event);
    }

    private function validateRequestTokenForRequest(Request $request, bool $shouldValidate = true): void
    {
        $config = $this->mockConfiguredAdapter(['get' => false]);
        $framework = $this->mockContaoFramework([Config::class => $config]);

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $scopeMatcher
            ->method('isContaoRequest')
            ->willReturn(true)
        ;

        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);
        $csrfTokenManager
            ->expects($shouldValidate ? $this->once() : $this->never())
            ->method('isTokenValid')
            ->willReturn(true)
        ;

        $csrfTokenManager
            ->method('canSkipTokenValidation')
            ->willReturnCallback(
                function () {
                    $tokenManager = new ContaoCsrfTokenManager($this->createMock(RequestStack::class), 'csrf_', new UriSafeTokenGenerator(), $this->createMock(TokenStorageInterface::class));

                    return $tokenManager->canSkipTokenValidation(...\func_get_args());
                }
            )
        ;

        $event = $this->createMock(RequestEvent::class);
        $event
            ->expects($this->once())
            ->method('getRequest')
            ->willReturn($request)
        ;

        $event
            ->expects($this->once())
            ->method('isMainRequest')
            ->willReturn(true)
        ;

        $listener = new RequestTokenListener($framework, $scopeMatcher, $csrfTokenManager, 'contao_csrf_token');
        $listener($event);
    }
}
