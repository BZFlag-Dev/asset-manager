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

namespace App\Controller;

use App\Database\DatabaseInterface;
use Composer\Spdx\SpdxLicenses;
use League\Config\Configuration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Views\Twig;

class ManagementController
{
  public function home(ServerRequestInterface $request, ResponseInterface $response, Twig $twig, DatabaseInterface $db): ResponseInterface
  {
    $queue = null;
    if (!empty($_SESSION['bzid'])) {
      $queue = $db->queue_get_by_bzid($_SESSION['bzid']);
    }

    return $twig->render($response, 'home.html.twig', [
      'pending' => $queue
    ]);
  }

  public function login(App $app, ServerRequestInterface $request, ResponseInterface $response, Twig $twig, Configuration $config, $token = '', $username = ''): ResponseInterface
  {
    if (empty($token) || empty($username)) {
      return $response
          ->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('home'))
          ->withStatus(302);
    }

    // Check the token
    $checktokens = urlencode($username);
    if ($config->get('auth.check_ip')) {
      $checktokens .= '@'.$_SERVER['REMOTE_ADDR'];
    }
    $checktokens .= '='.urlencode($token);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config->get('auth.list_url'),
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'action' => 'CHECKTOKENS',
            'checktokens' => $checktokens,
            'groups' => implode("\r\n", [
                $config->get('auth.admin_group')
            ])
        ])
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    // Check if we have both a TOKGOOD and a BZID
    $foundTOKGOOD = $foundBZID = false;
    if ($result !== false) {
      $result = explode("\n", str_replace(["\r\n", "\r"], "\n", $result));

      foreach($result as $line) {
        if (str_starts_with($line, 'TOKGOOD: ')) {
          $foundTOKGOOD = true;
          $_SESSION['is_admin'] = (
            ($pos = strpos($line, ':', 8)) !== false &&
              in_array($config->get('auth.admin_group'), explode(':', substr($line, $pos+1)), true)
          );
        } elseif (str_starts_with($line, 'BZID: ')) {
          $foundBZID = true;
          list($_SESSION['bzid'], $_SESSION['username']) = explode(' ', substr($line, 6), 2);
        }
      }
    }

    // If there was no response from the server list, or we had a problem verifying the token,
    // clear the session and show an error.
    if ($result === false || !$foundBZID || !$foundTOKGOOD) {
      $_SESSION = [];
      // TODO: Logging this error
      return $twig->render($response, 'error.html.twig', [
          'message' => 'There was an error verifying your login.'
      ]);
    }

    return $response
        ->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('home'))
        ->withStatus(302);
  }

  public function logout(App $app, ServerRequestInterface $request, ResponseInterface $response, Twig $twig): ResponseInterface
  {
    // Clear the session and return to the homepage
    $_SESSION = [];
    return $response
        ->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('home'))
        ->withStatus(302);
  }

  public function terms(ServerRequestInterface $request, ResponseInterface $response, Twig $twig, Configuration $config): ResponseInterface
  {
    return $twig->render($response, 'terms.html.twig', [
        'takedown_address' => $config->get('site.takedown_address')
    ]);
  }

  public function upload(ServerRequestInterface $request, ResponseInterface $response, Twig $twig, Configuration $config): ResponseInterface
  {
    // License information
    $spdx = new SpdxLicenses();

    if ($request->getMethod() == 'GET') {
      $convertToBytes = function ($size) {
        if (str_ends_with($size, 'G')) {
          return (int)$size * 1024 * 1024 * 1024;
        } elseif (str_ends_with($size, 'M')) {
          return (int)$size * 1024 * 1024;
        } elseif (str_ends_with($size, 'K')) {
          return (int)$size * 1024;
        } else {
          return (int)$size;
        }
      };

      $upload_config = $config->get('asset.upload');
      $upload_config['max_file_size'] = min($upload_config['max_file_size'], $convertToBytes(ini_get('upload_max_filesize')));
      $upload_config['max_file_count'] = min($upload_config['max_file_count'], ini_get('max_file_uploads'));
      // TODO: Check if we need to factor in a buffer to contain files AND other form data within this max post size
      $upload_config['max_post_size'] = min($upload_config['max_file_size'] * $upload_config['max_file_count'], $convertToBytes(ini_get('post_max_size')));
      $upload_config['accept'] = ".png";

      $licenses = [
        'popular' => array_fill_keys($upload_config['licenses']['popular'], ''),
        'common' => array_fill_keys($upload_config['licenses']['common'], ''),
        'other' => []
      ];

      foreach($spdx->getLicenses() as $license) {
        // If this license id is deprecated, skip it
        if ($license[3] === true) {
          continue;
        }

        // If it's a popular or common license, fill in the name
        if (array_key_exists($license[0], $licenses['popular'])) {
          $licenses['popular'][$license[0]] = $license[1];
        } elseif (array_key_exists($license[0], $licenses['common'])) {
          $licenses['common'][$license[0]] = $license[1];
        }
        // Otherwise, if it's an OSI-approved license, put it under Other
        elseif ($upload_config['licenses']['allow_other_osi'] && $license[2] === true) {
          $licenses['other'][$license[0]] = $license[1];
        }
      }

      return $twig->render($response, 'upload.html.twig', [
        'upload_config' => $upload_config,
        'licenses' => $licenses
      ]);
    } elseif ($request->getMethod() == 'POST') {
      // TODO: Actually process the files/data
      sleep(2);
      $response->getBody()->write(json_encode([
        'success' => false
      ]));
      return $response
        ->withHeader('Content-Type', 'application/json');
    }
  }
}
