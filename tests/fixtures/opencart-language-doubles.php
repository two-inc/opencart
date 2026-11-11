<?php

declare(strict_types=1);

/*
 * Offline stand-ins for OpenCart 4's Config, Loader and Language, enough to
 * render a merchant note from the extension's own language files. Loaded by
 * tests/bootstrap.php only, under the real class names, like the DB double.
 */

namespace Opencart\System\Engine {
    final class Config
    {
        /** @var array<string, mixed> */
        private array $data = [];

        public function get(string $key): mixed
        {
            return $this->data[$key] ?? '';
        }

        public function set(string $key, mixed $value): void
        {
            $this->data[$key] = $value;
        }
    }

    /** Without OpenCart's language events: their second load reads the same file here. */
    final class Loader
    {
        public function __construct(private \Opencart\System\Library\Language $language)
        {
        }

        /** @return array<string, string> */
        public function language(string $route, string $prefix = '', string $code = ''): array
        {
            return $this->language->load($route, $prefix, $code);
        }
    }
}

namespace Opencart\System\Library {
    final class Language
    {
        /** @var array<string, string> */
        private array $data = [];

        /** $directory is a side's language folder, as OpenCart maps `extension/two` to the extension's. */
        public function __construct(private string $code, private string $directory)
        {
        }

        public function get(string $key): string
        {
            return $this->data[$key] ?? $key;
        }

        public function set(string $key, string $value): void
        {
            $this->data[$key] = $value;
        }

        /** @return array<string, string> */
        public function load(string $filename, string $prefix = '', string $code = ''): array
        {
            $file = $this->directory . ($code ?: $this->code) . '/' . preg_replace('~^extension/two/~', '', $filename) . '.php';
            foreach (is_file($file) ? self::read($file) : [] as $key => $value) {
                $this->data[($prefix ? $prefix . '_' : '') . $key] = $value;
            }

            return $this->data;
        }

        /** @return array<string, string> */
        private static function read(string $file): array
        {
            $_ = [];
            require $file;

            return $_;
        }
    }
}
