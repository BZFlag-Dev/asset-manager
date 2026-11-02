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

use Phinx\Migration\AbstractMigration;

final class InitialStructure extends AbstractMigration
{
  /**
   * Change Method.
   *
   * Write your reversible migrations using this method.
   *
   * More information on writing migrations is available here:
   * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
   *
   * Remember to call "create()" or "update()" and NOT "save()" when working
   * with the Table class.
   */
  public function change(): void
  {

    $asset = $this->table('asset');
    $asset
      ->addColumn('path', 'string', ['length' => 255, 'null' => false])
      ->addColumn('filename', 'string', ['length' => 255, 'null' => false])
      ->addColumn('bzid', 'string', ['length' => 255, 'null' => false])
      ->addColumn('username', 'string', ['length' => 255, 'null' => false])
      ->addColumn('file_size', 'integer', ['null' => false, 'signed' => false])
      ->addColumn('hash_sha256', 'string', ['length' => 64, 'null' => true])
      ->addColumn('mime_type', 'string', ['length' => 255, 'null' => false])
      ->addColumn('author', 'string', ['length' => 255, 'null' => false])
      ->addColumn('source_url', 'string', ['length' => 255, 'null' => false])
      ->addColumn('license_id', 'string', ['length' => 255, 'null' => false])
      ->addColumn('license_name', 'string', ['length' => 255, 'null' => true])
      ->addColumn('license_url', 'string', ['length' => 255, 'null' => true])
      ->addColumn('license_text', 'text', ['null' => true])
      ->addColumn('approved_by', 'string', ['length' => 255, 'null' => true])
      ->addColumn('approved_at', 'timestamp', ['null' => true])
      ->addTimestampsWithTimezone()
      ->addIndex(['path', 'filename'], ['unique' => true, 'name' => 'idx_asset_path_filename'])
      ->addIndex('path', ['name' => 'idx_asset_path'])
      ->addIndex('approved_at', ['name' => 'idx_asset_approved_at'])
      ->create()
    ;

    $queue = $this->table('queue', ['id' => false, 'primary_key' => 'asset_id']);
    $queue
      ->addColumn('asset_id', 'integer', ['null' => false, 'signed' => false])
      ->addColumn('email', 'string', ['length' => 255, 'null' => false])
      ->addColumn('details', 'text', ['null' => true])
      ->addColumn('change_requested', 'boolean', ['default' => false])
      ->addTimestampsWithTimezone(updatedAt: false)
      ->addIndex('change_requested', ['name' => 'idx_queue_change_requested'])
      ->addForeignKey('asset_id', 'asset', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
      ->create()
    ;
  }
}
