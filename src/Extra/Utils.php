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

namespace App\Extra;

class Utils
{
  // Convert the various byte units used in php.ini to bytes
  public static function convertToBytes(string $size): int
  {
    $size = trim($size);
    return (int)$size * match (strtolower(substr($size, -1))) {
      'g' => 1024 ** 3,
      'm' => 1024 ** 2,
      'k' => 1024,
      default => 1
    };
  }

  // Clean the username for use in filesystem operations
  public static function clean_username(string $username): string
  {
    // TODO: Are there other characters we should allow? Spaces?
    return preg_replace('/[^[:alnum:]\-_]/', '', $username);
  }
}
