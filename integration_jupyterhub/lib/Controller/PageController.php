<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Controller;

use OCA\Jupyter\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;

class PageController extends Controller
{
  public function __construct(
    IRequest $request,
    private IConfig $config,
  ) {
    parent::__construct(Application::APP_ID, $request);
  }

  /**
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function index(): TemplateResponse
  {
    $jupyterUrl = $this->config->getAppValue(Application::APP_ID, 'jupyter_url');
    return new TemplateResponse(Application::APP_ID, 'main', [
      'jupyter_url' => $jupyterUrl !== '' ? rtrim($jupyterUrl, '/') . '/hub/home' : '',
    ]);
  }
}
