<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

/**
 * Raised when the back-channel push to the paired JupyterHub fails. The
 * webapp share must not be delivered to the recipient OCM server without
 * the hub first acknowledging the share envelope, so this aborts the send.
 */
class OCMBackChannelException extends \RuntimeException
{
}
