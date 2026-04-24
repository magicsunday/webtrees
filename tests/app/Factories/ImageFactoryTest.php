<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2026 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Fisharebest\Webtrees\Factories;

use Fisharebest\Webtrees\Services\PhpService;
use Fisharebest\Webtrees\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ImageFactory::class)]
class ImageFactoryTest extends TestCase
{
    public function testReplacementImageResponseSetsContentSecurityPolicyHeader(): void
    {
        $php_service   = $this->createStub(PhpService::class);
        $image_factory = new ImageFactory($php_service);
        $response      = $image_factory->replacementImageResponse('404');

        self::assertSame('image/svg+xml', $response->getHeaderLine('content-type'));
        self::assertSame(
            'default-src none',
            $response->getHeaderLine('content-security-policy'),
        );
    }
}
