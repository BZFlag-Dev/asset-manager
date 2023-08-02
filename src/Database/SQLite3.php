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

namespace App\Database;

use League\Config\Configuration;
use PDO;
use PDOException;

class SQLite3 implements DatabaseInterface
{
  /**
   * @var PDO SQlite3 PDO database object
   */
  private PDO $db;

  private const DATABASE_VERSION = 1;

  public function __construct(Configuration $config)
  {
    // Open the database
    $this->db = new PDO("sqlite:{$config->get('database.path')}", '', '', [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Database upgrade
    $version = 0;
    try {
      // Check if the database needs to be upgraded
      $stmt = $this->db->query(/** @lang SQLite */ "PRAGMA user_version");
      $version = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
      die("Unable to check database version: ".$e->getMessage());
    }

    if ($version < self::DATABASE_VERSION) {
      try {
        // Disable foreign key integrity checks during the upgrade
        //$this->db->query('PRAGMA foreign_keys=off');

        // Begin a transaction for the upgrade process
        $this->db->beginTransaction();

        // If this is a clean install, create the initial tables
        if ($version < 1) {
          $this->db->query(/** @lang SQLite */ 'CREATE TABLE queue (id INTEGER PRIMARY KEY, bzid TEXT NOT NULL, filename TEXT NOT NULL, type TEXT NOT NULL, author_name TEXT NOT NULL, license_name TEXT NOT NULL, license_url TEXT, license_text TEXT, UNIQUE(bzid, filename))');
        }

        // Update the user_version now that we've updated the schema
        $this->db->query(/** @lang SQLite */ 'PRAGMA user_version = ' . self::DATABASE_VERSION);

        // Commit the transaction
        $this->db->commit();
      } catch (PDOException $e) {
        // Rollback the attempted upgrade
        $this->db->rollBack();
        // TODO: Proper error handling/logging
        die("Unable to create/upgrade the database: ".$e->getMessage());
      }
    }

    try {
      // Enable foreign key integrity checks
      $this->db->query(/** @lang SQLite */ 'PRAGMA foreign_keys=on');
    } catch (PDOException $e) {
      // TODO: Proper error handling/logging
      die("Unable to enable foreign key integrity checks: ".$e->getMessage());
    }
  }

  public function queue_get_by_bzid($bzid): ?array
  {
    try {
      $stmt = $this->db->prepare(/** @lang SQLite */ "SELECT * FROM queue WHERE bzid = :bzid");
      $stmt->execute(['bzid' => $bzid]);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      if (sizeof($rows) > 0) {
        return $rows;
      }
      return null;
    } catch (PDOException $e) {
      die($e->getMessage());
    }
  }

  public function queue_get_by_bzid_and_id($bzid, $id): ?array
  {
    try {
      $stmt = $this->db->prepare(/** @lang SQLite */ "SELECT * FROM queue WHERE bzid = :bzid AND id = :id");
      $stmt->execute(['bzid' => $bzid, 'id' => $id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row !== false) {
        return $row;
      }
      return null;
    } catch (PDOException $e) {
      die($e->getMessage());
    }
  }
}
