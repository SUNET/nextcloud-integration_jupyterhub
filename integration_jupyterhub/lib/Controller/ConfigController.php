<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class ConfigController extends Controller
{
  private IConfig $config;

  public function __construct(
    IRequest $request,
    IConfig $config,
  ) {
    parent::__construct(Application::APP_ID, $request);
    $this->config = $config;
  }

  public function update(string $jupyter_url): DataResponse
  {
    $jupyter_url = rtrim($jupyter_url, '/');
    $this->config->setAppValue(Application::APP_ID, 'jupyter_url', $jupyter_url);
    return new DataResponse(['status' => 'success']);
  }
}
