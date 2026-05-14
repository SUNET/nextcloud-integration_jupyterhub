<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OCA\Jupyter\Db\WebappShare;

/**
 * Value object describing which webapp view targets a remote OCM peer
 * accepts when receiving a webapp share.
 *
 * The OCM webapp draft is unsettled about whether the target (iframe /
 * redirect / new-window) is advertised in capabilities or in protocols —
 * see https://github.com/cs3org/OCM-API/blob/develop/work/webapps/webapp-sharing.md
 * We therefore keep a single value-object that {@see DiscoveryService}
 * populates and that callers consult.
 */
final class WebappTargets
{
  public function __construct(
    public readonly bool $iframe,
    public readonly bool $redirect,
    public readonly bool $newWindow,
  ) {
  }

  public static function iframeOnly(): self
  {
    return new self(iframe: true, redirect: false, newWindow: false);
  }

  public static function all(): self
  {
    return new self(iframe: true, redirect: true, newWindow: true);
  }

  public function supports(string $viewMode): bool
  {
    return match ($viewMode) {
      WebappShare::VIEW_IFRAME => $this->iframe,
      WebappShare::VIEW_REDIRECT => $this->redirect,
      WebappShare::VIEW_NEW_WINDOW => $this->newWindow,
      default => false,
    };
  }

  /**
   * Pick the requested mode if supported, otherwise fall back to the
   * first supported mode (preferring iframe).
   */
  public function pickPreferred(string $requested): ?string
  {
    if ($this->supports($requested)) {
      return $requested;
    }
    if ($this->iframe) {
      return WebappShare::VIEW_IFRAME;
    }
    if ($this->redirect) {
      return WebappShare::VIEW_REDIRECT;
    }
    if ($this->newWindow) {
      return WebappShare::VIEW_NEW_WINDOW;
    }
    return null;
  }
}
