<?php

class Modules {
    private static $loaded_classes = [];
    private static $autorun_classes = [];

    public static function enable($modules = []) {
        $autorun_classes = [];
        $provide_classes = [];

        if (!isset($GLOBALS[KYUTILS_PROVIDE_KEY]) || !is_array($GLOBALS[KYUTILS_PROVIDE_KEY])) {
            $GLOBALS[KYUTILS_PROVIDE_KEY] = [];
        }

        $cacheDir = KYUTILS_PATH . '/cache';
        $cacheFile = $cacheDir . '/scripts.json';
        $cacheData = [];

        if (file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            if (!is_array($cacheData)) {
                $cacheData = [];
            }
        }

        if (empty($modules)) {
            $modules = self::discoverModules();
        }

        if (is_string($modules)) {
            $modules = [$modules];
        }

        $cacheUpdated = false;

        foreach ($modules as $module) {
            $moduleName = ucfirst(strtolower($module));
            $file = __DIR__ . "/modules/{$moduleName}.php";

            if (!file_exists($file)) {
                $file = __DIR__ . "/{$moduleName}.php";
            }

            if (file_exists($file)) {
                $mtime = filemtime($file);
                $is_checked = isset($cacheData[$file]) && $cacheData[$file] === $mtime;

                if (!$is_checked) {
                    $syntax_check_output = shell_exec("php -l " . escapeshellarg($file));
                    if (strpos($syntax_check_output, 'No syntax errors detected') === false) {
                        error_log("Spectro-utils: Syntax error skipped in module {$moduleName}: " . trim($syntax_check_output));
                        if (isset($cacheData[$file])) {
                            unset($cacheData[$file]);
                            $cacheUpdated = true;
                        }
                        continue;
                    }
                    $cacheData[$file] = $mtime;
                    $cacheUpdated = true;
                }

                try {
                    require_once $file;
                    if (class_exists($moduleName)) {
                        if (method_exists($moduleName, 'autorun')) {
                            $autorun_classes[] = $moduleName;
                        }
                        if (method_exists($moduleName, 'provide')) {
                            $provide_classes[] = $moduleName;
                        }
                        self::$loaded_classes[] = $moduleName;
                    }
                } catch (Exception $e) {
                    error_log("Spectro-utils: Runtime error skipped in module {$moduleName}: " . $e->getMessage());
                    if (isset($cacheData[$file])) {
                        unset($cacheData[$file]);
                        $cacheUpdated = true;
                    }
                }
            } else {
                throw new Exception("Module {$moduleName} not found");
            }
        }

        if ($cacheUpdated) {
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0777, true);
            }
            file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT));
        }

        foreach ($provide_classes as $class) {
            $provided_data = $class::provide();
            if (is_array($provided_data)) {
                foreach ($provided_data as $key => $value) {
                    $GLOBALS[KYUTILS_PROVIDE_KEY][$key] = $value;
                }
            }
        }

        self::$autorun_classes = $autorun_classes;

        define('SPECTRO_MODULES', count(self::$loaded_classes));
    }

    private static function discoverModules() {
        $modules = [];
        $moduleFiles = glob(__DIR__ . '/modules/*.php');
        foreach ($moduleFiles as $file) {
            $modules[] = basename($file, '.php');
        }
        return $modules;
    }

    public static function autorun() {
        foreach (self::$autorun_classes as $class) {
            $class::autorun();
        }
    }
}
