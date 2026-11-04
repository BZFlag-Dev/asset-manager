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

namespace App\Service;

use App\Entity\Asset;
use Doctrine\ORM\EntityManager;
use League\Config\Configuration;
use Monolog\Logger;
use Slim\Views\Twig;
use Symfony\Component\Finder\Finder;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

readonly class DirectoryIndexGenerator
{
  public function __construct(private Configuration $config, private EntityManager $entityManager, private Twig $twig, private Logger $logger)
  {
  }

  public function writeIndex(string $path): bool
  {
    // Trim the trailing slash off the path
    $path = trim($path, '/');
    $fullPath = "{$this->config->get('path.files')}/$path";

    // Create some variables to hold information about directories and files
    $directories = [];
    $assets = [];

    // Prepare a query to fetch information about an asset.
    $query = $this->entityManager->getRepository(Asset::class)
      ->createQueryBuilder('a')
      ->where('a.path = :path')
      ->andWhere('a.filename = :filename')
      ->andWhere('a.approved_at IS NOT NULL')
      ->setMaxResults(1)
      ->setParameter('path', $path)
    ;

    // Set up Symfony Finder with natural, case-insensitive sorting by name
    $finder = new Finder()->sortByCaseInsensitiveName(true);

    // Populate the list of directories
    foreach ($finder->directories()->in($fullPath)->depth('== 0') as $directory) {
      $directories[] = $directory->getFilename();
    }

    // Set up some mappings to Doctrine entity getters
    $assetFieldMappings = [
      'username' => 'getUsername',
      'author' => 'getAuthor',
      'license_id' => 'getLicenseId',
      'license_name' => 'getLicenseDisplayName',
      'license_url' => 'getLicenseUrl',
      'license_text' => 'getLicenseText',
    ];

    // Loop through all the files in this directory
    foreach ($finder->files()->in($fullPath)->depth('== 0') as $file) {
      // Skip index.html
      if ($file->getFilename() === 'index.html') {
        continue;
      }

      // Populate the minimal data, which is just the file size
      $asset = [
        'file_size' => $file->getSize()
      ];

      // Attempt to fetch information about this file from the database
      $data = $query
        ->setParameter('filename', $file->getFilename())
        ->getQuery()
        ->getOneOrNullResult()
      ;

      // Loop through the fields and either store the value from a getter, or an empty string if $data is null
      foreach ($assetFieldMappings as $field => $getter) {
        $asset[$field] = $data?->$getter() ?? '';
      }

      // Add it to the pile
      $assets[$file->getFilename()] = $asset;
    }

    // Attempt to write out index.html to this directory.
    try {
      $output = $this->twig->fetch('directory_index.html.twig', [
        'path' => $path,
        'directories' => $directories,
        'assets' => $assets,
        'takedown_address' => $this->config->get('site.takedown_address'),
        'base_path' => $this->config->get('site.base_path')
      ]);

      file_put_contents("$fullPath/index.html", $output);

      return true;
    } catch (LoaderError|SyntaxError|RuntimeError $e) {
      $this->logger->error($e->getMessage());
    }

    return false;
  }

  public function rebuildAll(): int
  {
    // Keep track of how many indexes we built
    $count = 0;

    // Get the base directory
    $baseDir = rtrim($this->config->get('path.files'), '/');

    // Rebuild the top level index
    echo "Rebuilding top level directory index.\n";
    if ($this->writeIndex('')) {
      $count++;
    }

    // Set up Symfony Finder and fetch all the directories, recursively
    $finder = new Finder();
    $directories = $finder->directories()->in($baseDir);

    // Loop through each directory found and write a new index.html for each
    foreach ($directories as $directory) {
      echo "Rebuilding index for {$directory->getRelativePathname()}\n";
      if ($this->writeIndex($directory->getRelativePathname())) {
        $count++;
      }
    }

    return $count;
  }
}
