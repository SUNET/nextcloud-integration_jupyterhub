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
  public function __construct(
    IRequest $request,
    private IConfig $config,
  ) {
    parent::__construct(Application::APP_ID, $request);
  }

  public function update(?string $jupyter_url = null, ?bool $webapp_sharing_enabled = null): DataResponse
  {
    if ($jupyter_url !== null) {
      $jupyter_url = rtrim($jupyter_url, '/');
      $this->config->setAppValue(Application::APP_ID, 'jupyter_url', $jupyter_url);
    }
    if ($webapp_sharing_enabled !== null) {
      $this->config->setAppValue(Application::APP_ID, 'webapp_sharing_enabled', $webapp_sharing_enabled ? 'yes' : 'no');
    }
    return new DataResponse(['status' => 'success']);
  }
}
