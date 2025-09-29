<?php
// Advanced layered env loader supporting root .env plus config/env.d/*.env fragments.
// Precedence: 1) Base .env (if exists) then 2) Each env.d file in alpha order (later CAN override) unless key already set in process environment.
if (!function_exists('educaid_parse_env_file')) {
    function educaid_parse_env_file(string $file): array {
        $vars = [];
        if (!is_readable($file)) return $vars;
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            // Strip inline comment (space + #) but not if value is quoted and # inside quotes
            if (preg_match('/(^[^#=]+)=(["\'])(.*)\2\s+#/',$line,$m)) {
                // quoted with comment after – handle below normally
            } elseif (preg_match('/\s+#/',$line)) {
                // naive split for unquoted values
                [$pre] = preg_split('/\s+#/',$line,2);
                $line = $pre;
            }
            $pos = strpos($line,'=');
            if ($pos === false) continue;
            $key = trim(substr($line,0,$pos));
            $value = trim(substr($line,$pos+1));
            if ($value === '') $value = '';
            $quote = $value[0] ?? '';
            if (($quote === '"' || $quote === "'") && substr($value,-1) === $quote) {
                $value = substr($value,1,-1);
            }
            // Expand ${VAR}
            $value = preg_replace_callback('/\$\{?([A-Z0-9_]+)\}?/i', function($m){
                return $_ENV[$m[1]] ?? getenv($m[1]) ?? '';
            }, $value);
            $vars[$key] = $value;
        }
        return $vars;
    }
}

if (!function_exists('educaid_load_env_layered')) {
    function educaid_load_env_layered(string $projectRoot): void {
        $loaded = [];
        $base = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        if (is_readable($base)) {
            $loaded['.env'] = educaid_parse_env_file($base);
        }
        $envDir = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.d';
        if (is_dir($envDir)) {
            $files = glob($envDir . DIRECTORY_SEPARATOR . '*.env');
            sort($files, SORT_STRING | SORT_FLAG_CASE);
            foreach ($files as $file) {
                $loaded[basename($file)] = educaid_parse_env_file($file);
            }
        }
        // Apply variables preserving already-set real environment (allow external container overrides)
        foreach ($loaded as $source => $vars) {
            foreach ($vars as $k => $v) {
                if (getenv($k) !== false) continue; // respect existing system env
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
        }
    }
}

educaid_load_env_layered(dirname(__DIR__));
?>
