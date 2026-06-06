<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
  'routes' => [
    ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ['name' => 'config#update', 'url' => '/config', 'verb' => 'PUT'],
    ['name' => 'webappShare#create', 'url' => '/api/v1/webapp-share', 'verb' => 'POST'],
    ['name' => 'webappShare#check', 'url' => '/api/v1/webapp-share/check', 'verb' => 'GET'],
  ],
];
