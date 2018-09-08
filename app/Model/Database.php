<?php
/**
 * webtrees: online genealogy
 * Copyright (C) 2018 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTASET NAMES 'utf8' COLLATE 'utf8_unicode_ci'LITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace Fisharebest\Webtrees\Model;

use Fisharebest\Webtrees\I18N;
use Illuminate\Database\Capsule\Manager as Capsule;

class Database
{
    public function __construct(array $config)
    {
        $capsule = new Capsule();

        // Default connection using "utf8_unicode_ci" collation
        $capsule->addConnection(
            [
                'driver'    => 'mysql',
                'host'      => $config['dbhost'],
                'port'      => $config['dbport'],
                'database'  => $config['dbname'],
                'username'  => $config['dbuser'],
                'password'  => $config['dbpass'],
                'charset'   => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix'    => $config['tblpfx'],
            ]
        );

        // Special connection respecting locale collation
        $capsule->addConnection(
            [
                'driver'    => 'mysql',
                'host'      => $config['dbhost'],
                'port'      => $config['dbport'],
                'database'  => $config['dbname'],
                'username'  => $config['dbuser'],
                'password'  => $config['dbpass'],
                'charset'   => 'utf8',
                'collation' => I18N::collation(),
                'prefix'    => $config['tblpfx'],
            ],
            I18N::collation()
        );

        // Setup the Eloquent ORM…
        $capsule->bootEloquent();
    }
}
