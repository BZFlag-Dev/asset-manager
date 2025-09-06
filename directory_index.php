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

use App\Controller\DirectoryIndexController;
use DI\Bridge\Slim\Bridge;
use League\Config\Configuration;
use Monolog\Logger;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/vendor/autoload.php';

$builder = new \DI\ContainerBuilder();
$builder->addDefinitions(dirname(__FILE__).'/src/di-config.php');
$container = $builder->build();

// Create our application
$app = Bridge::create($container);

// Grab a pointer to the configuration
$config = $app->getContainer()->get(Configuration::class);

// Add middleware
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

// Set up error handling
$errorMiddleware = $app->addErrorMiddleware(
  $config->get('debug'),
  true,
  true,
  $app->getContainer()->get(Logger::class)
);

// Index page generation for the asset directories
$app->get('{path:.*}', [DirectoryIndexController::class, 'generate'])->setName('directory_index');

// Let's go!
$app->run();
