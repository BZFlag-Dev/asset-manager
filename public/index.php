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
use League\Config\Configuration;
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/../vendor/autoload.php';

$session_save_path = ini_get('session.save_path').DIRECTORY_SEPARATOR.'asset-manager';
if (!is_dir($session_save_path))
  mkdir($session_save_path);
session_start([
  'save_path' => $session_save_path,
  'gc_maxlifetime' => 60 * 60 * 2,
  'cookie_httponly' => true,
  'cookie_samesite' => 'Strict',
  'use_trans_sid' => false
]);

require __DIR__ . '/../src/common_bootstrap.php';
global $container;
global $app;

$container->set(PHPMailer::class, function (Configuration $config) {
  $smtp = $config->get('email.smtp');

  $mailer = new PHPMailer(true);
  $mailer->isSMTP();
  $mailer->SMTPKeepAlive = true;
  $mailer->SMTPSecure = match($smtp['encryption']) {
    'ssl' => PHPMailer::ENCRYPTION_SMTPS,
    'tls' => PHPMailer::ENCRYPTION_STARTTLS,
    default => ''
  };
  $mailer->Host = $smtp['host'];
  $mailer->Port = $smtp['port'];
  $mailer->XMailer = null;
  $mailer->setFrom($config->get('email.from_address'), "{$config->get('site.game_name')} {$config->get('site.title')}");
  $mailer->WordWrap = 80;

  // If we have a username/password, use authentication
  if (!empty($smtp['username']) && !empty($smtp['password'])) {
    $mailer->SMTPAuth = true;
    $mailer->Username = $smtp['username'];
    $mailer->Password = $smtp['password'];
  }

  return $mailer;
});

// Grab a pointer to the configuration
$config = $app->getContainer()->get(Configuration::class);

// Set our base path
$app->setBasePath($config->get('site.base_path'));

// Management routes
$app->get('/', [ManagementController::class, 'home'])->setName('home');
$app->get('/login[/{token}/{username}]', [ManagementController::class, 'login'])->setName('login');
$app->get('/logout', [ManagementController::class, 'logout'])->setName('logout');
$app->get('/terms', [ManagementController::class, 'terms'])->setName('terms');
$app->get('/upload', [ManagementController::class, 'upload'])->setName('upload');
$app->post('/upload', [ManagementController::class, 'upload_process']);
$app->get('/queue', [ManagementController::class, 'queue'])->setName('queue');
$app->post('/queue', [ManagementController::class, 'queue_process']);
$app->get('/view/{bzid}/{queueid}[/{width}/{height}]', [AssetController::class, 'view'])->setName('view');

// Let's go!
$app->run();
