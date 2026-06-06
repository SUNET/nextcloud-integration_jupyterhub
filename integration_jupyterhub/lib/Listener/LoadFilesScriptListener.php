<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Jupyter\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Util;

/**
 * Loads our Files-app sender file-action script whenever the Files app
 * renders — but only when webapp sharing is enabled.
 *
 * @implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesScriptListener implements IEventListener
{
  public function __construct(private IConfig $config)
  {
  }

  public function handle(Event $event): void
  {
    if (!($event instanceof LoadAdditionalScriptsEvent)) {
      return;
    }
    if ($this->config->getAppValue(Application::APP_ID, 'webapp_sharing_enabled', 'no') !== 'yes') {
      return;
    }
    Util::addScript(Application::APP_ID, Application::APP_ID . '-files');
  }
}
