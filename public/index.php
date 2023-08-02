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

use App\Controller\AssetController;
use App\Controller\ManagementController;
use App\Database\DatabaseInterface;
use DI\Bridge\Slim\Bridge;
use League\Config\Configuration;
use Nette\Schema\Expect;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_trans_sid' => false
]);

$container = new \DI\Container();

$container->set(Configuration::class, function () {
  // Define config schema
  $config = new Configuration([
    'database' => Expect::structure([
      'driver' => Expect::anyOf('sqlite3')->default('sqlite3'),
      'path' => Expect::string(dirname(__DIR__).'/var/data/db.sqlite3')
    ]),
    'site' => Expect::structure([
        // Site title (used for the page header and the page title)
        'title' => Expect::string("Asset Manager"),
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
        'max_width' => Expect::int(256),
        'max_height' => Expect::int(256)
      ])
    ]),
    'email' => Expect::structure([
        // Emails are sent from this address
        'from_address' => Expect::email()->required(),
        // When a new asset is uploaded for moderation, all emails here will
        // be notified.
        'notify_addresses' => Expect::listOf(Expect::email())->required()
    ]),
    'auth' => Expect::structure([
        // The URL to the BZFlag list server
        'list_url' => Expect::string('https://my.bzflag.org/db/'),
        // An uppercase group name in the format of ORG.GROUP
        'admin_group' => Expect::string()->required(),
        // This should only be set to false for local test/dev environments
        'check_ip' => Expect::bool(true)
    ]),
    'debug' => Expect::bool(false)
  ]);

  // Merge our configuration file information
  $config->merge(require __DIR__ . '/../config.php');

  return $config;
});

$container->set(DatabaseInterface::class, function (Configuration $config) {
  $driver = $config->get('database.driver');
  if ($driver == 'sqlite3') {
    return new \App\Database\SQLite3($config);
  }

  return null;
});

$container->set(Twig::class, function (Configuration $config) {
  $twig = Twig::create(__DIR__.'/../views', [
      'cache' => __DIR__.'/../var/cache/twig',
      'auto_reload' => true
  ]);

  //var_dump($_SESSION); exit;
  if (!empty($_SESSION['username'])) {
    $twig->offsetSet('username', $_SESSION['username']);
    $twig->offsetSet('bzid', $_SESSION['bzid']);
    $twig->offsetSet('is_admin', $_SESSION['is_admin']);
  }

  $twig->offsetSet('site_title', $config->get('site.title'));

  return $twig;
});

// Create our application
$app = Bridge::create($container);

// Grab a pointer to the configuration
$config = $app->getContainer()->get(Configuration::class);

// Set our base path
$app->setBasePath($config->get('site.base_path'));

// Add middleware
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

// Set up error handling
// TODO: Logging errors to a file
$errorMiddleware = $app->addErrorMiddleware($config->get('debug'), true, true);

// Define routes
$app->get('/', [ManagementController::class, 'home'])->setName('home');
$app->get('/login[/{token}/{username}]', [ManagementController::class, 'login'])->setName('login');
$app->get('/logout', [ManagementController::class, 'logout'])->setName('logout');
$app->get('/terms', [ManagementController::class, 'terms'])->setName('terms');
$app->get('/upload', [ManagementController::class, 'upload'])->setName('upload');

$app->get('/view/{bzid}/{queueid}[/{width}/{height}]', [AssetController::class, 'view'])->setName('view');

// Let's go!
$app->run();
