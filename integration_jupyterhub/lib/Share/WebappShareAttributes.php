<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Share;

use OCP\Share\IAttributes;

/**
 * Minimal {@see IAttributes} value object.
 *
 * NC's concrete `OC\Share20\ShareAttributes` is in the private namespace, so
 * we implement the public interface directly to keep this app off the
 * private API. The behaviour mirrors NC's serialisation: scope+key tuples
 * map to bool/string/array/null values, and `toArray()` returns a flat list
 * of `{scope, key, value}` rows ready for the share provider to persist.
 */
class WebappShareAttributes implements IAttributes
{
  /** @var array<string, array<string, bool|string|array|null>> */
  private array $byScope = [];

  /**
   * @param list<array{scope:string, key:string, value: bool|string|array|null}> $rows
   */
  public static function fromArray(array $rows): self
  {
    $attrs = new self();
    foreach ($rows as $row) {
      if (!is_array($row) || !isset($row['scope'], $row['key'])) {
        continue;
      }
      $attrs->setAttribute((string)$row['scope'], (string)$row['key'], $row['value'] ?? null);
    }
    return $attrs;
  }

  #[\Override]
  public function setAttribute(string $scope, string $key, mixed $value): IAttributes
  {
    $this->byScope[$scope][$key] = $value;
    return $this;
  }

  #[\Override]
  public function getAttribute(string $scope, string $key): mixed
  {
    return $this->byScope[$scope][$key] ?? null;
  }

  #[\Override]
  public function toArray(): array
  {
    $out = [];
    foreach ($this->byScope as $scope => $keys) {
      foreach ($keys as $key => $value) {
        $out[] = ['scope' => $scope, 'key' => $key, 'value' => $value];
      }
    }
    return $out;
  }
}
