<?php

declare(strict_types=1);

/*
 * BZFlag Asset Manager: Tool to upload and moderate map assets for BZFlag.
 * Copyright (C) 2023  BZFlag & Associates
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

use App\Database\DatabaseInterface;
use DI\Bridge\Slim\Bridge;
use League\Config\Configuration;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nette\Schema\Expect;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

$container = new \DI\Container();

$container->set(Configuration::class, function () {
  // Define config schema
  $config = new Configuration([
    'database' => Expect::structure([
      'path' => Expect::string(dirname(__DIR__).'/var/data/db.sqlite3')
    ]),
    'site' => Expect::structure([
      // The name of the game that these assets are being hosted for
      'game_name' => Expect::string('BZFlag'),
      // Site title (used for the page header and the page title)
      'title' => Expect::string('Asset Manager'),
      // Base URL of the site, used for generating links to the final asset locations
      'base_url' => Expect::string()->required(),
      // Base path
      'base_path' => Expect::string('/manage'),
      // Content takedown requests
      'takedown_address' => Expect::email()->required()
    ]),
    // Paths should NOT end with a trailing slash
    'path' => Expect::structure([
      // Uploaded files are stored here before approval
      'upload' => Expect::string(dirname(__DIR__).'/var/upload'),
      // Approved files are moved here
      'files' => Expect::string()->required()
    ]),
    'asset' => Expect::structure([
      'image' => Expect::structure([
        'max_width' => Expect::int(4096),
        'max_height' => Expect::int(4096)
      ]),
      'upload' => Expect::structure([
        'max_file_size' => Expect::int(2 * 1024 * 1024)->min(512 * 1024),
        'max_file_count' => Expect::int(8)->min(1)->max(20),
        'licenses' => Expect::structure([
          // A short list of popular licenses
          'popular' => Expect::listOf(Expect::string())
            ->default(['CC-BY-4.0', 'CC-BY-SA-4.0', 'CC-BY-3.0', 'CC-BY-SA-3.0', 'CC0-1.0', 'LGPL-2.1-only', 'MPL-2.0', 'MIT'])
            ->mergeDefaults(false),
          // A list of less popular asset licenses, but still popular in open-source
          'common' => Expect::listOf(Expect::string())
            ->default(['GPL-2.0-only', 'GPL-2.0-or-later', 'GPL-3.0-only', 'GPL-3.0-or-later', 'LGPL-2.0-only', 'LGPL-2.0-or-later',
              'LGPL-2.1-or-later', 'LGPL-3.0-only', 'LGPL-3.0-or-later', 'MPL-1.0', 'MPL-1.1', 'BSD-3-Clause',
              'BSD-2-Clause', 'AGPL-3.0-only', 'AGPL-3.0-or-later'])
            ->mergeDefaults(false),
          // Allow specifying an unlisted license by providing the name, and the URL or text
          'allow_other' => Expect::bool(true),
          // Allow showing all other OSI-approved licenses that weren't in the 'popular' or 'common' sections above
          'allow_other_osi' => Expect::bool(false)
        ]),
        'types' => Expect::arrayOf(
          Expect::anyOf(Expect::string(), Expect::listOf(Expect::string())),
          Expect::string()
        )->required()
      ])
    ]),
    'email' => Expect::structure([
      // Emails are sent from this address
      'from_address' => Expect::email()->required(),
      // When a new asset is uploaded for moderation, all emails here will
      // be notified.
      'notify_addresses' => Expect::listOf(Expect::email())->required(),

      'smtp' => Expect::structure([
        'host' => Expect::string('localhost'),
        'port' => Expect::int(25)->min(1)->max(65535),
        'username' => Expect::string(''),
        'password' => Expect::string(''),
        'encryption' => Expect::anyOf('null', 'ssl', 'tls')->default('null')
      ])
    ]),
    'auth' => Expect::structure([
      // The URL to the BZFlag list server
      'list_url' => Expect::string('https://my.bzflag.org/db/'),
      // An uppercase group name in the format of ORG.GROUP
      'admin_group' => Expect::string()->required(),
      // This should only be set to false for local test/dev environments
      'check_ip' => Expect::bool(true)
    ]),
    // Display debug messages in the browser? Disable for production site.
    'debug' => Expect::bool(false),
    'logging' => Expect::structure([
      // Absolute path to the configuration file
      'path' => Expect::string(dirname(__DIR__).'/var/log/app.log'),
      // Log level (see https://seldaek.github.io/monolog/doc/01-usage.html)
      'level' => Expect::int(250)->min(100)->max(600)
    ])
  ]);

  // Merge our configuration file information
  $config->merge(require dirname(__DIR__) . '/config.php');

  return $config;
});

$container->set(DatabaseInterface::class, function (Configuration $config) {
  return new \App\Database\SQLite3($config);
});

$container->set(Twig::class, function (Configuration $config) {
  $twig = Twig::create(dirname(__DIR__).'/views', [
    'cache' => dirname(__DIR__).'/var/cache/twig',
    'auto_reload' => true
  ]);

  if (!empty($_SESSION['username'])) {
    $twig->offsetSet('username', $_SESSION['username']);
    $twig->offsetSet('bzid', $_SESSION['bzid']);
    $twig->offsetSet('is_admin', $_SESSION['is_admin']);
  }

  $twig->offsetSet('game_name', $config->get('site.game_name'));
  $twig->offsetSet('site_title', $config->get('site.title'));

  return $twig;
});

$container->set(Logger::class, function (Configuration $config): Logger {
  $c = $config->get('logging');
  $logger = new Logger('app');
  $logger->pushHandler(new StreamHandler($c['path'], $c['level']));
  return $logger;
});

// Create our application
$app = Bridge::create($container);

// Grab a pointer to the configuration
$config = $app->getContainer()->get(Configuration::class);

// Set our base path if we're in the manager
if (defined('IN_MANAGER')) {
  $app->setBasePath($config->get('site.base_path'));
}

// Add middleware
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

// Set up error handling
$errorMiddleware = $app->addErrorMiddleware(
  $config->get('debug'),
  true,
  true,
  $app->getContainer()->get(Logger::class)
);
