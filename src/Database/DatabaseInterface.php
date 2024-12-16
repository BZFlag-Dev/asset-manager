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

interface DatabaseInterface
{
  public function __construct(Configuration $config, Logger $logger);

  public function queue_get(): array;

  public function queue_get_by_id(int $id): ?array;

  public function queue_get_by_bzid(string $bzid): ?array;

  public function queue_get_by_bzid_and_id(string $bzid, int $id): ?array;

  public function queue_add(array $data): ?int;

  public function queue_update(int $id, array $data): bool;

  public function queue_remove(int $id): bool;

  public function asset_get_by_path($path): ?array;

  //public function asset_get_detail($path, $filename): ?array;

  public function asset_add(array $data): ?int;
}
