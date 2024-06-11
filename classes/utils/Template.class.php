<?php

/*
 * FileSender www.filesender.org
 *
 * Copyright (c) 2009-2012, AARNet, Belnet, HEAnet, SURFnet, UNINETT
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * *    Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 * *    Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in the
 *     documentation and/or other materials provided with the distribution.
 * *    Neither the name of AARNet, Belnet, HEAnet, SURFnet and UNINETT nor the
 *     names of its contributors may be used to endorse or promote products
 *     derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

// Require environment (fatal)
if (!defined('FILESENDER_BASE')) {
    die('Missing environment');
}

/**
 * Template managment class (resolve, parse, render)
 */
class Template
{
    private static function resolve_addPossibleLocation(&$possibleLocations,$rpath)
    {
        $base = FILESENDER_BASE;
        $themeName = Config::get('theme');
        if(strlen($themeName)) {
            array_push( $possibleLocations, $base.$rpath.'/'.$themeName );
        }
        array_push( $possibleLocations, $base.$rpath );
    }
    /**
     * Resolve template id to path
     *
     * @param string $id template id
     *
     * @return string the path
     */
    private static function resolve($id)
    {
        // Create a list of possible locations
        $possibleLocations = array();
        self::resolve_addPossibleLocation( $possibleLocations, '/'.'config/templates' );
        self::resolve_addPossibleLocation( $possibleLocations, '/'.'templates' );

        // Look in possible locations
        foreach ($possibleLocations as $location) {

            if (!is_dir($location)) {
                continue;
            }
            
            // Return if found
            $fullPath = $location.'/'.$id.'.php';
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }
        
        // Fail if not found
        throw new TemplateNotFoundException($id);
    }
    
    /**
     * Process a template (catch displayed content)
     *
     * @param string $id template id
     * @param array $vars template variables
     *
     * @return string parsed template content
     */
    public static function process($id, $vars = array())
    {
        // Are we asked to not output context related html comments ?
        $addctx = true;
        if (substr($id, 0, 1) == '!') {
            $addctx = false;
            $id = substr($id, 1);
        }
        
        $important = false;
        if (substr($id, 0, 1) == '!') {
            $important = true;
            $id = substr($id, 1);
        }
        
        // Resolve template file path
        $path = self::resolve($id);
        
        $cache_path = FILESENDER_BASE.'/cache/templates';
        if(!is_dir($cache_path) && !mkdir($cache_path, 0755, true))
            throw new DetailedException('failed_to_create_template_cache');

        $code_stack = Lang::getCodeStack();
        $id = str_replace('.php', '', basename($path));
        $id .= '-'.$code_stack['main'].($code_stack['fallback'] ? '-'.implode('-', $code_stack['fallback']) : '');
        $cache_path .= '/'.$id.'.php';

        if(!array_key_exists('tr_id', $_SESSION))
            $_SESSION['tr_id'] = substr(Utilities::generateUID(), -8);

        if(!file_exists($cache_path) || filemtime($cache_path) <= max(filemtime(FILESENDER_BASE.'/config/config.php'), filemtime($path))) {
            // Cache non-existent or outdated, regenerate
            $content = file_get_contents($path);

            // Run config dependent replaces to avoid user injection

            // Config syntax
            $content = preg_replace_callback('`\{(cfg|conf|config):([^}]+)\}`', function ($m) {
                return Utilities::sanitizeOutput(Config::get($m[2]));
            }, $content);

            // Image syntax
            $content = preg_replace_callback('`\{(img|image):([^}]+)\}`', function ($m) {
                return Utilities::sanitizeOutput(GUI::path('res/images/'.$m[2]));
            }, $content);

            // Path syntax
            $content = preg_replace_callback('`\{(path):([^}]*)\}`', function ($m) {
                return Utilities::sanitizeOutput(GUI::path($m[2]));
            }, $content);

            // Translation syntax to session tied unguessable translation call
            $content = preg_replace_callback('`\{(loc|tr|translate):([^}]+)\}`', function ($m) {
                return '{tr'.$_SESSION['tr_id'].':'.$m[2].'}';
            }, $content);

            // Relocate relative includes
            $dir = dirname($path);
            $content = preg_replace('`((?:include|require)(?:_once)?)\s*\(?(.+)\s*\)?\s*;`', '$1("'.$dir.'/".$2);', $content);

            // Stash template in cache
            if(!file_put_contents($cache_path, $content))
                throw new DetailedException('failed_to_save_template_cache');

            $path = $cache_path;
        }

        // Lambda renderer to isolate context
        $renderer = function ($path, $vars) {
            foreach ($vars as $k => $v) {
                if ((substr($k, 0, 1) != '_') && ($k != 'path')) {
                    $$k = $v;
                }
            }
            include $path;
        };
        
        // Render
        $exception = null;
        ob_start();
        try {
            $renderer($path, $vars);
        } catch (Exception $e) {
            $exception = $e;
        }
        $content = ob_get_clean();

        // Session tied unguessable translation call resolver
        $content = preg_replace_callback('`\{tr'.$_SESSION['tr_id'].':([^}]+)\}`', function ($m) {
            return Lang::tr(trim($m[1]));
        }, $content);
        
        // Add context as a html comment if required
        if ($addctx) {
            $content = "\n".'<!-- template:'.$id.' start -->'."\n".$content."\n".'<!-- template:'.$id.' end -->'."\n";
        }
        
        if ($important && $exception) {
            return (object)array('content' => $content, 'exception' => $exception);
        }
        
        // If rendering threw rethrow
        if ($exception) {
            throw $exception;
        }
        
        return $content;
    }
    
    /**
     * Sanitize data to avoid tag replacement
     *
     * @param mixed data
     *
     * @return string
     *
     */
    public static function sanitize($data)
    {
        return str_replace(array('{', '}'), array('&#123;', '&#125;'), $data);
    }
    
    /**
     * Sanitize data to avoid tag replacement.
     *
     * This differs from Utilities::sanitizeOutput because we also escape
     * the { and } characters to HTML entities.
     *
     * @param mixed data
     *
     * @return string
     *
     */
    public static function sanitizeOutput($data)
    {
        return self::sanitize(Utilities::sanitizeOutput($data));
    }

    /**
     * Sanitize data to avoid tag replacement for email addresses
     *
     * @param mixed data
     *
     * @return string
     *
     */
    public static function sanitizeOutputEmail($data)
    {
        return self::sanitize(Utilities::sanitizeOutput($data));
    }
    

    /**
     * Display a template (catch displayed content)
     *
     * @param string $id template id
     * @param array $vars template variables
     *
     * @return string parsed template content
     */
    public static function display($id, $vars = array())
    {
        $content = self::process($id, $vars);
        
        if (is_object($content)) {
            echo $content->content;
            throw $content->exception;
        }
        
        echo $content;
    }
}
