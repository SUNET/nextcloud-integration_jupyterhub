<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

require_once __DIR__ . '/integration_jupyterhub/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->exclude(['build', 'js', 'l10n', 'node_modules', 'src', 'vendor'])
	->in(__DIR__ . '/integration_jupyterhub');
return $config;
