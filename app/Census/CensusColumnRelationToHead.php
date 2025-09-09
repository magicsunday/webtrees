<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2025 webtrees development team
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

namespace Fisharebest\Webtrees\Census;

use Override;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\RelationshipService;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Relationship to head of household.
 */
class CensusColumnRelationToHead extends AbstractCensusColumn implements CensusColumnInterface
{
    protected const string HEAD_OF_HOUSEHOLD = '-';

    /**
     * Generate the likely value of this census column, based on available information.
     *
     * @param Individual $individual
     * @param Individual $head
     *
     * @return string
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[Override]
    public function generate(Individual $individual, Individual $head): string
    {
        if ($individual === $head) {
            return static::HEAD_OF_HOUSEHOLD;
        }

        return Registry::container()
            ->get(RelationshipService::class)
            ->getCloseRelationshipName($head, $individual);
    }
}
