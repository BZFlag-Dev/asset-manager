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

namespace App\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
class SecurityHeaders
{
  public function __invoke(Request $request, RequestHandler $handler): Response
  {
    return $handler->handle($request)
      ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src-attr 'unsafe-inline'; img-src 'self' data:");
  }
}
