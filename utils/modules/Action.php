<?php

require_once KYUTILS_PATH . '/modules/Request.php';
require_once KYUTILS_PATH . '/exceptions/SpectroError.php';

class Action {
    private static $actions = [];
    private static $data = "";

    public static function handle($name, $callback) {
        self::$actions[$name] = $callback;
    }

    private static function renderAnswer($data) {
        $GLOBALS['actionresult'] = $data['$result'];

        $text = isset($data['$json']) ? json_encode($data['$json']) : $data['$text'];

        if (isset($data['$json'])) {
            header('Content-Type: application/json');
        }

        $GLOBALS['data'] = $text;
        return;
    }

    public static function exec() {
        $action = $GLOBALS['action'];
        if (isset(self::$actions[$action])) {
            $callback = self::$actions[$action];

            if (is_string($callback) && strpos($callback, '::') !== false) {
                list($class, $method) = explode('::', $callback, 2);
                $reflection = new ReflectionMethod($class, $method);
            } elseif (is_array($callback) && count($callback) === 2 && is_object($callback[0])) {
                $reflection = new ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_string($callback) && function_exists($callback)) {
                $reflection = new ReflectionFunction($callback);
            } elseif (is_string($callback) && !function_exists($callback)) {
                $GLOBALS['data'] = $callback;
                $GLOBALS['dosomething'] = 1;
                return;
            } elseif (is_object($callback) && ($callback instanceof Closure)) {
                $reflection = new ReflectionFunction($callback);
            } else {
                trigger_error("Invalid callback format.", E_USER_WARNING);
                return;
            }
            $GLOBALS['actiondone'] = 1;
            
            $parameters = $reflection->getParameters();
            $args = [];

            $req = Request::get();
            $files = Request::files();

            foreach ($parameters as $parameter) {
                $name = $parameter->getName();
                $value = null;
                $index = null;

                if ($name === 'request') {
                    $value = $req;
                } elseif ($name === 'files') {
                    $value = $files;
                } elseif (array_key_exists($name, $req)) {
                    $value = $req[$name];
                }

                if ($value === null && array_key_exists($name, $GLOBALS)) {
                  $value = $GLOBALS[$name];
                }
    
                if ($value === null) {
                  if ($parameter->isOptional()) {
                      try {
                        $value = $parameter->getDefaultValue();
                      } catch (ReflectionException $e) {
                          
                      }
                  } else {
                    // trigger_error("Missing argument: " . $name, E_USER_WARNING);
                    // return;
                  }
                }
    
                if($index !== null) {
                //   unset($matches[$index]);
                }
    
                $args[] = $value;
            }

            try {
                self::$data = $reflection->invokeArgs($args);
                // Для методов объекта:
                // self::$data = $reflection->invokeArgs($callback[0], $args);

                if (is_array(self::$data)) {
                    $temp = self::$data;

                    self::$data = [
                        '$result' => 0,
                        '$json' => $temp
                    ];
                } else {
                    $temp = self::$data;

                    self::$data = [
                        '$result' => 0,
                        '$text' => $temp
                    ];
                }
            } catch (SpectroError $e) {
                self::$data = [
                    '$result' => $e->getCode(),
                ];
                $exc = $e->getDecodedMessage();
                $key = is_array($exc) ? '$json' : '$text';
                self::$data[$key] = $exc;
            }

            self::renderAnswer(self::$data);
        }
    }
}