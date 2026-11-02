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
use Monolog\Logger;
use PDO;
use PDOException;

class MariaDB implements DatabaseInterface
{
  /**
   * @var PDO MariaDB PDO database object
   */
  private PDO $db;

  private const string QUEUE_JOIN = /** @lang MariaDB */ 'SELECT q.asset_id as id, q.email, q.details, q.change_requested, q.created_at, a.path, a.filename, a.bzid, a.username, a.file_size, a.author, a.source_url, a.license_id, a.license_name, a.license_url, a.license_text FROM queue q INNER JOIN asset a ON a.id = q.asset_id';

  public function __construct(Configuration $config, private Logger $logger)
  {
    // Open the database connection
    $this->db = new PDO(
      "mysql:host={$config->get('database.host')};port={$config->get('database.port')};dbname={$config->get('database.name')}",
      $config->get('database.user'),
      $config->get('database.password'),
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]
    );
  }

  public function queue_get(): array
  {
    $query = $this->db->query(/** @lang MariaDB */ self::QUEUE_JOIN.' WHERE a.approved_at = NULL');
    return $query->fetchAll();
  }

  public function queue_get_by_id(int $id): ?array
  {
    $stmt = $this->db->prepare(/** @lang MariaDB */ self::QUEUE_JOIN.' WHERE a.approved_at = NULL AND a.id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row !== false) {
      return $row;
    }
    return null;
  }

  public function queue_get_by_bzid(string $bzid): ?array
  {
    $stmt = $this->db->prepare(/** @lang MariaDB */ self::QUEUE_JOIN.' WHERE a.approved_at = NULL AND bzid = :bzid');
    $stmt->execute(['bzid' => $bzid]);
    $rows = $stmt->fetchAll();
    if (sizeof($rows) > 0) {
      return $rows;
    }
    return null;
  }

  public function queue_get_by_bzid_and_id(string $bzid, int $id): ?array
  {
    $stmt = $this->db->prepare(/** @lang MariaDB */ self::QUEUE_JOIN.' WHERE a.approved_at = NULL AND bzid = :bzid AND id = :id');
    $stmt->execute(['bzid' => $bzid, 'id' => $id]);
    $row = $stmt->fetch();
    if ($row !== false) {
      return $row;
    }
    return null;
  }

  public function queue_add(array $data): ?int
  {
    $stmt = $this->db->prepare(/** @lang MariaDB */ 'INSERT INTO queue (asset_id, email) VALUES (:asset_id, :email)');
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
    $stmt = $this->db->prepare(/** @lang MariaDB */ "UPDATE queue SET $fields WHERE asset_id = :id LIMIT 1");
    $stmt->execute([...$data, 'id' => $id]);
    return $stmt->rowCount() === 1;
  }

  public function queue_remove(int $id): bool
  {
    $stmt = $this->db->prepare(/** @lang MariaDB */ 'DELETE FROM queue WHERE asset_id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->rowCount() === 1;
  }

  public function asset_get_by_path($path): ?array
  {
    $stmt = $this->db->prepare(/** @lang MariaDB */ 'SELECT path, filename, bzid, username, file_size, hash_sha256, mime_type, author, source_url, license_id, license_name, license_url, license_text, approved_by, approved_at, created_at, updated_at FROM asset WHERE path = :path');
    $stmt->execute(['path' => $path]);
    $rows = [];
    while ($row = $stmt->fetch()) {
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
      $stmt = $this->db->prepare(/** @lang MariaDB */ 'INSERT INTO asset (path, filename, bzid, username, file_size, hash_sha256, mime_type, author, source_url, license_id, license_name, license_url, license_text, approved_by) VALUES (:path, :bzid, :username, :filename, :file_size, :mime_type, :author, :source_url, :license_id, :license_name, :license_url, :license_text, :hash_sha256, :approved_by)');
      $stmt->execute($data);
      $id = $this->db->lastInsertId();
    } catch (PDOException $e) {
      $this->logger->critical($e);
      return null;
    }
    return ($id !== false) ? (int)$id : null;
  }
}
