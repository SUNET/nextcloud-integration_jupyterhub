<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
  'routes' => [
    ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'config#update', 'url' => '/config', 'verb' => 'PUT'],
    ['name' => 'page#ocmOpen', 'url' => '/ocm/open/{token}', 'verb' => 'GET'],
    ['name' => 'page#hasNotebooks', 'url' => '/api/v1/has-notebooks', 'verb' => 'GET'],
    ['name' => 'webappShare#create', 'url' => '/api/v1/webapp-share', 'verb' => 'POST'],
    ['name' => 'webappShare#listSent', 'url' => '/api/v1/webapp-share/sent', 'verb' => 'GET'],
    ['name' => 'webappShare#listReceived', 'url' => '/api/v1/webapp-share/received', 'verb' => 'GET'],
  ],
];
