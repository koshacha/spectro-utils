<?php

require_once KYUTILS_PATH . '/libs/smarty-3/libs/bootstrap.php';

class Template {
    private static $templates = [];
    private static $template = "";
    public static $s;

    public static function autorun() {
        $s = new Smarty();

        $s->setTemplateDir(KYUTILS_PATH . '/html');
        $s->setCacheDir(KYUTILS_PATH . '/cache/smarty/cache');
        $s->setCompileDir(KYUTILS_PATH . '/cache/smarty/templates_c');

        $s->addPluginsDir(KYUTILS_PATH . '/assets/smarty_plugins');

        self::$s = $s;
    }

    public static function provide() {
        if (empty(self::$s)) {
            self::autorun();
        }
        return [
            's' => self::$s
        ];
    }

    public static function useTemplates($filename) {
        $templates = [];
        $fileContent = file_get_contents($filename);
      
        self::$template = $fileContent;
        
        $pattern = '/<!--\s*\$template\s*([\'"])(.*?)\1\s*-->\s*(.*?)(?=<!--\s*\$template\s*|\z)/s';
      
        preg_match_all($pattern, $fileContent, $matches, PREG_SET_ORDER);
      
        foreach ($matches as $match) {
            $name = trim($match[2]);
            $content = trim($match[3]);
            $templates[$name] = $content;
        }

        self::$templates = $templates;
      
        return $templates;
    }

    public static function assert($fields, $template = null) {
        if (empty(self::$s)) {
            self::autorun();
        } else {
            self::$s->clearAllAssign();
        }

        if ($template === null && !array_key_exists('$template', $fields)) {
            $html = self::$template;
        } else {
            if (isset($fields['$template'])) {
                $template = $fields['$template'];
            } else {
                $fields['$template'] = $template;
            }
    
            if (!$template) {
                throw new \Exception("Template not specified");
            }
    
            if (!array_key_exists($template, self::$templates)) {
                throw new \Exception("Template $template not found");
            }
    
            $html = self::$templates[$template];
        }

        $smarty_context = [];
        foreach($fields as $k => $v) {
            if ($k === '$template') continue;

            if (is_array($v)) {
                if (isset($v['$items'])) {
                    $items_html = '';
                    if(isset($v['$items'])){
                        foreach ($v['$items'] as $item) {
                             if (is_array($item) && isset($item['$template'])) {
                                 $items_html .= self::assert($item, $item['$template']);
                             }
                             elseif (is_array($item)) {
                                $items_html .= self::assert($item, $v['$template']);
                             } else {
                                $items_html .= (string)$item;
                             }
                        }
                    }
                    $smarty_context[$k] = $items_html;
                } elseif (isset($v['$template'])) {
                    $smarty_context[$k] = self::assert($v, $v['$template']);
                } else {
                    $smarty_context[$k] = $v;
                }
            } else {
                $smarty_context[$k] = $v;
            }
        }

        self::$s->assign($smarty_context);
        return self::$s->fetch('string:' . $html);
    }
}

if (!function_exists('template')) {
    function template($name, $dir = IMGPATH) {
        $filename = str_replace('.html', '', $name) . '.html';
        $path = $dir . '/views/' . $filename;
        $path = str_replace('//', '/', $path);

        if (!file_exists($path)) return null;

        return Template::useTemplates($path);
    }
}

if (!function_exists('view')) {
    function view($html, $data = []) {
        $context = Template::provide();
        $s = $context['s'];

        $s->clearAllAssign();
        $s->assign($data);

        return $s->fetch('string:' . $html);
    }
}

