<?php
/**
 * PSR4 compliant autoloader
 *
 * Adapted from https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader-examples.md
 *
 * Copyright (C) 2013-2017 PHP Framework Interop Group
 * Copyright (C) 2023  FusionDirectory
 * SPDX-License-Identifier: MIT
 *
 * @param string $class The fully-qualified class name.
 */
spl_autoload_register(function (string $class): void {
  // project-specific namespace prefix
  $prefix = 'FusionDirectory\\Tools\\';

  // base directory for the namespace prefix
  $base_dir = __DIR__ . '/';

  // does the class use the namespace prefix?
  $len = strlen($prefix);
  if (strncmp($prefix, $class, $len) !== 0) {
    // no, move to the next registered autoloader
    return;
  }

  // get the relative class name
  $relative_class = substr($class, $len);

  // replace the namespace prefix with the base directory, replace namespace
  // separators with directory separators in the relative class name, append
  // with .php
  $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

  // if the file exists, require it
  if (file_exists($file)) {
    require $file;
  }
});
