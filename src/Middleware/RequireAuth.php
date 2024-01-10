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

use League\Config\Configuration;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use ReflectionClass;
use Slim\App;
use Slim\Routing\RouteContext;

class RequireAuth
{
  public function __construct(protected App $app, protected Configuration $config)
  {
  }

  public function __invoke(Request $request, RequestHandler $handler): Response
  {
    // Get route information
    $route_context = RouteContext::fromRequest($request);
    $route = $route_context->getRoute();

    // Let the 'home', 'login', and 'logout' pages process without any extra checking
    if (!empty($route) && in_array($route->getName(), ['home', 'login', 'logout'], true)) {
      return $handler->handle($request);
    }

    // Track if we should return a JSON error
    $use_json = false;

    // Fails safe to requiring an authenticated administrator
    $needs_auth = $needs_admin = true;

    // Get the callable for this route and, through reflection, get the attributes for the method
    $callable = $route->getCallable();
    $reflection_class = new ReflectionClass($callable[0]);
    $reflection_method = $reflection_class->getMethod($callable[1]);
    $attributes = $reflection_method->getAttributes();

    // Look through the attributes and process AuthRequirement and JSONResponse
    foreach($attributes as $attribute) {
      if ($attribute->getName() === 'App\Attribute\AuthRequirement') {
        $instance = $attribute->newInstance();
        $needs_auth = $instance->needs_auth;
        $needs_admin = $instance->needs_admin;
      } elseif ($attribute->getName() === 'App\Attribute\JSONResponse') {
        $use_json = true;
      }
    }

    /*
    print_r(compact('use_json', 'needs_auth', 'needs_admin'));
    exit;
    */


    // Check if we have a valid session
    $valid_session =
      // Must have a bzid, username, and clean_username set
      !empty($_SESSION['bzid']) && !empty($_SESSION['username']) && !empty($_SESSION['clean_username']) &&

      // Check if last activity exceeds our limit
      // TODO: Should we check this here or just rely on PHP's own handling?

      // Check if overall session duration exceeds our limit
      // TODO: Should we bother with a max duration?

      // Check if the user agent changed
      ($_SESSION['user_agent'] ?? '') === $_SERVER['HTTP_USER_AGENT']
    ;

    // If the session is not valid, check if the user can view this page
    if (!$valid_session) {
      // TODO: Should we do this?
      $_SESSION = [];

      // If the page needs auth, redirect or send an error
      if ($needs_auth) {
        if ($use_json) {
          $response = new Response();
          $response->getBody()->write(json_encode([
            'success' => 'false',
            'errors' => [
              'This request requires logging in and the session is invalid or expired.'
            ]
          ]));
          return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(403);
        } else {
          return (new Response())
            ->withHeader('Location', $this->app->getRouteCollector()->getRouteParser()->urlFor('home'))
            ->withStatus(302);
        }
      }
    } else {
      // Update the session last used time
      $_SESSION['when_last_used'] = time();

      // If this page requires admin rights and the user isn't an admin...
      if ($needs_admin && $_SESSION['is_admin'] !== true) {
        $response = new Response();
        if ($use_json) {
          $response->getBody()->write(json_encode([
            'success' => 'false',
            'errors' => [
              'This request requires administrative rights.'
            ]
          ]));
          return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(403);
        } else {
          // TODO: Figure out if we can/should show an error page here instead
          return (new Response())
            ->withHeader('Location', $this->app->getRouteCollector()->getRouteParser()->urlFor('home'))
            ->withStatus(302);
        }
      }
    }

    // If we made it this far, show the request
    return $handler->handle($request);
  }
}
