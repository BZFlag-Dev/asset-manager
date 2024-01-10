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

use App\Attribute\AuthRequirement;
use App\Attribute\JSONResponse;
use App\Database\DatabaseInterface;
use App\Extra\Utils;
use Composer\Spdx\SpdxLicenses;
use Exception;
use InvalidArgumentException;
use League\Config\Configuration;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Respect\Validation\Validator as v;
use RuntimeException;
use Slim\App;
use Slim\Views\Twig;

class ManagementController
{
  #[AuthRequirement('none')]
  public function home(ResponseInterface $response, Twig $twig, DatabaseInterface $db, SpdxLicenses $spdx, Configuration $config): ResponseInterface
  {
    $queue = null;
    $licenses = null;
    $upload_config = null;

    if (!empty($_SESSION['bzid'])) {
      $queue = $db->queue_get_by_bzid($_SESSION['bzid']);
      $upload_config = $config->get('asset.upload');
      if ($queue) {
        $licenses = [
          'popular' => array_fill_keys($upload_config['licenses']['popular'], ''),
          'common' => array_fill_keys($upload_config['licenses']['common'], ''),
          'other' => []
        ];

        foreach ($spdx->getLicenses() as $license) {
          // If this license id is deprecated, skip it
          if ($license[3] === true) {
            continue;
          }

          // If it's a popular or common license, fill in the name
          if (array_key_exists($license[0], $licenses['popular'])) {
            $licenses['popular'][$license[0]] = $license[1];
          } elseif (array_key_exists($license[0], $licenses['common'])) {
            $licenses['common'][$license[0]] = $license[1];
          } // Otherwise, if it's an OSI-approved license, put it under Other
          elseif ($upload_config['licenses']['allow_other_osi'] && $license[2] === true) {
            $licenses['other'][$license[0]] = $license[1];
          }
        }

        foreach($queue as &$asset) {
          // Figure out what license name to show on assets pending approval
          if ($asset['change_requested'] === 0 && $asset['license_id'] !== 'Other') {
            if ($spdx->validate($asset['license_id'])) {
              $asset['license_name'] = $spdx->getLicenseByIdentifier($asset['license_id'])[0];
            } else {
              $asset['license_name'] = 'Unknown or invalid';
            }
          }
        }
      }
    }

    return $twig->render($response, 'home.html.twig', [
      'pending' => $queue,
      'licenses' => $licenses,
      'upload_config' => $upload_config
    ]);
  }

  private function validate_asset_information(SpdxLicenses $spdx, array $upload_config, array $asset): array
  {
    $errors = [];

    // Require an author name
    if (!v::notEmpty()->validate($asset['author'])) {
      $errors[] = 'The author name was not provided.';
    }

    // Verify the source URL, if provided, is actually a URL
    if (!v::optional(v::url())->validate($asset['source_url'])) {
      $errors[] = 'The source URL was not a valid URL.';
    }

    // Require a license
    if (!v::notEmpty()->validate($asset['license'])) {
      $errors[] = 'No license was selected.';
    }
    // If we allow other licenses, check if they provided the name, and either the URL or text
    elseif ($asset['license'] === 'Other') {
      if (!$upload_config['licenses']['allow_other']) {
        $errors[] = 'Other licenses are not allowed.';
      }
      // Verify that the license name is provided
      if (!v::notEmpty()->validate($asset['license_name'])) {
        $errors[] = 'The license name was not provided.';
      }

      // Verify either the license URL or text are provided
      if (!(v::optional(v::url()))->validate($asset['license_url'])) {
        $errors[] = 'The license URL was not a valid URL.';
      } elseif (!(v::notEmpty()->validate($asset['license_url']) || v::notEmpty()->validate($asset['license_text']))) {
        $errors[] = 'The license URL or text was not provided. One or both must be provided when "Other Approved Licensed" is selected.';
      }
    } else {
      // Check if it's a popular or common license, or, if enabled, another OSI-approved license
      if (
        !in_array($asset['license'], $upload_config['licenses']['popular'], true) &&
        !in_array($asset['license'], $upload_config['licenses']['common'], true) &&
        !($upload_config['licenses']['allow_other_osi'] && $spdx->validate($asset['license']) && $spdx->isOsiApprovedByIdentifier($asset['license']))
      ) {
        $errors[] = 'An invalid license was selected.';
      }
    }

    return $errors;
  }

