<?php // -*- coding: utf-8 -*-

error_reporting(E_ALL);

require_once 'Oktest.php';

require_once 'lib/sqltempl8.php';


class SQLTempl8_TC {

    static $SIMPLE_TEMPLATE_STR = <<<'END'
/* description */

select *
from books
where true
-- #if :title_pattern
  and title like :title_pattern
-- #end
  -- #if :publisher_id
  and publisher_id = :publisher_id
  -- #end
order by title

END;

    static $SIMPLE_PHP_CODE = <<<'END'
select *
from books
where true
<?php if (isset($vars['title_pattern']) && $vars['title_pattern']) { ?>
  and title like :title_pattern
<?php } ?>
<?php if (isset($vars['publisher_id']) && $vars['publisher_id']) { ?>
  and publisher_id = :publisher_id
<?php } ?>
order by title

END;

    var $template_str;
    var $php_code;
    var $template_file;
    var $cache_file;
    var $files;

    function provide_simple($create_file) {
        $this->template_str  = self::$SIMPLE_TEMPLATE_STR;
        $this->php_code      = self::$SIMPLE_PHP_CODE;
        $this->template_file = "test_cxzbk.sql";
        $this->cache_file    = "test_cxzbk.sql.cache";
        $this->files         = array($this->template_file, $this->cache_file);
        if ($create_file) {
            file_put_contents($this->template_file, $this->template_str);
            touch($this->template_file, time() - 10);
        }
    }

    function before() {
    }

    function after() {
        if ($this->files) {
            foreach ($this->files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    function test_parse_simple_template() {
        $this->provide_simple(false);
        //
        $sqlt = new SQLTempl8();
        $php_code = $sqlt->parse($this->template_str);
        ok ($php_code)->eq($this->php_code);
    }

    function test_create_cache_file_when_not_exist() {
        $this->provide_simple(true);
        $now = time();
        $mtime = filemtime($this->template_file);
        ok ($mtime)->lt($now);
        //
        ok (file_exists($this->cache_file))->eq(false);
        $sqlt = new SQLTempl8($this->template_file);
        ok (file_exists($this->cache_file))->eq(true);
        ok (filemtime($this->cache_file))->eq($mtime);
    }

    function test_create_cache_file_when_obsolete() {
        $this->provide_simple(true);
        $now = time();
        $mtime = filemtime($this->template_file);
        ok ($mtime)->lt($now);
        //
        file_put_contents($this->cache_file, "xxx");
        ok (file_exists($this->cache_file))->eq(true);
        $sqlt = new SQLTempl8($this->template_file);
        ok (file_exists($this->cache_file))->eq(true);
        ok (file_get_contents($this->cache_file))->ne("xxx");  // changed
        $expected = "-- file: {$this->template_file}\n".$this->php_code;
        ok (file_get_contents($this->cache_file))->eq($expected);
    }

    function test_skip_creating_file_when_exists() {
        $this->provide_simple(true);
        $now = time();
        $mtime = filemtime($this->template_file);
        ok ($mtime)->lt($now);
        //
        file_put_contents($this->cache_file, "xxx");
        touch($this->cache_file, $mtime);
        $sqlt = new SQLTempl8($this->template_file);
        ok (file_get_contents($this->cache_file))->eq("xxx");  // not changed
        ok ($sqlt->render())->eq("xxx");
    }

    function test_render() {
        $this->provide_simple(true);
        $sqlt = new SQLTempl8($this->template_file);
        //
        $vars1 = array('title_pattern'=>"%Database%", 'publisher_id'=>123);
        ok ($sqlt->render($vars1))->eq(
"-- file: {$this->template_file}
select *
from books
where true
  and title like :title_pattern
  and publisher_id = :publisher_id
order by title
");
        //
        $vars2 = array('title_pattern'=>"%Database%");
        ok ($sqlt->render($vars2))->eq(
"-- file: {$this->template_file}
select *
from books
where true
  and title like :title_pattern
order by title
");
        //
        $vars3 = array('publisher_id'=>123);
        ok ($sqlt->render($vars3))->eq(
"-- file: {$this->template_file}
select *
from books
where true
  and publisher_id = :publisher_id
order by title
");
    }

    function test_render_without_vars() {
        $this->provide_simple(true);
        $sqlt = new SQLTempl8($this->template_file);
        //
        $sql = $sqlt->render();
        ok ($sql)->eq(
"-- file: {$this->template_file}
select *
from books
where true
order by title
");
    }

    function test_convert_statement() {
        $sqlt = new SQLTempl8();
        //
        ok ($sqlt->convert("-- #end\n"))->eq("<?php } ?>\n");
        ok ($sqlt->convert("-- #else\n"))->eq("<?php } else { ?>\n");
        ok ($sqlt->convert("-- #if :foo\n"))->eq("<?php if (isset(\$vars['foo']) && \$vars['foo']) { ?>\n");
        ok ($sqlt->convert("-- #if_var :foo\n"))->eq("<?php if (isset(\$vars['foo'])) { ?>\n");
        ok ($sqlt->convert("-- #unless :foo\n"))->eq("<?php if (! (isset(\$vars['foo']) && \$vars['foo'])) { ?>\n");
        ok ($sqlt->convert("-- #unless_var :foo\n"))->eq("<?php if (! isset(\$vars['foo'])) { ?>\n");
        ok ($sqlt->convert("-- #elseif :foo\n"))->eq("<?php } elseif (isset(\$vars['foo']) && \$vars['foo']) { ?>\n");
        ok ($sqlt->convert("-- #elseif_var :foo\n"))->eq("<?php } elseif (isset(\$vars['foo'])) { ?>\n");
    }

    function test_throws_error_when_unknown_stmt() {
        $sqlt = new SQLTempl8();
        $fn = function() use ($sqlt) { $sqlt->convert("-- #elsif :foo\n"); };
        ok ($fn)->throws('SQLTempl8Error', "#elsif :foo: unknown statement.");
    }

    function test_escape_text() {
        $sqlt = new SQLTempl8();
        $template_str =
'<?= var1 ?>
<?= var2 ?>
-- #if_var :key
<?= var3 ?>
<?= var4 ?>
-- #end
<?= var5 ?>
<?= var6 ?>
';
        $expected_php_code =
'<<?php ?>?= var1 ?>
<<?php ?>?= var2 ?>
<?php if (isset($vars[\'key\'])) { ?>
<<?php ?>?= var3 ?>
<<?php ?>?= var4 ?>
<?php } ?>
<<?php ?>?= var5 ?>
<<?php ?>?= var6 ?>
';
        $php_code = $sqlt->convert($template_str);
        ok ($php_code)->eq($expected_php_code);
    }

}



if (basename(__FILE__) === basename($argv[0])) {
    Oktest::run();
    //Oktest::main();
}
