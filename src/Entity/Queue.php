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

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'queue')]
class Queue
{
  #[ORM\Id]
  #[ORM\OneToOne(targetEntity: Asset::class, cascade: ['persist'])]
  #[ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', nullable: false)]
  private ?Asset $asset = null;

  #[ORM\Column(type: 'string', length: 255)]
  private ?string $email = null;

  #[ORM\Column(type: 'text', length: 65535, nullable: true)]
  private ?string $details = null;

  #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => false])]
  private bool $change_requested = false;

  /*public function getAssetId(): ?int {
    return $this->asset_id;
  }

  public function setAssetId(?int $asset_id): self {
    $this->asset_id = $asset_id;
    return $this;
  }*/

  public function getAsset(): ?Asset
  {
    return $this->asset;
  }

  public function setAsset(?Asset $asset): self
  {
    $this->asset = $asset;
    return $this;
  }

  public function getEmail(): ?string
  {
    return $this->email;
  }

  public function setEmail(?string $email): self
  {
    $this->email = $email;
    return $this;
  }

  public function getDetails(): ?string
  {
    return $this->details;
  }

  public function setDetails(?string $details): self
  {
    $this->details = $details;
    return $this;
  }

  public function isChangeRequested(): bool
  {
    return $this->change_requested;
  }

  public function setChangeRequested(bool $change_requested): self
  {
    $this->change_requested = $change_requested;
    return $this;
  }
}
