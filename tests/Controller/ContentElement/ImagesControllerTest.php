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

use Contao\CoreBundle\Controller\ContentElement\ImagesController;
use Contao\StringUtil;
use Symfony\Component\Security\Core\Security;

class ImagesControllerTest extends ContentElementTestCase
{
    public function testOutputsSingleImage(): void
    {
        $security = $this->createMock(Security::class);

        $response = $this->renderWithModelData(
            new ImagesController($security, $this->getDefaultStorage(), $this->getDefaultStudio()),
            [
                'type' => 'image',
                'singleSRC' => StringUtil::uuidToBin(ContentElementTestCase::FILE_IMAGE1),
                'sortBy' => '',
                'numberOfItems' => '0',
                'size' => '',
                'fullsize' => '1',
                'perPage' => '4',
                'perRow' => '2',
                'overwriteMeta' => '1',
                'alt' => 'alt text',
            ],
        );

        $expectedOutput = <<<'HTML'
            <div class="content_element/image">
                <figure>
                    <img src="files/image1.jpg" alt="alt text">
                </figure>
            </div>
            HTML;

        $this->assertSameHtml($expectedOutput, $response->getContent());
    }

    public function testOutputsGallery(): void
    {
        $security = $this->createMock(Security::class);

        $response = $this->renderWithModelData(
            new ImagesController($security, $this->getDefaultStorage(), $this->getDefaultStudio()),
            [
                'type' => 'gallery',
                'multiSRC' => serialize([
                    StringUtil::uuidToBin(ContentElementTestCase::FILE_IMAGE1),
                    StringUtil::uuidToBin(ContentElementTestCase::FILE_IMAGE2),
                    StringUtil::uuidToBin(ContentElementTestCase::FILE_IMAGE3),
                ]),
                'sortBy' => 'name_desc',
                'numberOfItems' => '2',
                'size' => '',
                'fullsize' => '1',
                'perPage' => '4',
                'perRow' => '2',
            ],
        );

        $expectedOutput = <<<'HTML'
            <div class="content_element/gallery">
                <ul>
                    <li>
                        <figure>
                            <img src="files/image3.jpg" alt>
                        </figure>
                    </li>
                    <li>
                        <figure>
                            <img src="files/image2.jpg" alt>
                        </figure>
                    </li>
                </ul>
            </div>
            HTML;

        $this->assertSameHtml($expectedOutput, $response->getContent());
    }
}
