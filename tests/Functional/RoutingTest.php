<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Functional;

use Contao\Config;
use Contao\Environment;
use Contao\Input;
use Contao\InsertTags;
use Contao\System;
use Contao\TestCase\ContaoDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RoutingTest extends WebTestCase
{
    use ContaoDatabaseTrait;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::loadFileIntoDatabase(__DIR__.'/app/Resources/contao_test.sql');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];

        Input::resetCache();
        Input::resetUnusedGet();
        Environment::reset();
        InsertTags::reset();

        Config::set('debugMode', false);
        Config::set('useAutoItem', true);
        Config::set('addLanguageToUrl', false);
    }

    /**
     * @dataProvider getAliases
     */
    public function testResolvesAliases(string $request, int $statusCode, string $pageTitle, array $query, string $host, bool $autoItem, bool $folderUrl): void
    {
        Config::set('useAutoItem', $autoItem);
        Config::set('folderUrl', $folderUrl);

        $_SERVER['REQUEST_URI'] = $request;
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

        $client = $this->createClient([], $_SERVER);
        System::setContainer($client->getContainer());

        $crawler = $client->request('GET', $request);
        $title = trim($crawler->filterXPath('//head/title')->text());
        $response = $client->getResponse();

        $this->assertSame($statusCode, $response->getStatusCode());
        $this->assertSame($query, $_GET);
        $this->assertStringContainsString($pageTitle, $title);
    }

    public function getAliases(): \Generator
    {
        yield 'Renders the page if the alias is "index" and the request is empty' => [
            '/',
            200,
            'Index - Root with index page',
            [],
            'root-with-index.local',
            false,
            false,
        ];

        yield 'Redirects to the first regular page if the alias is not "index" and the request is empty' => [
            '/',
            303,
            'Redirecting to http://root-with-home.local/home.html',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the page if the alias matches' => [
            '/home.html',
            200,
            'Home - Root with home page',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if there is an item with an empty key' => [
            '/home//.html',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the URL suffix does not match' => [
            '/home.xml',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the URL contains the "auto_item" keyword' => [
            '/home/auto_item/foo.html',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains duplicate keys' => [
            '/home/foo/bar1/foo/bar2.html',
            404,
            '(404 Not Found)',
            ['foo' => 'bar1'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains an unused argument' => [
            '/home/foo/bar.html',
            404,
            '(404 Not Found)',
            ['foo' => 'bar'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains an unused argument without value' => [
            '/home/foo.html',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains an unused argument with an empty value' => [
            '/home/foo/.html',
            404,
            '(404 Not Found)',
            ['foo' => ''],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the page if an existing item is requested' => [
            '/home/items/foobar.html',
            200,
            'Foobar - Root with home page',
            ['items' => 'foobar'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains an item with an empty key' => [
            '/home//foo.html',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the alias is empty' => [
            '/.html',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Urldecodes the alias' => [
            '/h%C3%B6me.html',
            200,
            'Höme - Root with special chars',
            [],
            'root-with-special-chars.local',
            false,
            false,
        ];

        yield 'Renders the page if auto items are enabled and an existing item is requested' => [
            '/home/foobar.html',
            200,
            'Foobar - Root with home page',
            ['auto_item' => 'foobar', 'items' => 'foobar'],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Renders the 404 page if auto items are enabled and the URL contains the "auto_item" keyword' => [
            '/home/auto_item/foo.html',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Renders the 404 page if auto items are enabled and the URL contains an auto item keyword' => [
            '/home/items/foobar.html',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Redirects to the first regular page if the folder URL alias is not "index" and the request is empty' => [
            '/',
            303,
            'Redirecting to http://root-with-folder-urls.local/folder/url/home.html',
            [],
            'root-with-folder-urls.local',
            false,
            true,
        ];

        yield 'Renders the page if the folder URL alias matches' => [
            '/folder/url/home.html',
            200,
            'Home - Root with folder URLs',
            [],
            'root-with-folder-urls.local',
            false,
            true,
        ];

        yield 'Renders the folder URL page if an existing item is requested' => [
            '/folder/url/home/items/foobar.html',
            200,
            'Foobar - Root with folder URLs',
            ['items' => 'foobar'],
            'root-with-folder-urls.local',
            false,
            true,
        ];

        yield 'Renders the folder URL page if auto items are enabled an existing item is requested' => [
            '/folder/url/home/foobar.html',
            200,
            'Foobar - Root with folder URLs',
            ['auto_item' => 'foobar', 'items' => 'foobar'],
            'root-with-folder-urls.local',
            true,
            true,
        ];

        yield 'Renders the 404 page if auto items are enabled and the folder URL contains the "auto_item" keyword' => [
            '/folder/url/home/auto_item/foo.html',
            404,
            '(404 Not Found)',
            [],
            'root-with-folder-urls.local',
            true,
            true,
        ];

        yield 'Renders the 404 page if auto items are enabled and the folder URL contains an auto item keyword' => [
            '/folder/url/home/items/foobar.html',
            404,
            '(404 Not Found)',
            [],
            'root-with-folder-urls.local',
            true,
            true,
        ];
    }

    /**
     * @dataProvider getAliasesWithLocale
     */
    public function testResolvesAliasesWithLocale(string $request, int $statusCode, string $pageTitle, array $query, string $host, bool $autoItem, bool $folderUrl): void
    {
        Config::set('useAutoItem', $autoItem);
        Config::set('folderUrl', $folderUrl);
        Config::set('addLanguageToUrl', true);

        $_SERVER['REQUEST_URI'] = $request;
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

        $client = $this->createClient(['environment' => 'locale'], $_SERVER);
        System::setContainer($client->getContainer());

        $crawler = $client->request('GET', $request);
        $title = trim($crawler->filterXPath('//head/title')->text());
        $response = $client->getResponse();

        $this->assertSame($statusCode, $response->getStatusCode());
        $this->assertSame($query, $_GET);
        $this->assertStringContainsString($pageTitle, $title);
    }

    public function getAliasesWithLocale(): \Generator
    {
        yield 'Redirects to the language root if the request is empty' => [
            '/',
            302,
            'Redirecting to http://root-with-index.local/en/',
            ['language' => 'en'],
            'root-with-index.local',
            false,
            false,
        ];

        yield 'Renders the page if the alias is "index" and the request contains the language only' => [
            '/en/',
            200,
            'Index - Root with index page',
            ['language' => 'en'],
            'root-with-index.local',
            false,
            false,
        ];

        yield 'Renders the page if the alias matches' => [
            '/en/home.html',
            200,
            'Home - Root with home page',
            ['language' => 'en'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Redirects if the alias matches but no language is given' => [
            '/home.html',
            303,
            'Redirecting to http://root-with-home.local/en/home.html',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the URL suffix does not match' => [
            '/en/home.xml',
            404,
            '(404 Not Found)',
            ['language' => 'en'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the URL contains the "auto_item" keyword' => [
            '/en/home/auto_item/foo.html',
            404,
            '(404 Not Found)',
            ['language' => 'en'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains duplicate keys' => [
            '/en/home/foo/bar1/foo/bar2.html',
            404,
            '(404 Not Found)',
            ['language' => 'en', 'foo' => 'bar1'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains an unused argument' => [
            '/en/home/foo/bar.html',
            404,
            '(404 Not Found)',
            ['language' => 'en', 'foo' => 'bar'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the page if an existing item is requested' => [
            '/en/home/items/foobar.html',
            200,
            'Foobar - Root with home page',
            ['language' => 'en', 'items' => 'foobar'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains item with an empty key' => [
            '/en/home//foo.html',
            404,
            '(404 Not Found)',
            ['language' => 'en'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the alias is empty' => [
            '/en/.html',
            404,
            '(404 Not Found)',
            ['language' => 'en'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Urldecodes the alias' => [
            '/en/h%C3%B6me.html',
            200,
            'Höme - Root with special chars',
            ['language' => 'en'],
            'root-with-special-chars.local',
            false,
            false,
        ];

        yield 'Renders the page if auto items are enabled and an existing item is requested' => [
            '/en/home/foobar.html',
            200,
            'Foobar - Root with home page',
            ['language' => 'en', 'auto_item' => 'foobar', 'items' => 'foobar'],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Renders the 404 page if auto items are enabled and there is item with an empty key' => [
            '/en/home/foobar//foo.html',
            404,
            '(404 Not Found)',
            ['language' => 'en', 'auto_item' => 'foobar'],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Renders the page if there is an item with an empty value and another item with an empty key' => [
            '/en/home/foobar///foo.html',
            404,
            '(404 Not Found)',
            ['language' => 'en', 'foobar' => ''],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Renders the 404 page if auto items are enabled and the URL contains the "auto_item" keyword' => [
            '/en/home/auto_item/foo.html',
            404,
            '(404 Not Found)',
            ['language' => 'en'],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Renders the 404 page if auto items are enabled and the URL contains an auto item keyword' => [
            '/en/home/items/foobar.html',
            404,
            '(404 Not Found)',
            ['language' => 'en'],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Renders the page if the folder URL alias matches' => [
            '/en/folder/url/home.html',
            200,
            'Home - Root with folder URLs',
            ['language' => 'en'],
            'root-with-folder-urls.local',
            false,
            true,
        ];

        yield 'Renders the folder URL page if an existing item is requested' => [
            '/en/folder/url/home/items/foobar.html',
            200,
            'Foobar - Root with folder URLs',
            ['language' => 'en', 'items' => 'foobar'],
            'root-with-folder-urls.local',
            false,
            true,
        ];

        yield 'Renders the folder URL page if auto items are enabled an existing item is requested' => [
            '/en/folder/url/home/foobar.html',
            200,
            'Foobar - Root with folder URLs',
            ['language' => 'en', 'auto_item' => 'foobar', 'items' => 'foobar'],
            'root-with-folder-urls.local',
            true,
            true,
        ];

        yield 'Renders the 404 page if auto items are enabled and the folder URL contains the "auto_item" keyword' => [
            '/en/folder/url/home/auto_item/foo.html',
            404,
            '(404 Not Found)',
            ['language' => 'en'],
            'root-with-folder-urls.local',
            true,
            true,
        ];

        yield 'Renders the 404 page if auto items are enabled and the folder URL contains an auto item keyword' => [
            '/en/folder/url/home/items/foobar.html',
            404,
            '(404 Not Found)',
            ['language' => 'en'],
            'root-with-folder-urls.local',
            true,
            true,
        ];
    }

    /**
     * @dataProvider getAliasesWithoutUrlSuffix
     */
    public function testResolvesAliasesWithoutUrlSuffix(string $request, int $statusCode, string $pageTitle, array $query, string $host, bool $autoItem, bool $folderUrl): void
    {
        Config::set('useAutoItem', $autoItem);
        Config::set('folderUrl', $folderUrl);

        $_SERVER['REQUEST_URI'] = $request;
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

        $client = $this->createClient(['environment' => 'suffix'], $_SERVER);
        System::setContainer($client->getContainer());

        $crawler = $client->request('GET', $request);
        $title = trim($crawler->filterXPath('//head/title')->text());
        $response = $client->getResponse();

        $this->assertSame($statusCode, $response->getStatusCode());
        $this->assertSame($query, $_GET);
        $this->assertStringContainsString($pageTitle, $title);
    }

    public function getAliasesWithoutUrlSuffix(): \Generator
    {
        yield 'Renders the page if the alias is "index" and the request is empty' => [
            '/',
            200,
            'Index - Root with index page',
            [],
            'root-with-index.local',
            false,
            false,
        ];

        yield 'Redirects to the first regular page if the alias is not "index" and the request is empty' => [
            '/',
            303,
            'Redirecting to http://root-with-home.local/home',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the page if the alias matches' => [
            '/home',
            200,
            'Home - Root with home page',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the URL suffix does not match' => [
            '/home.xml',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the URL contains the "auto_item" keyword' => [
            '/home/auto_item/foo',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains duplicate keys' => [
            '/home/foo/bar1/foo/bar2',
            404,
            '(404 Not Found)',
            ['foo' => 'bar1'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains an unused argument' => [
            '/home/foo/bar',
            404,
            '(404 Not Found)',
            ['foo' => 'bar'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the page if an existing item is requested' => [
            '/home/items/foobar',
            200,
            'Foobar - Root with home page',
            ['items' => 'foobar'],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Renders the 404 page if the path contains an item with item with an empty key' => [
            '/home//foo',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            false,
            false,
        ];

        yield 'Urldecodes the alias' => [
            '/h%C3%B6me',
            200,
            'Höme - Root with special chars',
            [],
            'root-with-special-chars.local',
            false,
            false,
        ];

        yield 'Renders the page if auto items are enabled an existing item is requested' => [
            '/home/foobar',
            200,
            'Foobar - Root with home page',
            ['auto_item' => 'foobar', 'items' => 'foobar'],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Renders the 404 page if auto items are enabled and the URL contains the "auto_item" keyword' => [
            '/home/auto_item/foo',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Renders the 404 page if auto items are enabled and the URL contains an auto item keyword' => [
            '/home/items/foobar',
            404,
            '(404 Not Found)',
            [],
            'root-with-home.local',
            true,
            false,
        ];

        yield 'Redirects to the first regular page if the folder URL alias is not "index" and the request is empty' => [
            '/',
            303,
            'Redirecting to http://root-with-folder-urls.local/folder/url/home',
            [],
            'root-with-folder-urls.local',
            false,
            true,
        ];

        yield 'Renders the page if the folder URL alias matches' => [
            '/folder/url/home',
            200,
            'Home - Root with folder URLs',
            [],
            'root-with-folder-urls.local',
            false,
            true,
        ];

        yield 'Renders the folder URL page if an existing item is requested' => [
            '/folder/url/home/items/foobar',
            200,
            'Foobar - Root with folder URLs',
            ['items' => 'foobar'],
            'root-with-folder-urls.local',
            false,
            true,
        ];

        yield 'Renders the folder URL page if auto items are enabled an existing item is requested' => [
            '/folder/url/home/foobar',
            200,
            'Foobar - Root with folder URLs',
            ['auto_item' => 'foobar', 'items' => 'foobar'],
            'root-with-folder-urls.local',
            true,
            true,
        ];

        yield 'Renders the 404 page if auto items are enabled and the folder URL contains the "auto_item" keyword' => [
            '/folder/url/home/auto_item/foo',
            404,
            '(404 Not Found)',
            [],
            'root-with-folder-urls.local',
            true,
            true,
        ];

        yield 'Renders the 404 page if auto items are enabled and the folder URL contains an auto item keyword' => [
            '/folder/url/home/items/foobar',
            404,
            '(404 Not Found)',
            [],
            'root-with-folder-urls.local',
            true,
            true,
        ];
    }

    /**
     * @dataProvider getRootAliases
     */
    public function testResolvesTheRootPage(string $request, int $statusCode, string $pageTitle, string $acceptLanguages, string $host): void
    {
        $_SERVER['REQUEST_URI'] = $request;
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguages;

        $client = $this->createClient([], $_SERVER);
        System::setContainer($client->getContainer());

        $crawler = $client->request('GET', $request);
        $title = trim($crawler->filterXPath('//head/title')->text());
        $response = $client->getResponse();

        $this->assertSame($statusCode, $response->getStatusCode());
        $this->assertStringContainsString($pageTitle, $title);
    }

    public function getRootAliases(): \Generator
    {
        yield'Renders the root page if one of the accept languages matches' => [
            '/',
            200,
            'Index - Root with index page',
            'en,de',
            'root-with-index.local',
        ];

        yield'Renders the fallback page if none of the accept languages matches' => [
            '/',
            200,
            'Index - Root with index page',
            'de,fr',
            'root-with-index.local',
        ];

        yield'Matches a root page without hostname' => [
            '/',
            200,
            'Home - Domain without hostname',
            'en',
            'domain-without-hostname.local',
        ];

        yield'Matches a hostname with port' => [
            '/',
            200,
            'Home - Domain with port',
            'en',
            'domain-with-port.local:8080',
        ];

        yield'Renders the 404 page if no language matches' => [
            '/',
            404,
            '(404 Not Found)',
            'de,fr',
            'root-without-fallback-language.local',
        ];

        yield'Redirects to the first language root if the accept languages matches' => [
            '/',
            303,
            'Redirecting to http://same-domain-root.local/english-site.html',
            'en',
            'same-domain-root.local',
        ];

        yield'Redirects to the second language root if the accept languages matches' => [
            '/',
            303,
            'Redirecting to http://same-domain-root.local/german-site.html',
            'de',
            'same-domain-root.local',
        ];

        yield'Redirects to the fallback root if none of the accept languages matches' => [
            '/',
            303,
            'Redirecting to http://same-domain-root.local/english-site.html',
            'fr',
            'same-domain-root.local',
        ];
    }

    /**
     * @dataProvider getRootAliasesWithLocale
     */
    public function testResolvesTheRootPageWithLocale(string $request, int $statusCode, string $pageTitle, string $acceptLanguages, string $host): void
    {
        Config::set('addLanguageToUrl', true);

        $_SERVER['REQUEST_URI'] = $request;
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguages;

        $client = $this->createClient(['environment' => 'locale'], $_SERVER);
        System::setContainer($client->getContainer());

        $crawler = $client->request('GET', $request);
        $title = trim($crawler->filterXPath('//head/title')->text());
        $response = $client->getResponse();

        $this->assertSame($statusCode, $response->getStatusCode());
        $this->assertStringContainsString($pageTitle, $title);
    }

    public function getRootAliasesWithLocale(): \Generator
    {
        yield 'Redirects to the language root if one of the accept languages matches' => [
            '/',
            302,
            'Redirecting to http://same-domain-root.local/de/',
            'de,en',
            'same-domain-root.local',
        ];

        yield 'Redirects to the language fallback if one of the accept languages matches' => [
            '/',
            302,
            'Redirecting to http://same-domain-root.local/en/',
            'en,de',
            'same-domain-root.local',
        ];

        yield 'Redirects to the language fallback if none of the accept languages matches' => [
            '/',
            302,
            'Redirecting to http://same-domain-root.local/en/',
            'fr,es',
            'same-domain-root.local',
        ];

        yield 'Redirects to "de" if "de-CH" is accepted and "de" is not' => [
            '/',
            302,
            'Redirecting to http://same-domain-root.local/de/',
            'de-CH',
            'same-domain-root.local',
        ];

        yield 'Ignores the case of the language code' => [
            '/',
            302,
            'Redirecting to http://same-domain-root.local/de/',
            'dE-at',
            'same-domain-root.local',
        ];

        yield 'Redirects to "en" if "de-CH" and "en" are accepted and "de" is not' => [
            '/',
            302,
            'Redirecting to http://same-domain-root.local/en/',
            'de-CH,en',
            'same-domain-root.local',
        ];

        yield 'Renders the 404 page if none of the accept languages matches' => [
            '/',
            404,
            '(404 Not Found)',
            'de,fr',
            'root-without-fallback-language.local',
        ];

        yield 'Renders the root page if the locale matches' => [
            '/en/',
            200,
            'Index - Root with index page',
            'en,de',
            'root-with-index.local',
        ];

        yield 'Renders the first language root if the locale matches' => [
            '/en/',
            200,
            'English site - Same domain root English with index',
            'en,de',
            'same-domain-root-with-index.local',
        ];

        yield 'Renders the second language root if the locale matches' => [
            '/de/',
            200,
            'German site - Same domain root German with index',
            'de,en',
            'same-domain-root-with-index.local',
        ];

        yield 'Renders the second language root if the locale matches regardless of accept language' => [
            '/de/',
            200,
            'German site - Same domain root German with index',
            'fr',
            'same-domain-root-with-index.local',
        ];

        yield 'Renders the 404 page if the locale does not match' => [
            '/de/',
            404,
            '(404 Not Found)',
            'de,fr',
            'root-with-index.local',
        ];

        yield 'Renders the 404 page if the locale does not exist' => [
            '/fr/',
            404,
            '(404 Not Found)',
            'de,fr',
            'root-without-fallback-language.local',
        ];
    }

    public function testOrdersThePageModelsByCandidates(): void
    {
        Config::set('folderUrl', true);

        $_SERVER['REQUEST_URI'] = '/main/sub-zh.html';
        $_SERVER['HTTP_HOST'] = 'root-zh.local';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

        $client = $this->createClient([], $_SERVER);
        System::setContainer($client->getContainer());

        $crawler = $client->request('GET', '/main/sub-zh.html');
        $title = trim($crawler->filterXPath('//head/title')->text());
        $response = $client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('', $title);
    }

    public function testCorrectPageForUnknownLanguage(): void
    {
        static::loadFileIntoDatabase(__DIR__.'/app/Resources/issue-2465.sql');

        Config::set('folderUrl', true);
        Config::set('addLanguageToUrl', true);

        $request = 'https://domain1.local/it/';

        $_SERVER['REQUEST_URI'] = $request;
        $_SERVER['HTTP_HOST'] = 'domain1.local';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de,en';

        $client = $this->createClient(['environment' => 'locale'], $_SERVER);
        System::setContainer($client->getContainer());

        $crawler = $client->request('GET', $request);
        $title = trim($crawler->filterXPath('//head/title')->text());
        $response = $client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Domain1', $title);
    }
}
