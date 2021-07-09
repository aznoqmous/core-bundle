<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Twig\Extension;

use Contao\BackendCustom;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Twig\Runtime\SchemaOrgRuntime;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ContaoTemplateExtension extends AbstractExtension
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var ContaoFramework
     */
    private $framework;

    /**
     * @var ScopeMatcher
     */
    private $scopeMatcher;

    /**
     * @internal Do not inherit from this class; decorate the "contao.twig.template_extension" service instead
     */
    public function __construct(RequestStack $requestStack, ContaoFramework $framework, ScopeMatcher $scopeMatcher)
    {
        $this->requestStack = $requestStack;
        $this->framework = $framework;
        $this->scopeMatcher = $scopeMatcher;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('render_contao_backend_template', [$this, 'renderContaoBackendTemplate']),
            new TwigFunction('add_schema_org', [SchemaOrgRuntime::class, 'add']),
        ];
    }

    /**
     * Renders a Contao back end template with the given blocks.
     */
    public function renderContaoBackendTemplate(array $blocks = []): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$this->scopeMatcher->isBackendRequest($request)) {
            return '';
        }

        /** @var BackendCustom $controller */
        $controller = $this->framework->createInstance(BackendCustom::class);
        $template = $controller->getTemplateObject();

        foreach ($blocks as $key => $content) {
            $template->{$key} = $content;
        }

        $response = $controller->run();

        return $response->getContent();
    }
}
