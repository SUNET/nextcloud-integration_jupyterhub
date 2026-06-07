<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Settings;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappCapabilityDiscovery;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
  public function __construct(
    private IInitialState $initialStateService,
    private IConfig $config,
  ) {
  }

  /**
   * @return TemplateResponse
   */
  public function getForm(): TemplateResponse
  {
    $jupyterUrl = $this->config->getAppValue(Application::APP_ID, 'jupyter_url');
    $webappSharingEnabled = $this->config->getAppValue(Application::APP_ID, 'webapp_sharing_enabled', 'no') === 'yes';
    $allowedTargetsRaw = $this->config->getAppValue(Application::APP_ID, 'webapp_allowed_targets', '');
    $allowedTargets = WebappCapabilityDiscovery::normaliseTargets(
      $allowedTargetsRaw === '' ? [] : explode(',', $allowedTargetsRaw),
    );
    // Unset config = every supported target allowed (matches the
    // sender's fallback) so the checkboxes start fully ticked.
    if ($allowedTargets === []) {
      $allowedTargets = WebappCapabilityDiscovery::ALL_TARGETS;
    }
    $accessTokenTtl = (int)$this->config->getAppValue(Application::APP_ID, 'ocm_access_token_ttl', '3600');
    $this->initialStateService->provideInitialState('jupyter_url', $jupyterUrl);
    $this->initialStateService->provideInitialState('webapp_sharing_enabled', $webappSharingEnabled);
    $this->initialStateService->provideInitialState('webapp_allowed_targets', $allowedTargets);
    $this->initialStateService->provideInitialState('ocm_access_token_ttl', $accessTokenTtl);
    return new TemplateResponse(Application::APP_ID, 'adminSettings');
  }

  public function getSection(): string
  {
    return 'additional';
  }

  public function getPriority(): int
  {
    return 20;
  }
}
