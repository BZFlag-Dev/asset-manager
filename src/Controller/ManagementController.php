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
use Respect\Validation\Validator as v;
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

  public function upload(App $app, ServerRequestInterface $request, ResponseInterface $response, Twig $twig, Configuration $config, SpdxLicenses $spdx): ResponseInterface
  {
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

    // Fetch the upload configuration options and clamp values to the PHP maximums
    $upload_config = $config->get('asset.upload');
    $upload_config['max_file_size'] = min($upload_config['max_file_size'], $convertToBytes(ini_get('upload_max_filesize')));
    $upload_config['max_file_count'] = min($upload_config['max_file_count'], ini_get('max_file_uploads'));

    if ($request->getMethod() == 'GET') {
      if (!isset($_SESSION['username'])) {
        return $response
          ->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('home'))
          ->withStatus(302);
      }

      // TODO: Check if we need to factor in a buffer to contain files AND other form data within this max post size
      $upload_config['max_post_size'] = min($upload_config['max_file_size'] * $upload_config['max_file_count'], $convertToBytes(ini_get('post_max_size')));
      // TODO: Adjust this based on the allowed types
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
      $writeErrors = function (ResponseInterface $response, array $errors) {
        $response->getBody()->write(json_encode([
          'success' => false,
          'errors' => $errors
        ]));
        return $response
          ->withHeader('Content-Type', 'application/json');
      };

      // If we don't have a valid session, throw an error back
      if (!isset($_SESSION['username'])) {
        return $writeErrors($response, [
          'User is not logged in or session has expired. Please log in and try again.'
        ]);
      }

      // Grab a copy of the form data
      $data = $request->getParsedBody();

      // Check that we have the 'success' hidden field. If we don't have this, it can indicate that a PHP upload
      // limit has been exceeded.
      if (!isset($data['success']) || $data['success'] !== '1') {
        return $writeErrors($response, [
          'The form submission was invalid. An upload limit may have been exceeded.'
        ]);
      }

      // Let's check the uploaded files
      $files = $request->getUploadedFiles();

      // Verify we have at least one file that was uploaded
      if (!isset($files['assets']) || sizeof($files['assets']) < 1) {
        return $writeErrors($response, [
          'No files were uploaded'
        ]);
      }

      // Start tracking errors
      $errors = [];

      foreach($files['assets'] as $index => $upload) {
        $filename = $upload['file']->getClientFilename();

        // Check the error status first
        $error = $upload['file']->getError();
        if ($error != UPLOAD_ERR_OK) {
          $errors['file'][$index][] = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeded the maximum size',
            UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE => 'The file was not uploaded completely',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The file could not be written due to a server error',
            default => 'The upload failed for an unknown reason',
          };

          continue;
        }

        // Check the mime type and file extension
        $tmp_path = $upload['file']->getStream()->getMetadata('uri');
        $mime_type = mime_content_type($tmp_path);
        $ext_start = strrpos($filename, '.');
        // Verify we have an extension
        if ($ext_start === false || strlen($filename) === $ext_start + 1) {
          $errors['file'][$index][] = 'The file extension is missing';
          continue;
        }
        $extension = substr($filename, $ext_start+1);

        // TODO: Add a configuration array or database tables to store valid options
        if ($mime_type !== 'image/png' || $extension !== 'png') {
          $errors['file'][$index][] = 'Invalid MIME type or file extension';
          continue;
        }

        // Verify the file size does not exceed the limit
        $size = $upload['file']->getSize();
        if ($size > min($config->get('asset.upload.max_file_size'), $convertToBytes(ini_get('upload_max_filesize')))) {
          $errors['file'][$index][] = 'Maximum file size exceeded';
          continue;
        }

        // Verify the other form data exists for this file
        if (!isset($data['assets'][$index]) || !is_array($data['assets'][$index])) {
          $errors['file'][$index][] = 'Asset information is missing';
          continue;
        }

        // Grab a short reference to this asset's information
        $d = &$data['assets'][$index];

        // Require an author name
        if (!v::notEmpty()->validate($d['author'])) {
          $errors['file'][$index][] = 'Missing author name';
        }

        // Verify the source URL, if provided, is actually a URL
        if (!v::optional(v::url())->validate($d['source_url'])) {
          $errors['file'][$index][] = 'Source URL was not a valid URL';
        }

        // Require a license
        if (!v::notEmpty()->validate($d['license'])) {
          $errors['file'][$index][] = 'Missing license';
        }
        // If we allow other licenses, check if they provided the name, and either the URL or text
        else if ($d['license'] === 'Other') {
          if (!$upload_config['licenses']['allow_other']) {
            $errors['file'][$index][] = 'Other licenses are not allowed';
          }
          // Verify that the license name is provided
          if (!v::notEmpty()->validate($d['license_name'])) {
            $errors['file'][$index][] = 'Missing license name';
          }

          // Verify either the license URL or text are provided
          if (!(v::optional(v::url()))->validate($d['license_url'])) {
            $errors['file'][$index][] = 'License URL was not a valid URL';
          }
          else if (!(v::notEmpty()->validate($d['license_url']) || v::notEmpty()->validate($d['license_text']))) {
            $errors['file'][$index][] = 'Missing license URL or text';
          }
        }
        else {
          // Check if it's a popular or common license, or, if enabled, another OSI-approved license
          if (
            !in_array($d['license'], $upload_config['licenses']['popular']) &&
            !in_array($d['license'], $upload_config['licenses']['common']) &&
            !($upload_config['licenses']['allow_other_osi'] && $spdx->validate($d['license']) && $spdx->isOsiApprovedByIdentifier($d['license']))
          ) {
            $errors['file'][$index][] = 'Invalid license selected';
          }
        }
      }

      // If we had any errors, return them to the user
      if (sizeof($errors) > 0) {
        return $writeErrors($response, $errors);
      }

      // TODO: Actually process the files/data and put them into the queue
      sleep(2);

      $response->getBody()->write(json_encode([
        'success' => true,
      ]));
      return $response
        ->withHeader('Content-Type', 'application/json');
    }
  }
}