  #[AuthRequirement('user')]
  #[JSONResponse]
  public function changes(App $app, ServerRequestInterface $request, ResponseInterface $response, Twig $twig, Configuration $config, DatabaseInterface $db, SpdxLicenses $spdx, PHPMailer $mailer): ResponseInterface
  {
    // Grab a copy of the form data
    $data = $request->getParsedBody();

    $upload_config = $config->get('asset.upload');

    $return = [];

    $successful_files = 0;

    // Loop through
    foreach ($data['assets'] as $id => $asset) {
      // Fetch the current information
      $row = $db->queue_get_by_bzid_and_id($_SESSION['bzid'], (int)$id);

      if ($row === null) {
        $return['asset_errors'][$id][] = 'Could not locate this asset in the database';
        continue;
      }

      // If changes aren't requested, skip this one
      if ($row['change_requested'] !== 1) {
        continue;
      }

      // Check if there were any changes made
      $has_changes = (
        $asset['author'] != $row['author'] ||
        $asset['source_url'] != $row['source_url'] ||
        $asset['license'] != $row['license_id'] ||
        $asset['license_name'] != $row['license_name'] ||
        $asset['license_url'] != $row['license_url'] ||
        $asset['license_text'] != $row['license_text']
      );

      if ($has_changes) {
        $errors = $this->validate_asset_information($spdx, $upload_config, $asset);

        // If we had errors, add those
        if ($errors) {
          $return['asset_errors'][$id] = $errors;
        }
        // Otherwise, update the queue item
        else {
          // NOTE: This doesn't pass $asset directly because a malicious user could include extra fields
          $db->queue_update($id, [
            'author' => $asset['author'],
            'source_url' => $asset['source_url'],
            'license_id' => $asset['license'],
            'license_name' => $asset['license_name'],
            'license_url' => $asset['license_url'],
            'license_text' => $asset['license_text'],
            'change_requested' => 0
          ]);

          $successful_files++;
        }
      } else {
        $return['asset_errors'][$id][] = 'No changes were made.';
      }
    }

    $return['success'] = empty($return['asset_errors']);

    // If one or more files were successful, send a notification
    if ($successful_files > 0) {
      // Each email will use the same subject/message
      $mailer->Subject = sprintf("%s %s - Uploaded Assets", $config->get('site.game_name'), $config->get('site.title'));
      $mailer->msgHTML($twig->fetch('email/queue_notification.html.twig'));
      $mailer->AltBody = $twig->fetch('email/queue_notification.text.twig');

      foreach($config->get('email.notify_addresses') as $email_address) {
        try {
          $mailer->addAddress($email_address);
        } catch (Exception) {
          // TODO: Log invalid address
          continue;
        }

        // Try to send the email
        try {
          $mailer->send();
        } catch (Exception) {
          $mailer->getSMTPInstance()->reset();
        }

        // Clear the address list
        $mailer->clearAddresses();
      }
    }

    $response->getBody()->write(json_encode($return));
    return $response
      ->withHeader('Content-Type', 'application/json');
  }

  #[AuthRequirement('none')]
  public function login(App $app, ResponseInterface $response, Twig $twig, Configuration $config, $token = '', $username = ''): ResponseInterface
  {
    if (empty($token) || empty($username)) {
      return $response
          ->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('home'))
          ->withStatus(302);
    }

