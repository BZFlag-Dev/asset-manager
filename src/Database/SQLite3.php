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

use Exception;
use League\Config\Configuration;
use Monolog\Logger;
use PDO;
use PDOException;

class SQLite3 implements DatabaseInterface
{
  /**
   * @var PDO SQlite3 PDO database object
   */
  private PDO $db;

  private const DATABASE_VERSION = 3;

  public function __construct(Configuration $config, private Logger $logger)
  {
    // Open the database
    $this->db = new PDO("sqlite:{$config->get('database.path')}", '', '', [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Database upgrade
    try {
      // Check if the database needs to be upgraded
      $stmt = $this->db->query(/** @lang SQLite */ "PRAGMA user_version");
      $version = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
      throw new Exception("Unable to check database version: ".$e->getMessage());
    }

    if ($version < self::DATABASE_VERSION) {
      try {
        // Disable foreign key integrity checks during the upgrade
        //$this->db->query('PRAGMA foreign_keys=off');

        // Begin a transaction for the upgrade process
        $this->db->beginTransaction();

        // If this is a clean install, create the initial tables
        if ($version < 1) {
          $this->db->query(/** @lang SQLite */ 'CREATE TABLE queue (id INTEGER PRIMARY KEY AUTOINCREMENT, bzid TEXT NOT NULL, username TEXT NOT NULL, email TEXT NOT NULL, filename TEXT NOT NULL, file_size INTEGER NOT NULL, mime_type TEXT NOT NULL, author TEXT NOT NULL, source_url TEXT NOT NULL, license_id TEXT NOT NULL, license_name TEXT, license_url TEXT, license_text TEXT, details TEXT, change_requested INTEGER NOT NULL DEFAULT 0, UNIQUE(bzid, filename))');
          $this->db->query(/** @lang SQLite */ 'CREATE INDEX queue_bzid_idx ON queue (bzid)');
          $this->db->query(/** @lang SQLite */ 'CREATE INDEX queue_change_requested_idx ON queue (change_requested)');
        }

        // We added a table to store approved assets
        if ($version < 2) {
          $this->db->query(/** @lang SQLite */ 'CREATE TABLE asset (id INTEGER PRIMARY KEY AUTOINCREMENT, path TEXT NOT NULL, bzid TEXT NOT NULL, username TEXT NOT NULL, filename TEXT NOT NULL, file_size INTEGER NOT NULL, mime_type TEXT NOT NULL, author TEXT NOT NULL, source_url TEXT, license_id TEXT NOT NULL, license_name TEXT NOT NULL, license_url TEXT, license_text TEXT, UNIQUE(path, filename))');
          $this->db->query(/** @lang SQLite */ 'CREATE INDEX asset_path_idx ON asset (path)');
        }

        // Some columns were added. Because one of them defaults to CURRENT_TIMESTAMP, we can't just use ALTER TABLE.
        if ($version < 3) {
          $this->db->query(/** @lang SQLite */ 'CREATE TABLE asset2 (id INTEGER PRIMARY KEY AUTOINCREMENT, path TEXT NOT NULL, bzid TEXT NOT NULL, username TEXT NOT NULL, filename TEXT NOT NULL, file_size INTEGER NOT NULL, mime_type TEXT NOT NULL, author TEXT NOT NULL, source_url TEXT, license_id TEXT NOT NULL, license_name TEXT NOT NULL, license_url TEXT, license_text TEXT, hash_sha256 TEXT NULL, approved_by TEXT NULL, when_approved TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(path, filename))');
          $this->db->query(/** @lang SQLite */ 'INSERT INTO asset2 SELECT id, path, bzid, username, filename, file_size, mime_type, author, source_url, license_id, license_name, license_url, license_text, NULL, NULL, CURRENT_TIMESTAMP FROM asset');
          $this->db->query(/** @lang SQLite */ 'ALTER TABLE asset RENAME TO oldasset'); // Keep the old table in case something goes horribly wrong
          $this->db->query(/** @lang SQLite */ 'ALTER TABLE asset2 RENAME TO asset');
        }

        // Update the user_version now that we've updated the schema
        $this->db->query(/** @lang SQLite */ 'PRAGMA user_version = ' . self::DATABASE_VERSION);

        // Commit the transaction
        $this->db->commit();
      } catch (PDOException $e) {
        // Rollback the attempted upgrade
        $this->db->rollBack();
        throw new Exception("Unable to create/upgrade the database: ".$e->getMessage());
      }
    }

    try {
      // Enable foreign key integrity checks
      $this->db->query(/** @lang SQLite */ 'PRAGMA foreign_keys=on');
    } catch (PDOException $e) {
      throw new Exception("Unable to enable foreign key integrity checks: ".$e->getMessage());
    }
  }

  public function queue_get(): array
  {
    $query = $this->db->query(/** @lang SQLite */ 'SELECT * FROM queue');
    return $query->fetchAll();
  }

  public function queue_get_by_id(int $id): ?array
  {
    $stmt = $this->db->prepare(/** @lang SQLite */ "SELECT * FROM queue WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
      return $row;
    }
    return null;
  }

  public function queue_get_by_bzid(string $bzid): ?array
  {
    $stmt = $this->db->prepare(/** @lang SQLite */ "SELECT * FROM queue WHERE bzid = :bzid");
    $stmt->execute(['bzid' => $bzid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (sizeof($rows) > 0) {
      return $rows;
    }
    return null;
  }

  public function queue_get_by_bzid_and_id(string $bzid, int $id): ?array
  {
    $stmt = $this->db->prepare(/** @lang SQLite */ "SELECT * FROM queue WHERE bzid = :bzid AND id = :id");
    $stmt->execute(['bzid' => $bzid, 'id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
      return $row;
    }
    return null;
  }

  public function queue_add(array $data): ?int
  {
    $stmt = $this->db->prepare(/** @lang SQLite */ 'INSERT INTO queue (bzid, username, email, filename, file_size, mime_type, author, source_url, license_id, license_name, license_url, license_text) VALUES (:bzid, :username, :email, :filename, :file_size, :mime_type, :author, :source_url, :license_id, :license_name, :license_url, :license_text)');
    $stmt->execute($data);
    $id = $this->db->lastInsertId();
    return ($id !== false) ? (int)$id : null;
  }

  public function queue_update(int $id, array $data): bool
  {
    if (sizeof($data) === 0) {
      return false;
    }

    $fields = implode(', ', array_map(fn ($k) => "$k = :$k", array_keys($data)));
    $stmt = $this->db->prepare(/** @lang SQLite */ "UPDATE queue SET $fields WHERE id = :id LIMIT 1");
    $stmt->execute([...$data, 'id' => $id]);
    return $stmt->rowCount() === 1;
  }

  public function queue_remove(int $id): bool
  {
    $stmt = $this->db->prepare(/** @lang SQLite */ 'DELETE FROM queue WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->rowCount() === 1;
  }

  public function asset_get_by_path($path): ?array
  {
    $stmt = $this->db->prepare(/** @lang SQLite */ 'SELECT username, filename, file_size, mime_type, author, license_id, license_name, license_url, license_text FROM asset WHERE path = :path');
    $stmt->execute(['path' => $path]);
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $rows[ $row['filename'] ] = $row;
    }
    if (sizeof($rows) > 0) {
      return $rows;
    }
    return null;
  }

  public function asset_add(array $data): ?int
  {
    try {
      $stmt = $this->db->prepare(/** @lang SQLite */ 'INSERT INTO asset (path, bzid, username, filename, file_size, mime_type, author, source_url, license_id, license_name, license_url, license_text, hash_sha256, approved_by) VALUES (:path, :bzid, :username, :filename, :file_size, :mime_type, :author, :source_url, :license_id, :license_name, :license_url, :license_text, :hash_sha256, :approved_by)');
      $stmt->execute($data);
      $id = $this->db->lastInsertId();
    }
    catch (PDOException $e) {
      $this->logger->critical($e);
      return null;
    }
    return ($id !== false) ? (int)$id : null;
  }
}
