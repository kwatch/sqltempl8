<?php // -*- coding: utf-8 -*-

///
/// $Release: 0.0.0 $
/// $Copyright: copyright(c) 2015 kuwata-lab.com all rights reserved $
/// $License: MIT License $
///

///
/// sqltempl8.php -- SQL template engine to prevent SQL Injection (almost) perfectly.
///
/// Example (books.sql):
///
///     -- -*- coding: utf-8 -*-
///     /* List of books */
///
///     select *
///     from books
///     where true
///       -- #if :title_pattern
///       and title like :title_pattern
///       -- #end
///       -- #if :publisher_id
///       and publisher_id = :publisher_id
///       -- #end
///     order by title
///
/// Example (ex1.php):
///
///     require_once 'sqltempl8.php';
///     $sqlt = new SQLTempl8('books.sql');
///     $vars = array('title_pattern'=>'%SQL%');
///     $sql = $sqlt->render($vars);
///     echo $sql;
///
/// Result:
///
///     -- file: books.sql
///     select *
///     from books
///     where true
///       and title like :title_pattern
///     order by title
///


class SQLTempl8Error extends Exception {
}


class SQLTempl8 {

    private $path;

    public function __construct($filepath=null) {
        if ($filepath) {
            $this->load_file($filepath);
        }
    }

    public function load_file($filepath) {
        if (! file_exists($filepath)) {
            throw $this->error("$filepath: file not exist.");
        }
        $this->path = $filepath;
        $mtime = filemtime($filepath);
        if (! $this->is_cache_available($mtime)) {
            for ($i = 0; $i < 2; $i++) {
                $template_str = file_get_contents($filepath);
                $php_code = $this->parse($template_str);
                $this->create_cache_file($php_code, $mtime);
                if ($mtime === filemtime($filepath)) break;
                /// retry when timestamp changed during creating cache file
                $mtime = filemtime($filepath);
            }
            if ($i === 2) {
                throw $this->error("$filepath: timestamp changed too frequently.");
            }
        }
        return $this;
    }

    protected function get_cache_path() {
        return $this->path . '.cache';
    }

    protected function is_cache_available($mtime) {
        $cpath = $this->get_cache_path();
        return file_exists($cpath) && filemtime($cpath) === $mtime;
    }

    protected function create_cache_file($php_code, $mtime) {
        $cpath = $this->get_cache_path();
        $tmpfile = $cpath . "." . rand(10000, 99999);
        file_put_contents($tmpfile, $php_code);
        touch($tmpfile, $mtime);
        rename($tmpfile, $cpath);
    }

    public function parse($template_str) {
        list($header, $body) = explode("\n\n", $template_str, 2);
        $php_code = $this->convert($body);
        return $php_code;
    }

    public function convert($body) {
        preg_match_all('/(.*?)^\s*--\s+(#.*?)\n/sm', $body, $match, PREG_SET_ORDER);
        $pos = 0;
        $buf = array();
        if ($this->path) {
            $buf[] = "-- file: " . $this->path . "\n";
        }
        foreach ($match as $a) {
            list($s, $text, $stmt) = $a;
            $pos += strlen($s);
            $buf[] = $this->escape($text);
            $buf[] = $this->convert_statement($stmt);
        }
        $rest = $pos === 0 ? $body : substr($body, $pos);
        if ($rest) {
            $buf[] = $this->escape($rest);
        }
        return implode("", $buf);
    }

    protected function error($message) {
        return new SQLTempl8Error($message);
    }

    protected function escape($text) {
        return preg_replace('/<\?/', "<<?php ?>?", $text);
    }

    protected function convert_statement($stmt) {
        if ($stmt === '#end') {
            return "<?php } ?>\n";
        }
        if ($stmt === '#else') {
            return "<?php } else { ?>\n";
        }
        if (preg_match('/^#if\s+:(\w+)$/', $stmt, $m)) {
            return "<?php if (isset(\$vars['".$m[1]."']) && \$vars['".$m[1]."']) { ?>\n";
        }
        if (preg_match('/^#if_var\s+:(\w+)$/', $stmt, $m)) {
            return "<?php if (isset(\$vars['".$m[1]."'])) { ?>\n";
        }
        if (preg_match('/^#unless\s+:(\w+)$/', $stmt, $m)) {
            return "<?php if (! (isset(\$vars['".$m[1]."']) && \$vars['".$m[1]."'])) { ?>\n";
        }
        if (preg_match('/^#unless_var\s+:(\w+)$/', $stmt, $m)) {
            return "<?php if (! isset(\$vars['".$m[1]."'])) { ?>\n";
        }
        if (preg_match('/^#elseif\s+:(\w+)$/', $stmt, $m)) {
            return "<?php } elseif (isset(\$vars['".$m[1]."']) && \$vars['".$m[1]."']) { ?>\n";
        }
        if (preg_match('/^#elseif_var\s+:(\w+)$/', $stmt, $m)) {
            return "<?php } elseif (isset(\$vars['".$m[1]."'])) { ?>\n";
        }
        throw $this->error("$stmt: unknown statement.");
    }

    public function render($vars=array()) {
        ob_start();
        require $this->get_cache_path();
        return ob_get_clean();
    }

    public function execute($pdo_conn, $vars=array()) {
        $sql = $this->render($vars);
        $pdo_stmt = $pdo_conn->prepare($sql);
        $ok = $pdo_stmt->execute($vars);
        if (! $ok) {
            list($_, $_, $errmsg) = $pdo_stmt->errorInfo();
            $pdo_stmt = null;
            throw $this->error($errmsg);
        }
        return $pdo_stmt;
    }

}