    // Check the token
    $checktokens = urldecode($username);
    if ($config->get('auth.check_ip')) {
      $checktokens .= '@'.$_SERVER['REMOTE_ADDR'];
    }
    $checktokens .= '='.urldecode($token);

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
          list($_SESSION['bzid'], $_SESSION['username']) = explode(' ', substr($line, 6), 2);
          // TODO: Allow other characters? Spaces?
          $_SESSION['clean_username'] = Utils::clean_username($_SESSION['username']);
          // If the cleaned username is empty, bail out
          if (strlen($_SESSION['clean_username']) === 0) {
            break;
          }
          $foundBZID = true;
        }
      }
    }

    // If there was no response from the server list, or we had a problem verifying the token,
    // clear the session and show an error.
    if ($result === false || !$foundBZID || !$foundTOKGOOD) {
      $_SESSION = [];
      session_destroy();
      // TODO: Logging this error
      return $twig->render($response, 'error.html.twig', [
        'message' => 'There was an error verifying your login.'
      ]);
    }

    // Store the user-agent so that we can compare later for session validation
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

    return $twig->render($response, 'login.html.twig', [
      'username' => $_SESSION['username'],
      'bzid' => $_SESSION['bzid'],
      'is_admin' => $_SESSION['is_admin']
    ]);
  }

  #[AuthRequirement('none')]
  public function logout(App $app, ResponseInterface $response): ResponseInterface
  {
    // Clear the session and return to the homepage
    $_SESSION = [];
    session_destroy();
    return $response
        ->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('home'))
        ->withStatus(302);
  }

  #[AuthRequirement('none')]
  public function terms(ResponseInterface $response, Twig $twig, Configuration $config): ResponseInterface
  {
    return $twig->render($response, 'terms.html.twig', [
      'takedown_address' => $config->get('site.takedown_address')
    ]);
  }

  #[AuthRequirement('user')]
  public function upload(App $app, ServerRequestInterface $request, ResponseInterface $response, Twig $twig, Configuration $config, SpdxLicenses $spdx): ResponseInterface
  {
    // Fetch the upload configuration options and clamp values to the PHP maximums
    $upload_config = $config->get('asset.upload');
    $upload_config['max_file_size'] = min($upload_config['max_file_size'], Utils::convertToBytes(ini_get('upload_max_filesize')));
    $upload_config['max_file_count'] = min($upload_config['max_file_count'], ini_get('max_file_uploads'));

    // TODO: Check if we need to factor in a buffer to contain files AND other form data within this max post size
    $upload_config['max_post_size'] = min($upload_config['max_file_size'] * $upload_config['max_file_count'], Utils::convertToBytes(ini_get('post_max_size')));

    $extensions = [];
    foreach ($upload_config['types'] as $e) {
      $extensions = array_merge($extensions, array_map(fn (string $v) => '.' . $v, is_array($e) ? $e : [$e]));
    }
    $upload_config['accept'] = implode(',', $extensions);

    $licenses = [
      'popular' => array_fill_keys($upload_config['licenses']['popular'], ''),
      'common' => array_fill_keys($upload_config['licenses']['common'], ''),
      'other' => []
    ];

    foreach ($spdx->getLicenses() as $license) {
      // If this license id is deprecated, skip it
      if ($license[3] === true) {
        continue;
      }

      // If it's a popular or common license, fill in the name
      if (array_key_exists($license[0], $licenses['popular'])) {
        $licenses['popular'][$license[0]] = $license[1];
      } elseif (array_key_exists($license[0], $licenses['common'])) {
        $licenses['common'][$license[0]] = $license[1];
      } // Otherwise, if it's an OSI-approved license, put it under Other
      elseif ($upload_config['licenses']['allow_other_osi'] && $license[2] === true) {
        $licenses['other'][$license[0]] = $license[1];
      }
    }

    return $twig->render($response, 'upload.html.twig', [
      'upload_config' => $upload_config,
      'licenses' => $licenses,
      'upload_directory' => $_SESSION['clean_username']
    ]);
  }

  #[AuthRequirement('user')]
  #[JSONResponse]
  public function upload_process(ServerRequestInterface $request, ResponseInterface $response, Twig $twig, Configuration $config, DatabaseInterface $db, SpdxLicenses $spdx, PHPMailer $mailer): ResponseInterface
  {
    // Fetch the upload configuration options and clamp values to the PHP maximums
    $upload_config = $config->get('asset.upload');
    $upload_config['max_file_size'] = min($upload_config['max_file_size'], Utils::convertToBytes(ini_get('upload_max_filesize')));
    $upload_config['max_file_count'] = min($upload_config['max_file_count'], ini_get('max_file_uploads'));

    $writeErrors = function (ResponseInterface $response, array $errors) {
      $response->getBody()->write(json_encode([
        'success' => false,
        'errors' => $errors
      ]));
      return $response
        ->withHeader('Content-Type', 'application/json');
    };

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
        'No files were uploaded.'
      ]);
    }

    // Verify the terms were agreed to
    if (!isset($data['agree_terms']) || $data['agree_terms'] !== 'yes') {
      return $writeErrors($response, [
        'You must read and agree to the Terms of Service.'
      ]);
    }

    // Verify the terms were agreed to
    if (!v::email()->validate($data['uploader_email'])) {
      return $writeErrors($response, [
        'You must provide a valid email address.'
      ]);
    }

    // Start tracking errors
    $errors = [];
    $file_errors = [];

    // Track filenames so we can check for duplicates
    // TODO: This may be unnecessary once we start to move the temporary files into place.
    $filenames = [];
    $successful_files = 0;

    foreach($files['assets'] as $index => $upload) {
      $file_errors[$index] = [];

      // Check the error status first
      $error = $upload['file']->getError();
      if ($error != UPLOAD_ERR_OK) {
        $file_errors[$index][] = match ($error) {
          UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file had exceeded the maximum size.',
          UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE => 'The file was not uploaded completely.',
          UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The file could not be written due to a server error.',
          default => 'The upload failed for an unknown reason.',
        };

        continue;
      }

      $filename = $upload['file']->getClientFilename();

      // Verify the filename only contains specific characters
      if (!v::regex('/^[a-zA-Z0-9_\-]+\.[a-z0-9]+$/')->validate($filename)) {
        $file_errors[$index][] = 'The filename contains disallowed characters. Only a-z, A-Z, 0-9, _ and - are allowed, and the file extension must exist, be lowercase, and contain only a-z.';
        continue;
      }

      // Check if this filename was already provided as part of this upload
      if (in_array($filename, $filenames, true)) {
        $file_errors[$index][] = 'A duplicate filename was found in this upload.';
        continue;
      }

      // Add this filename to the list of filenames part of this upload
      $filenames[] = $filename;

      // Check the mime type and file extension
      $path_tmp = $upload['file']->getStream()->getMetadata('uri');
      $mime_type = mime_content_type($path_tmp);
      $ext_start = strrpos($filename, '.');
      // Verify we have an extension
      if ($ext_start === false || strlen($filename) === $ext_start + 1) {
        $file_errors[$index][] = 'The filename does not have an extension.';
        continue;
      }
      $extension = substr($filename, $ext_start + 1);

      // Check if this is a supported mime type
      if (!array_key_exists($mime_type, $upload_config['types'])) {
        // TODO: Should we list all support types?
        $file_errors[$index][] = 'Unsupported MIME type.';
        continue;
      }
      // If it is supported, check if it has an expected extension for this mime type
      else {
        $el = &$upload_config['types'][$mime_type];
        if (!in_array($extension, is_array($el) ? $el : [$el], true)) {
          $file_errors[$index][] = 'Unexpected file extension for this MIME type.';
          continue;
        }
      }

      // Verify the file size does not exceed the limit
      $file_size = $upload['file']->getSize();
      if ($file_size > min($config->get('asset.upload.max_file_size'), Utils::convertToBytes(ini_get('upload_max_filesize')))) {
        $file_errors[$index][] = 'The maximum file size was exceeded.';
        continue;
      }

      // If it's an image, ensure it doesn't exceed the maximum size
      if (str_starts_with($mime_type, 'image/')) {
        $image_size = getimagesize($path_tmp);
        if ($image_size !== false && ($image_size[0] > $config->get('asset.image.max_width') || $image_size[1] > $config->get('asset.image.max_height'))) {
          $file_errors[$index][] = 'The image exceeds the maximum width or height.';
          continue;
        }
      }

      // Build the temporary and final destination paths
      $path_queue = $config->get('path.upload')."/{$_SESSION['bzid']}_$filename";
      $path_final = $config->get('path.files')."/{$_SESSION['clean_username']}/$filename";

      // Verify that a file with the same name doesn't already exist in the queue for this user
      if (file_exists($path_queue)) {
        $file_errors[$index][] = 'A file with the same name already exists in your current queue.';
        continue;
      }

      // Verify that a file with the same final path doesn't already exist in the files directory
      if (file_exists($path_final)) {
        $file_errors[$index][] = 'A file with the same name already exists in the final directory.';
        continue;
      }

      // Verify the other form data exists for this file
      if (!isset($data['assets'][$index]) || !is_array($data['assets'][$index])) {
        $file_errors[$index][] = 'The asset information was not provided.';
        continue;
      }

      // Grab a short reference to this asset's information
      $d = &$data['assets'][$index];

      $file_errors[$index] = [
        ...$file_errors[$index],
        ...$this->validate_asset_information($spdx, $upload_config, $d)
      ];

      // If we have no errors for this file, move it into the temporary directory and add it to the queue
      if (!isset($file_errors[$index]) || sizeof($file_errors[$index]) === 0) {
        try {
          $upload['file']->moveTo($path_queue);
        } catch (InvalidArgumentException | RuntimeException) {
          // TODO: Log the error
          $file_errors[$index][] = 'A server error occurred while moving the temporary file.';
          continue;
        }

        try {
          $db->queue_add([
            'bzid' => $_SESSION['bzid'],
            'username' => $_SESSION['username'],
            'email' => $data['uploader_email'],
            'filename' => $filename,
            'file_size' => $file_size,
            'mime_type' => $mime_type,
            'author' => $d['author'],
            'source_url' => $d['source_url'],
            'license_id' => $d['license'],
            'license_name' => $d['license_name'],
            'license_url' => $d['license_url'],
            'license_text' => $d['license_text']
          ]);
        } catch (Exception) {
          // TODO: Log the error
          // TODO: Delete the file that was moved
          $file_errors[$index][] = 'A database error occurred while adding the file to the queue.';
        }

        // If we had no errors, delete the empty array
        if (empty($file_errors[$index])) {
          unset($file_errors[$index]);
        }

        $successful_files++;
      }
    }

    // If one or more files were successful, send a notification
    if ($successful_files > 0) {
      // Each email will use the same subject/message
      $mailer->Subject = sprintf("%s %s - Uploaded Assets", $config->get('site.game_name'), $config->get('site.title'));
      $mailer->msgHTML($twig->fetch('email/queue_notification.html.twig'));
      $mailer->AltBody = $twig->fetch('email/queue_notification.text.twig');

      foreach($config->get('email.notify_addresses') as $email_address) {
        try {
          $mailer->addAddress($email_address);
        } catch (Exception) {
          // TODO: Log invalid address
          continue;
        }

        // Try to send the email
        try {
          $mailer->send();
        } catch (Exception) {
          $mailer->getSMTPInstance()->reset();
        }

        // Clear the address list
        $mailer->clearAddresses();
      }
    }

    // If we had any errors, return them to the user
    if (sizeof($errors) > 0 || sizeof($file_errors) > 0) {
      $response->getBody()->write(json_encode([
        'success' => false,
        'errors' => $errors,
        'file_errors' => $file_errors
      ]));
      return $response
        ->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode([
      'success' => true,
    ]));
    return $response
      ->withHeader('Content-Type', 'application/json');
  }

  #[AuthRequirement('admin')]
  public function queue(App $app, ServerRequestInterface $request, ResponseInterface $response, Twig $twig, DatabaseInterface $db, SpdxLicenses $spdx): ResponseInterface
  {
    // TODO: Pagination?

    // Get all the items in the queue
    $queue = $db->queue_get();

    foreach ($queue as &$asset) {
      if ($asset['license_id'] !== 'Other') {
        if ($spdx->validate($asset['license_id'])) {
          $asset['license_name'] = $spdx->getLicenseByIdentifier($asset['license_id'])[0];
        } else {
          $asset['license_name'] = 'Unknown or invalid';
        }
      }
    }

    return $twig->render($response, 'queue.html.twig', [
      'queue' => $queue
    ]);
  }

  #[AuthRequirement('admin')]
  public function queue_process(App $app, ServerRequestInterface $request, ResponseInterface $response, Twig $twig, Configuration $config, DatabaseInterface $db, SpdxLicenses $spdx, PHPMailer $mailer): ResponseInterface
  {
    $data = $request->getParsedBody();

    $errors = [];

    $notifications = [];

    $base_url = $config->get('site.base_url');

    foreach($data['review'] as $id => $review) {
      if (empty($review['action'])) {
        continue;
      }

      // Fetch this queue entry
      $queue = $db->queue_get_by_id($id);

      if (!$queue) {
        continue;
      }

      if ($review['action'] === 'approve') {
        // Paths
        $path_queue = $config->get('path.upload')."/{$queue['bzid']}_{$queue['filename']}";
        $path_clean_dir = Utils::clean_username($queue['username']);
        if (strlen($path_clean_dir) === 0) {
          $errors[$id][] = 'The destination directory name is blank.';
          continue;
        }
        $path_final_dir = $config->get('path.files')."/$path_clean_dir";
        $path_final = "$path_final_dir/{$queue['filename']}";

        // Verify the temporary file exists
        if (!file_exists($path_queue)) {
          $errors[$id][] = 'The temporary file does not exist.';
          continue;
        }

        // Verify the final destination doesn't already exist
        if (file_exists($path_final)) {
          $errors[$id][] = 'A file already exists at the final path.';
          continue;
        }

        // Create the final directory if it doesn't exist
        if (!is_dir($path_final_dir)) {
          if (!file_exists($path_final_dir)) {
            mkdir($path_final_dir);
          }
          // Else, it might exist as a file or link, so bail
          else {
            $errors[$id][] = 'File or link exists at the final directory.';
            continue;
          }
        }

        // Copy the temporary file to the final destination
        if (copy($path_queue, $path_final) === false) {
          $errors[$id][] = 'Failed to place the file in the final directory.';
          continue;
        }

        // Add the approved asset to the database
        if ($db->asset_add([
          'path' => $path_clean_dir,
          'bzid' => $queue['bzid'],
          'username' => $queue['username'],
          'filename' => $queue['filename'],
          'file_size' => $queue['file_size'],
          'mime_type' => $queue['mime_type'],
          'author' => $queue['author'],
          'source_url' => $queue['source_url'],
          'license_id' => $queue['license_id'],
          'license_name' => $queue['license_name'],
          'license_url' => $queue['license_url'],
          'license_text' => $queue['license_text']
        ]) === null) {
          $errors[$id][] = 'Failed to add asset entry.';
          unlink($path_final);
          continue;
        }

        // Remove it from the database
        if (!$db->queue_remove($queue['id'])) {
          $errors[$id][] = 'Failed to remove queue entry.';
          continue;
        }

        // Remove the temporary file
        unlink($path_queue);

        $notifications[$queue['email']]['approved'][] = [
          'filename' => $queue['filename'],
          'final_url' => "$base_url/$path_clean_dir/{$queue['filename']}"
        ];

      } elseif ($review['action'] === 'request' || $review['action'] === 'reject') {
        // Verify that we have the details for this review
        if (!v::notEmpty()->validate($review['details'])) {
          $errors[$id][] = 'Review details were not provided.';
          continue;
        }

        if ($review['action'] === 'request') {
          $db->queue_update($queue['id'], [
            'details' => $review['details'],
            'change_requested' => 1
          ]);

          $notifications[$queue['email']]['change_requested'][] = [
            'filename' => $queue['filename'],
            'details' => $review['details']
          ];
        } else {
          // Delete the temporary file
          unlink($config->get('path.upload')."/{$queue['bzid']}_{$queue['filename']}");

          // Remove the entry from the database
          $db->queue_remove($queue['id']);

          $notifications[$queue['email']]['rejected'][] = [
            'filename' => $queue['filename'],
            'details' => $review['details']
          ];
        }
      }
    }

    // Send notification emails
    $mailer->Subject = sprintf("%s %s - Reviewed Assets", $config->get('site.game_name'), $config->get('site.title'));
    foreach($notifications as $email_address => $reviews) {
      try {
        $mailer->addAddress($email_address);
      } catch (Exception) {
        // TODO: Log error about invalid address
        continue;
      }
      $mailer->msgHTML($twig->fetch('email/review_notification.html.twig', $reviews));
      $mailer->AltBody = $twig->fetch('email/review_notification.text.twig', $reviews);

      // Try to send the email
      try {
        $mailer->send();
      } catch (Exception) {
        $mailer->getSMTPInstance()->reset();
      }

      // Clear the address list
      $mailer->clearAddresses();
    }

    return $response
      ->withHeader('Location', $app->getRouteCollector()->getRouteParser()->urlFor('queue'))
      ->withStatus(302);
  }
}
