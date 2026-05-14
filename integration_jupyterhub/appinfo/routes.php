<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
  'routes' => [
    ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'page#ocmOpen', 'url' => '/ocm/open/{token}', 'verb' => 'GET'],
    ['name' => 'config#update', 'url' => '/config', 'verb' => 'PUT'],
    ['name' => 'webappShare#create', 'url' => '/api/v1/webapp-share', 'verb' => 'POST'],
    ['name' => 'receivedShare#showAt', 'url' => '/api/v1/webapp-share/at', 'verb' => 'GET'],
  ],
];
