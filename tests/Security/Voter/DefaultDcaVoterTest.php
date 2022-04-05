<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Security\Voter;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\Voter\DataContainer\DefaultDcaVoter;
use Contao\CoreBundle\Tests\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class DefaultDcaVoterTest extends TestCase
{
    public function testVoter(): void
    {
        $voter = new DefaultDcaVoter();

        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->createMock(TokenInterface::class), 'foobar', ['foobar'])
        );

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->createMock(TokenInterface::class), 'foobar', [ContaoCorePermissions::DCA_PREFIX.'foobar'])
        );
    }
}
