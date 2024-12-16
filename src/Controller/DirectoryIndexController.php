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
use DirectoryIterator;
use League\Config\Configuration;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Views\Twig;

class DirectoryIndexController
{
  public function generate(App $app, ResponseInterface $response, Twig $twig, Configuration $config, DatabaseInterface $db, SpdxLicenses $spdx, string $path): ResponseInterface
  {
    // Trim the trailing slash off the path
    $path = trim($path, '/');

    $directories = [];
    $assets = [];
    $data = $db->asset_get_by_path($path);

    // Iterate through the contents of this path and enumerate directories
    $iter = new DirectoryIterator("{$config->get('path.files')}/$path");
    foreach ($iter as $info) {
      // Skip . and ..
      if ($info->isDot()) {
        continue;
      }

      $filename = $info->getFilename();

      // Skip index.php and data.sqlite3
      if ($filename === 'index.php' || $filename == 'data.sqlite3') {
        continue;
      }

      // Is this a directory?
      if ($info->isDir()) {
        $directories[] = [
          'path' => "$filename/",
          'name' => $filename
        ];
      } elseif ($info->isFile()) {
        if (isset($data[$filename])) {
          $assets[] = $data[$filename];
        } else {
          $assets[] = [
            'filename' => $filename,
            'file_size' => $info->getSize(),
            'author' => '(Unknown)',
            'license_name' => '(Unknown)'
          ];
        }
      }
    }

    // Sort the directories and assets
    uasort($directories, fn ($a, $b) => strcmp($a['path'], $b['path']));
    uasort($assets, fn ($a, $b) => strcmp($a['filename'], $b['filename']));

    return $twig->render($response, 'directory_index.html.twig', [
      'path' => $path,
      'directories' => $directories,
      'assets' => $assets,
      'takedown_address' => $config->get('site.takedown_address'),
      'base_path' => $config->get('site.base_path')
    ]);
  }
}
