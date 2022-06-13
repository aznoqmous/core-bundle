<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Filesystem\FilesystemItem;
use Contao\CoreBundle\Filesystem\FilesystemUtil;
use Contao\CoreBundle\Filesystem\SortMode;
use Contao\CoreBundle\Filesystem\VirtualFilesystem;
use Contao\CoreBundle\Image\Studio\Figure;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\ServiceAnnotation\ContentElement;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FrontendUser;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Security;

/**
 * @ContentElement("image", category="media")
 * @ContentElement("gallery", category="media")
 */
class ImagesController extends AbstractContentElementController
{
    public function __construct(
        private readonly Security $security,
        private readonly VirtualFilesystem $filesStorage,
        private readonly Studio $studio,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        // Find filesystem items
        $filesystemItems = FilesystemUtil::listContentsFromSerialized($this->filesStorage, $this->getSources($model));

        // Sort elements; relay to client-side logic if list should be randomized
        if (null !== ($sortMode = SortMode::tryFrom($sortBy = $model->sortBy))) {
            $filesystemItems = $filesystemItems->sort($sortMode);
        }

        $template->set('sort_mode', $sortMode);
        $template->set('randomize_order', $randomize = ('random' === $sortBy));

        // Limit elements; use client-side logic for only displaying the first
        // $limit elements in case we are dealing with a random order
        if (($limit = (int) $model->numberOfItems) > 0 && !$randomize) {
            $filesystemItems = $filesystemItems->limit($limit);
        }

        $template->set('limit', $limit > 0 && $randomize ? $limit : null);

        // Compile list of images
        $figureBuilder = $this->studio
            ->createFigureBuilder()
            ->setSize($model->size)
            ->setLightboxGroupIdentifier('lb'.$model->id)
            ->enableLightbox((bool) $model->fullsize)
        ;

        if ('image' === $model->type) {
            $figureBuilder->setMetadata($model->getOverwriteMetadata());
        }

        $imageList = array_map(
            fn (FilesystemItem $filesystemItem): Figure => $figureBuilder
                // TODO: As soon as our image libraries support this case, read from the public path instead.
                ->fromPath(Path::join($this->filesStorage->getPrefix(), $filesystemItem->getPath()))
                ->build(),
            iterator_to_array($filesystemItems)
        );

        $template->set('images', $imageList);
        $template->set('items_per_page', (int) $model->perPage ?: null);
        $template->set('items_per_row', (int) $model->perRow ?: null);

        return $template->getResponse();
    }

    /**
     * @return string|array<string>
     */
    private function getSources(ContentModel $model): string|array
    {
        // Depending on the selected mode, we read from tl_content.singleSRC,
        // tl_content.multiSRC or the user's home directory (tl_user.homeDir)
        return match (true) {
            'image' === $model->type => [$model->singleSRC],
            $model->useHomeDir && ($user = $this->security->getUser()) instanceof FrontendUser && $user->assignDir && ($homeDir = $user->homeDir) => $homeDir,
            default => $model->multiSRC,
        };
    }
}
