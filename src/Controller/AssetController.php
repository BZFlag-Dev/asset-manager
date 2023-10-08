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
use League\Config\Configuration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AssetController
{
  public function view(ServerRequestInterface $request, ResponseInterface $response, Configuration $config, DatabaseInterface $db, string $bzid, int $queueid, int $width = 0, int $height = 0): ResponseInterface
  {
    if (empty($_SESSION['username'])) {
      $response->getBody()->write("401 Unauthorized");
      return $response
          ->withStatus(401);
    }

    // Look up the entry in moderation queue
    $asset = $db->queue_get_by_bzid_and_id($bzid, $queueid);
    if ($asset === null) {
      $response->getBody()->write("404 Not Found");
      return $response
        ->withStatus(404);
    }

    // Clamp the maximum values
    $width = min($width, $config->get('asset.image.max_width'));
    $height = min($height, $config->get('asset.image.max_height'));

    // Store a reference to the full path of the file
    $fullPath = $config->get('path.upload')."/{$asset['bzid']}_{$asset['filename']}";

    // Verify that the file actually exists
    if (!file_exists($fullPath)) {
      $response->getBody()->write("404 Not Found");
      return $response
        ->withStatus(404);
    }

    if (str_starts_with($asset['mime_type'], 'image/')) {
      // If either the width or the height are 0 or less, just send the original file
      if ($width <= 0 || $height <= 0) {
        $response = $response->withHeader('Content-Type', $asset['type']);
        $response->getBody()->write(file_get_contents($fullPath));
      } else {
        // Get information about the image file
        $info = getimagesize($fullPath);

        // If we couldn't read information about the image, bail out now.
        if ($info === false) {
          $response->getBody()->write("500 Error Reading File");
          return $response
            ->withStatus(500);
        }

        // Calculate the image ratios and
        $sourceRatio = $info[0] / $info[1];
        $targetRatio = $width / $height;

        // Adjust the target dimensions to match the source ratio
        if ($sourceRatio > $targetRatio) {
          $height = floor($info[1] / ($info[0] / $width));
        } elseif ($sourceRatio < $targetRatio) {
          $width = floor($info[0] / ($info[1] / $height));
        }

        $thumbnail = imagecreatetruecolor($width, $height);
        try {
          $image = match ($asset['mime_type']) {
            'image/png' => imagecreatefrompng($fullPath),
            'image/jpeg' => imagecreatefromjpeg($fullPath),
            'image/gif' => imagecreatefromgif($fullPath),
            'image/avif' => imagecreatefromavif($fullPath),
            'image/webp' => imagecreatefromwebp($fullPath),
          };
        } catch (\UnhandledMatchError $e) {
          $response->getBody()->write('500 Error Reading Image');
          return $response
            ->withStatus(500);
        }

        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);

        ob_start();
        if (imagejpeg($thumbnail)) {
          $response = $response->withHeader('Content-Type', 'image/jpeg');
          $response->getBody()->write(ob_get_contents());
        } else {
          $response->getBody()->write("500 Error encoding thumbnail");
          $response = $response->withStatus(500);
        }
        ob_end_clean();
      }
    } else {
      $response->getBody()->write("403 Unsupported file type");
      $response = $response->withStatus(403);
    }

    return $response;
  }
}
