<?php

error_reporting(E_ALL);


class Oktest {

    const VERSION = '0.0.0'; // $Release: 0.0.0 $

    static $debug             = false;
    static $color_available   = null;  //= ! preg_match('/^WIN(32|NT)$/', PHP_OS);
    static $testclass_rexp    = '/(Test|TestCase|_TC)$/';
    static $repr              = 'oktest_repr';
    static $fixture_manager   = null;  //= new Oktest_DefaultFixtureManager()
    static $diff_command_path = '/usr/bin/diff';   // don't add any option

    const PASSED  = 'passed';
    const FAILED  = 'failed';
    const ERROR   = 'error';
    const SKIPPED = 'skipped';

    static $custom_assertions = array(
        'not_exist'  => 'oktest_assert_not_exist',
    );

    function oktest_repr($val) {
        return var_export($val, true);
    }

    function failed($msg) {
        return new Oktest_AssertionFailed($msg);
    }

    function not_thrown($exception_class) {
        throw new Oktest_AssertionFailed($exception_class." expected, but not thrown.");
    }

    function skip($reason) {
        throw new Oktest_SkipException($reason);
    }

    function run() {
        $args = func_get_args();
        $runner = new Oktest_TestRunner();
        return $runner->run_with_args($args);
    }

    function tracer() {
        return new Oktest_Tracer();
    }

    function _log($msg, $arg) {
        if (self::$debug) {
            echo '*** debug: '.$msg.var_export($arg, true)."\n";
        }
    }

    function main() {
        $app = new Oktest_MainApp();
        $app->main($argv);
    }

}

Oktest::$color_available = ! preg_match('/^WIN(32|NT)$/', PHP_OS);


///
/// utils
///
class Oktest_Utils {

    static function arr_concat(&$arr, $items) {
        foreach ($items as $item) {
            $arr[] = $item;
        }
        return $arr;
    }

    static function rm_rf($path) {
        if (is_file($path)) {
            unlink($path);
        }
        else if (is_dir($path)) {
            $items = scandir($path);
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    self::rm_rf($path.'/'.$item);
                }
            }
            rmdir($path);
        }
        else {
            // nothing
        }
    }

    /** _rformat('%r', $value) is similar to sprintf('%s', oktest_repr($value)) */
    function _rformat($format, &$args) {
        preg_match_all('/(.*?)(%[sr%])/s', $format, $matches, PREG_SET_ORDER);
        $n = 0; $i = 0; $s = '';
        $repr = Oktest::$repr;
        foreach ($matches as $m) {
            $n += strlen($m[0]);
            $s .= $m[1];
            if ($m[2] === '%r') {
                $s .= '%s';        // convert '%r' into '%s'
                $args[$i] = $repr($args[$i]);
                $i++;
            }
            else {
                $s .= $m[2];
                if ($m[2] !== '%%') $i++;
            }
        }
        $s .= substr($format, $n);
        $new_format = $s;
        return $new_format;
    }

    static function get_kwargs(&$args, $defaults=array()) {
        if (! $args) return $defaults;
        $last_index = count($args) - 1;
        if (! is_array($args[$last_index])) return $defaulst;
        $options = array_pop($args);
        if ($defaults) $options = array_merge($defaults, $options);
        return $options;
    }

    static function fnmatch_grep($pattern, $list) {
        $matched = array();
        foreach ($list as $item) {
            if (fnmatch($pattern, $item)) {
                $matched[] = $item;
            }
        }
        return $matched;
    }

    //static function pat2rexp($pattern) {
    //    $metachars = '';
    //    $len = strlen($pattern);
    //    $rexp = '/^';
    //    for ($i = 0; $i < $len; $i++) {
    //        $ch = $pattern{$i};
    //        if ($ch === '\\') {
    //            $rexp .= '\\';
    //            $rexp .= ++$i < $len ? $pattern{$i} : '\\';
    //        }
    //        else if ($ch === '*') {
    //            $rexp .= '.*';
    //        }
    //        else if ($ch === '?') {
    //            $rexp .= '.';
    //        }
    //        else {
    //            $rexp .= preg_quote($ch);
    //        }
    //    }
    //    $rexp .= '$/';
    //    return $rexp;
    //}

    static function diff_u($actual, $expected) {
        if (is_file(Oktest::$diff_command_path)) {
            return self::_invoke_diff_command(Oktest::$diff_command_path, $actual, $expected);
        }
        else {
            return self::_diff_by_text_diff_library($actual, $expected);
        }
    }

    static function _invoke_diff_command($diff_command_path, $actual, $expected) {
        $tmpdir = sys_get_temp_dir();
        $tmpfile1 = tempnam($tmpdir, "actual.");
        $tmpfile2 = tempnam($tmpdir, "expected.");
        $output = null;
        $ex = null;
        try {
            file_put_contents($tmpfile1, $actual);
            file_put_contents($tmpfile2, $expected);
            $output = shell_exec("$diff_command_path -u $tmpfile2 $tmpfile1");
            $output = preg_replace('/^--- .*\n\+\+\+ .*\n/', "--- expected\n+++ actual\n", $output);
        }
        catch (Exception $ex) {
        }
        if (is_file($tmpfile1)) unlink($tmpfile1);
        if (is_file($tmpfile2)) unlink($tmpfile2);
        if ($ex) throw $ex;
        return $output;
    }

    static function _diff_by_text_diff_library($actual, $expected) {
        require_once('Text/Diff.php');
        require_once('Text/Diff/Renderer.php');
        require_once('Text/Diff/Renderer/unified.php');
        $e_lines = preg_split('/^/m', $expected);
        array_shift($e_lines);
        $a_lines = preg_split('/^/m', $actual);
        array_shift($a_lines);
        $diffobj = new Text_Diff('auto', array($e_lines, $a_lines));
        $renderer = new Text_Diff_Renderer_unified();
        $diffstr = $renderer->render($diffobj);
        $preamble = "--- expected\n+++ actual\n";
        return $preamble.$diffstr;
    }

}

function oktest_repr($val) {
    return var_export($val, true);
}


///
/// assertions
///

class Oktest_AssertionFailed extends Exception {
    function _setMessage($message) {
        $this->message = $message;
    }
}


class Oktest_SkipException extends Exception {
}


class Oktest_AssertionObject {

    function __construct($actual, $boolean=true) {
        $this->actual = $actual;
        $this->boolean = $boolean;
        $this->_done = false;
    }

    function _create($actual, $op, $expected, $boolean) {
        $obj = new self($actual, $boolean);
        if ($op === null) return $obj;
        $meth = isset(self::$operators[$op]) ? self::$operators[$op] : $op;
        return call_user_func(array($obj, $meth), $expected);
    }

    function _msg($op, $expected) {
        $repr = Oktest::$repr;
        $prefix = $this->boolean ? '' : 'NOT ';
        $msg = $prefix.'$actual '.$op.' $expected : failed.
  $actual  : '.$repr($this->actual).'
  $expected: '.$repr($expected);
        return $msg;
    }

    function _msg2($op, $expected) {
        $diff_str = $this->boolean && is_string($expected)
                    ? Oktest_Utils::diff_u($this->actual, $expected)
                    : null;
        if (! $diff_str) return $this->_msg($op, $expected);
        $prefix = $this->boolean ? '' : 'NOT ';
        return $prefix.'$actual '.$op.' $expected : failed.'."\n".$diff_str;
    }

    function eq($expected) {
        $this->_done = true;
        $boolean = $this->actual == $expected;
        if ($boolean === $this->boolean) return $this;
        throw Oktest::failed($this->_msg2('==', $expected));
    }

    function ne($expected) {
        $this->_done = true;
        $boolean = $this->actual != $expected;
        if ($boolean === $this->boolean) return $this;
        throw Oktest::failed($this->_msg('!=', $expected));
    }

    function is($expected) {
        $this->_done = true;
        $boolean = $this->actual === $expected;
        if ($boolean === $this->boolean) return $this;
        throw Oktest::failed($this->_msg2('===', $expected));
    }

    function is_not($expected) {
        $this->_done = true;
        $boolean = $this->actual !== $expected;
        if ($boolean === $this->boolean) return $this;
        throw Oktest::failed($this->_msg('!==', $expected));
    }

    function lt($expected) {
        $this->_done = true;
        $boolean = $this->actual < $expected;
        if ($boolean === $this->boolean) return $this;
        throw Oktest::failed($this->_msg('<', $expected));
    }

    function le($expected) {
        $this->_done = true;
        $boolean = $this->actual <= $expected;
        if ($boolean === $this->boolean) return $this;
        throw Oktest::failed($this->_msg('<=', $expected));
    }

    function gt($expected) {
        $this->_done = true;
        $boolean = $this->actual > $expected;
        if ($boolean === $this->boolean) return $this;
        throw Oktest::failed($this->_msg('>', $expected));
    }

    function ge($expected) {
        $this->_done = true;
        $boolean = $this->actual >= $expected;
        if ($boolean === $this->boolean) return $this;
        throw Oktest::failed($this->_msg('>=', $expected));
    }

    static $operators = array(
        '=='    => 'eq',
        '!='    => 'ne',
        '==='   => 'is',
        '!=='   => 'is_not',
        '<'     => 'lt',
        '<='    => 'le',
        '>'     => 'gt',
        '>='    => 'ge',
    );

    function in_delta($expected, $delta) {
        $this->gt($expected - $delta);
        $this->lt($expected + $delta);
        return $this;
    }

    function is_a($expected) {
        $this->_done = true;
        $boolean = $this->actual instanceof $expected;
        if ($boolean === $this->boolean) return $this;
        throw Oktest::failed($this->_msg('instanceof', $expected));
    }

    function _failed($format, $args) {
        $args   = func_get_args();
        $format = array_shift($args);
        $format = Oktest_Utils::_rformat($format, $args);
        $prefix = $this->boolean ? '' : 'NOT ';
        $msg    = vsprintf($prefix.$format, $args);
        return Oktest::failed($msg);
    }

    function is_true_value() {
        $this->_done = true;
        $boolean = !! $this->actual;
        if ($boolean === $this->boolean) return $this;
        throw $this->_failed('!! $actual === true : failed.
  $actual  : %r', $this->actual);
    }

    function is_false_value() {
        $this->_done = true;
        $boolean = ! $this->actual;
        if ($boolean === $this->boolean) return $this;
        throw $this->_failed('!! $actual === false : failed.
  $actual  : %r', $this->actual);
    }

    function is_one_of($args) {  // or in()?
        $this->_done = true;
        $args = func_get_args();
        $boolean = in_array($this->actual, $args);
        if ($boolean === $this->boolean) return $this;
        throw $this->_failed('in_array($actual, $expected) : failed.
  $actual  : %r
  $expected: %r', $this->actual, $args);
    }

    function match($regexp) {
        $this->_done = true;
        $boolean = !! preg_match($regexp, $this->actual);
        if ($boolean === $this->boolean) return $this;
        throw $this->_failed('preg_match(\'%s\', $actual) : failed.
  $actual  : %r', $regexp, $this->actual);
    }

    function has_key($key) {
        $this->_done = true;
        $boolean = array_key_exists($key, $this->actual);
        if ($boolean === $this->boolean) return $this;
        $repr = Oktest::$repr;
        $prefix = $this->boolean ? '' : 'NOT ';
        throw $this->_failed('array_key_exists($key, $actual) : failed.
  $actual  : %r
  $key     : %r', $this->actual, $key);
        throw Oktest::failed($msg);
    }

    function has_attr($attr_name) {
        $this->_done = true;
        $boolean = property_exists($this->actual, $attr_name);
        if ($boolean === $this->boolean) return $this;
        $repr = Oktest::$repr;
        $prefix = $this->boolean ? '' : 'NOT ';
        throw $this->_failed('property_exists($actual, \'%s\') : failed.
  $actual  : %r', $attr_name, $this->actual);
    }

    function attr($attr_name, $attr_value) {
        $this->_done = true;
        //
        $bkup = $this->boolean;
        $this->boolean = true;
        $this->has_attr($attr_name);
        $this->boolean = $bkup;
        //
        $val = $this->actual->$attr_name;
        $boolean = $this->actual->$attr_name === $attr_value;
        if ($boolean === $this->boolean) return $this;
        throw $this->_failed('$actual->%s === $expected : failed.
  $actual->%s: %r
  $expected: %r', $attr_name, $attr_name, $val, $attr_value);
    }

    function count($num) {
        $this->_done = true;
        $val = count($this->actual);
        $boolean = $val === $num;
        if ($boolean === $this->boolean) return $this;
        throw $this->_failed('count($actual) === %s : failed.
  count($actual): %s
  $actual  : %r', $num, $val, $this->actual);
    }

    function throws($error_class, $error_msg=null, &$exception=null) {
        if (! $this->boolean) {
            throw new ErrorException("throws() is available only with ok(), not NG().");
        }
        $ex = null;
        try {
            $fn = $this->actual;
            $fn();
        }
        catch (Exception $ex) {
            $exception = $ex;
            $repr = Oktest::$repr;
            if (! ($ex instanceof $error_class)) {
                $msg = $error_class.' expected but '.get_class($ex)." thrown.\n'"
                      .'  $exception: '.$repr($ex);
                throw Oktest::failed($msg);
            }
            if ($error_msg && $ex->getMessage() !== $error_msg) {
                $bkup = $this->actual;
                $this->actual = $ex->getMessage();
                $msg = $this->_msg('==', $error_msg);
                $this->actual = $bkup;
                throw Oktest::failed($msg);
            }
        }
        if (! $ex) {
            throw Oktest::failed($error_class.' expected but nothing thrown.');
        }
        return $this;
    }

    function not_throw($error_class='Exception', $exception=null) {
        if (! $this->boolean) {
            throw new ErrorException("not_throw() is available only with ok(), not NG().");
        }
        try {
            $fn = $this->actual;
            $fn();
        }
        catch (Exception $ex) {
            $exception = $ex;
            $repr = Oktest::$repr;
            if ($ex instanceof $error_class) {
                $msg = $error_class." is not expected, but thrown.\n"
                      .'  $exception: '.$repr($ex);
                throw Oktest::failed($msg);
            }
        }
        return $this;
    }


    static $boolean_assertions = array(
        'is_null'    => 'is_null',
        'is_string'  => 'is_string',
        'is_int'     => 'is_int',
        'is_float'   => 'is_float',
        'is_numeric' => 'is_numeric',
        'is_bool'    => 'is_bool',
        'is_scalar'  => 'is_scalar',
        'is_array'   => 'is_array',
        'is_object'  => 'is_object',
        'is_file'    => 'is_file',
        'is_dir'     => 'is_dir',
        'isset'      => 'isset',
        'in_array'   => 'in_array',
    );

    function __call($name, $args) {
        if (isset(Oktest::$custom_assertions[$name])) {
            $func = Oktest::$custom_assertions[$name];
            array_unshift($args, $this->boolean, $this->actual);
            $errmsg = call_user_func_array($func, $args);
            if ($errmsg === null) return $this;
            throw Oktest::failed($errmsg);
        }
        else if (isset(self::$boolean_assertions[$name])) {
            $func_name = self::$boolean_assertions[$name];
            array_unshift($args, $this->actual);
            $boolean = call_user_func_array($name, $args);
            if ($boolean !== true && $boolean !== false) {
                $repr = Oktest::$repr;
                $msg = 'ERROR: '.$name.'() should return true or false, but got '.$repr($boolean);
                throw new Exception($msg);
            }
            if ($boolean === $this->boolean) return $this;
            $prefix = $this->boolean ? '' : 'NOT ';
            $repr = Oktest::$repr;
            $msg = $prefix.$name."(\$actual) : failed.\n"
                 . '  $actual: '.$repr($this->actual);
            throw Oktest::failed($msg);
        }
        else {
            $method = $this->boolean ? 'ok()' : 'NG()';
            $msg = $method.'->'.$name.'(): no such assertion method.';
            throw new ErrorException($msg);
        }
    }

    function all() {
        if (! $this->boolean) {
            throw ErrorException("all() is avialable only with ok(), not NG().");
        }
        return new Oktest_IterableAssertionObject($this);
    }

}


class Oktest_IterableAssertionObject {

    function __construct($assertobj) {
        $this->_assertobj = $assertobj;
    }

    function __call($method, $args) {
        $actual = $this->_assertobj->actual;
        $i = null;
        try {
            foreach ($actual as $i=>$item) {
                call_user_func_array(array(ok ($item), $method), $args);
            }
        }
        catch (Oktest_AssertionFailed $ex) {
            if ($i !== null) {
                $ex->_setMessage('[index='.$i.'] '.$ex->getMessage());
            }
            throw $ex;
        }
        return $this;
    }

}


function ok($actual, $op=null, $expected=null) {
    return Oktest_AssertionObject::_create($actual, $op, $expected, true);
}

function NG($actual, $op=null, $expected=null) {
    return Oktest_AssertionObject::_create($actual, $op, $expected, false);
}

/** same as ok(), but intended to describe pre-condition of test. */
function pre_cond($actual, $op=null, $expected=null) {
    return Oktest_AssertionObject::_create($actual, $op, $expected, true);
}

function oktest_assert_not_exist($boolean, $actual) {
    if      (is_file($actual)) $func = 'is_file';
    else if (is_dir($actual))  $func = 'is_dir';
    else                       $func = null;
    $result = $func === null;
    if ($result === $boolean) return null;
    $repr = Oktest::$repr;
    $errmsg = $func."(\$actual) === false : failed.\n"."  \$actual: ".$repr($actual);
    return $errmsg;
}


///
/// test runner
///

class Oktest_TestRunner {

    function __construct($reporter=null) {
        if (! $reporter) $reporter = new Oktest_VerboseReporter();
        $this->reporter = $reporter;
    }

    function _get_testclasses($names_or_patterns) {
        $all_class_names = get_declared_classes();
        $class_names = array();  // list
        foreach ($names_or_patterns as $item) {
            if (preg_match('/^\/.*\/[a-z]*/', $item)) {
                $rexp = $item;
                $matched = preg_grep($rexp, $all_class_names);
                if ($matched) $class_names = array_merge($class_names, $matched);
                else          trigger_error("'".$rexp."': nothing matched.", E_USER_WARNING);
            }
            else if (preg_match('/[?*]/', $item)) {
                $pattern = $item;
                $matched = Oktest_Utils::fnmatch_grep($pattern, $all_class_names);
                if ($matched) $class_names = array_merge($class_names, $matched);
                else          trigger_error("'".$pattern."': nothing matched.", E_USER_WARNING);
            }
            else {
                $class_name = $item;
                $ok = in_array($class_name, $all_class_names);
                if ($ok) $class_names[] = $class_name;
                else     trigger_error("'".$class_name."': no such class found.", E_USER_WARNING);
            }
        }
        return $class_names;
    }

    function _get_testnames($testclass) {
        $methods = get_class_methods($testclass);
        $testnames = array();
        if (! $methods) return $testnames;
        foreach ($methods as $name) {
            if (preg_match('/^test/', $name)) {
                $testnames[] = $name;
            }
        }
        return $testnames;
    }

    function _parse_filter($filter) {
        $arr = preg_split('/\./', $filter);
        if (count($arr) > 1) {
            $class_pattern = $arr[0];  $method_pattern = $arr[1];
        }
        else if (preg_match('/^test/', $filter)) {
            $class_pattern = null;     $method_pattern = $filter;
        }
        else {
            $class_pattern = $filter;  $method_pattern = null;
        }
        return array($class_pattern, $method_pattern);
    }

    function run_with_args($args) {
        $defaults = array('style'=>'verbose', 'color'=>null, 'filter'=>null);
        $kwargs = Oktest_Utils::get_kwargs($args, $defaults);
        $style  = $kwargs['style'];
        $color  = $kwargs['color'];
        $filter = $kwargs['filter'];
        //
        $klass = Oktest_BaseReporter::get_registered_class($style);
        if (! $klass) throw new Exception($style.": no such reporting style.");
        $this->reporter = new $klass();
        $this->reporter->color = $color;
        //
        if (! $filter) {
            $class_rexps = $args ? $args : array(Oktest::$testclass_rexp);
            $class_names = $this->_get_testclasses($class_rexps);
            $method_pat = null;
        }
        else {
            list($class_pat, $method_pat) = $this->_parse_filter($filter);
            $arg = $class_pat ? $class_pat : OKtest::$testclass_rexp;
            $class_names = $this->_get_testclasses(array($arg));
        }
        //
        $ex = null;
        try {
            $this->enter_all();
            foreach ($class_names as $testclass) {
                $this->_run_testclass($testclass, $method_pat);
            }
        }
        catch (Exception $ex) {
        }
        $this->exit_all();
        if ($ex) throw $ex;
        //
        assert('isset($this->reporter->counts)');
        $dict = $this->reporter->counts;
        return $dict[Oktest::FAILED] + $dict[Oktest::ERROR];
    }

    function _run_testclass($testclass, $method_pat=null) {
        $method_names = $this->_get_testnames($testclass);
        if ($method_pat) {
            $method_names = Oktest_Utils::fnmatch_grep($method_pat, $method_names);
            if (! $method_names) return;
        }
        //
        $ex = null;
        try {
            $this->enter_testclass($testclass);
            $this->_invoke_classmethod($testclass, 'before_all', 'setUpBeforeClass');
            foreach ($method_names as $testname) {
                $testcase = $this->_new_testcase($testclass, $testname);
                $this->_run_testcase($testclass, $testcase, $testname);
            }
        }
        catch (Exception $ex) {
        }
        $this->_invoke_classmethod($testclass, 'after_all', 'tearDownAfterClass');
        $this->exit_testclass($testclass);
        if ($ex) throw $ex;
    }

    function _invoke_classmethod($klass, $method1, $method2) {
        $method = method_exists($klass, $method1) ? $method1 :
                 (method_exists($klass, $method2) ? $method2 : null);
        if ($method) {
            $s = "$klass:$method();";
            eval("$klass::$method();");
        }
    }

    function _run_testcase($testclass, $testcase, $testname) {
        $this->enter_testcase($testcase, $testname);
        $status = Oktest::PASSED;
        $exceptions = array();
        $ex = $this->_call_method($testcase, 'before', 'setUp');
        if ($ex) {
            $status = Oktest::ERROR;
            $exceptions[] = $ex;
        }
        else {
            $ex = null;
            try {
                $this->_invoke_testcase($testclass, $testcase, $testname, $exceptions);
            }
            catch (Oktest_AssertionFailed $ex) { $status = Oktest::FAILED; }
            catch (Oktest_SkipException $ex)   { $status = Oktest::SKIPPED; $ex = null; }
            catch (Exception $ex)              { $status = Oktest::ERROR; }
            if ($exceptions) $status = Oktest::ERROR;
            if ($ex) $exceptions[] = $ex;
        }
        $ex = $this->_call_method($testcase, 'after', 'tearDown');
        if ($ex) {
            $status = Oktest::ERROR;
            $exceptions[] = $ex;
        }
        $this->exit_testcase($testcase, $testname, $status, $exceptions);
    }

    function _invoke_testcase($testclass, $testcase, $testname, &$exceptions) {
        $injector = new Oktest_FixtureInjector($testclass);
        $injector->invoke($testcase, $testname);
    }

    //function _invoke_testcase($testclass, $testcase, $testname, &$exceptions) {
    //    $fixture_names = $this->_fixture_names($testclass, $testname);
    //    if (! $fixture_names) {
    //        $testcase->$testname();   // may throw
    //        return;
    //    }
    //    /// gather fixtures
    //    $fixtures = array();
    //    $fixture_dict = array();
    //    $releasers = array();
    //    $ok = true;
    //    foreach ($fixture_names as $name) {
    //        $provider = 'provide_'.$name;
    //        $releaser = 'release_'.$name;
    //        try {
    //            if (! method_exists($testcase, $provider)) {
    //                throw new Oktest_FixtureError($provider.'() is not defined.');
    //            }
    //            $val = $testcase->$provider();
    //            $fixtures[] = $val;
    //            $fixture_dict[$name] = $val;
    //            $releasers[$name] = method_exists($testcase, $releaser) ? $releaser : null;
    //        }
    //        catch (Exception $ex) {
    //            $ok = false;
    //            $exceptions[] = $ex;
    //        }
    //    }
    //    /// invoke test method with fixtures
    //    $exception = null;
    //    if ($ok) {
    //        try {
    //            call_user_method_array($testname, $testcase, $fixtures);  // may throw
    //        }
    //        catch (Exception $ex) {
    //            $exception = $ex;
    //        }
    //    }
    //    /// release fixtures
    //    foreach ($fixture_dict as $name=>$val) {
    //        if (isset($releasers[$name])) {
    //            $releaser = $releasers[$name];
    //            $testcase->$releaser($val);
    //        }
    //    }
    //    /// throw exception if thrown
    //    if ($exception) throw $exception;
    //}
    //
    //function _fixture_names($testclass, $testname) {
    //    $ref = new ReflectionMethod($testclass, $testname);
    //    $params = $ref->getParameters();
    //    if (! $params) return null;
    //    $param_names = array();
    //    foreach ($params as $param) {
    //        $param_names[] = $param->name;
    //    }
    //    return $param_names;
    //}

    function _call_method($testcase, $meth1, $meth2) {
        $meth = null;
        if      ($meth1 && method_exists($testcase, $meth1)) $meth = $meth1;
        else if ($meth2 && method_exists($testcase, $meth2)) $meth = $meth2;
        if ($meth) {
            try {
                $testcase->$meth();
            }
            catch (Exception $ex) {
                return $ex;
            }
        }
        return null;
    }

    function _new_testcase($testclass, $testname) {
        return new $testclass();
    }


    function enter_all() {
        $this->reporter->enter_all();
    }

    function exit_all() {
        $this->reporter->exit_all();
    }

    function enter_testclass($testclass) {
        $this->reporter->enter_testclass($testclass);
    }

    function exit_testclass($testclass) {
        $this->reporter->exit_testclass($testclass);
    }

    function enter_testcase($testcase, $testname) {
        $this->reporter->enter_testcase($testcase, $testname);
    }

    function exit_testcase($testcase, $testname, $status, $exceptions) {
        $this->reporter->exit_testcase($testcase, $testname, $status, $exceptions);
    }

}


///
/// fixture
///

class Oktest_FixtureError extends ErrorException {
}


class Oktest_FixtureNotFoundError extends Oktest_FixtureError {
}


class Oktest_LoopedDependencyError extends Oktest_FixtureError {
}


class Oktest_FixtureManager {

    function provide($name) { return null; }
    function release($name, $value) { }

}


class Oktest_DefaultFixtureManager extends Oktest_FixtureManager {

    function __construct() {
        $this->_fixtures = array();
    }

    function provide($name) {
        if ($name === 'cleaner') return new Oktest_Fixture_Cleaner();
        throw new Oktest_FixtureNotFoundError('provide_'.$name.'() not defined.');
    }

    function release($name, $value) {
        if ($name === 'cleaner') return $value->clean();
    }

}

Oktest::$fixture_manager = new Oktest_DefaultFixtureManager();


class Oktest_FixtureInjector {

    function __construct($klass) {
        $this->klass = $klass;
        $this->fixture_manager = Oktest::$fixture_manager;
        $this->_ref_cache = array();  // {"provider_name"=>ReflectionMethod}
    }

    function _resolve($fixture_name, $object) {
        $name = $fixture_name;
        if (! array_key_exists($name, $this->_resolved)) {
            $pair = $this->find($name, $object);
            if ($pair) {
                list($provider, $releaser) = $pair;
                $this->_resolved[$name]  = $this->_call($provider, $object, $name);
                $this->_releasers[$name] = $releaser;
            }
            else {
                $this->_resolved[$name] = $this->fixture_manager->provide($name);
            }
        }
        return $this->_resolved[$name];
    }

    function _call($provider, $object, $fixture_name) {
        if (isset($this->_ref_cache[$provider])) {
            $ref = $this->_ref_cache[$provider];
        }
        else {
            $ref = $this->_ref_cache[$provider] = new ReflectionMethod($this->klass, $provider);
        }
        if ($ref->getNumberOfParameters() === 0) {
            return $object->$provider();
        }
        $this->_in_progress[] = $fixture_name;
        $args = array();  // list
        foreach ($ref->getParameters() as $param) {
            if ($param->isOptional()) {
                /// default value can be overrided by optional parameter of test method
                $args[] = array_key_exists($param->name, $this->_resolved)
                          ? $this->_resolved[$param->name]
                          : $param->getDefaultValue();
            }
            else {
                $args[] = $this->_get_fixture_value($param->name, $object);
            }
        }
        $popped = array_pop($this->_in_progress);
        if ($popped !== $fixture_name) throw new Exception("** internal error: ");
        return call_user_func_array(array($object, $provider), $args);
    }

    function _get_fixture_value($aname, $object) {
        /// if already resolved then return resolved value
        if (array_key_exists($aname, $this->_resolved)) {
            return $this->_resolved[$aname];
        }
        /// if not resolved yet then resolve it with checking dependency loop
        if (! in_array($aname, $this->_in_progress)) {
            return $this->_resolve($aname, $object);
        }
        throw $this->_looped_dependency_error($aname);
    }

    function invoke($object, $method) {
        if (get_class($object) !== $this->klass) {
            throw new ErrorException('expected '.$this->klass.' object but got '.get_class($object).' object');
        }
        ///
        $ref = new ReflectionMethod($this->klass, $method);
        if ($ref->getNumberOfParameters() === 0) {
            return $object->$method();
        }
        ///
        $this->_releasers = array('self'=>null);
        $this->_resolved  = array('self'=>$object);
        $this->_in_progress = array();
        ///
        $fixture_names = array();  // list
        foreach ($ref->getParameters() as $param) {
            if ($param->isOptional()) {
                /// default value of optional parameters are part of fixtures.
                /// their values are passed into providers if necessary.
                $this->_resolved[$param->name] = $param->getDefaultValue();
            }
            else {
                $fixture_names[] = $param->name;
            }
        }
        if (! $fixture_names) {
            return $object->$method();
        }
        ///
        $ex = null;
        try {
            ///
            $fixture_values = array();
            foreach ($fixture_names as $name) {
                $fixture_values[] = $this->_resolve($name, $object);
            }
            if ($this->_in_progress) throw Exception('internal error: $in_progress='.var_export($this->_in_progress, true));
            ///
            $ret = call_user_func_array(array($object, $method), $fixture_values);
        }
        catch (Exception $ex) {
        }
        $this->_release_fixtures($object);
        if ($ex) throw $ex;
        return $ret;
    }

    function _release_fixtures($object) {
        foreach ($this->_resolved as $name=>$val) {
            if (array_key_exists($name, $this->_releasers)) {
                $releaser = $this->_releasers[$name];
                if ($releaser) $object->$releaser($val);
            }
            else {
                Oktest::$fixture_manager->release($name, $val);
            }
        }
    }

    function find($name, $object) {
        $provider = 'provide_'.$name;
        $releaser = 'release_'.$name;
        if (method_exists($object, $provider)) {
            if (! method_exists($object, $releaser)) $releaser = null;
            return array($provider, $releaser);
        }
        return null;
    }

    function _looped_dependency_error($aname) {
        $names = array_merge($this->_in_progress, array($aname));
        $pos   = $this->_array_index($names, $aname);
        $loop  = join('=>', array_slice($names, $pos));
        if ($pos > 0) {
            $loop = join('->', array_slice($names, 0, $pos)).'->'.$loop;
        }
        $msg = sprintf('fixture dependency is looped: '.$loop.' ('.$this->klass.')');
        return new Oktest_LoopedDependencyError($msg);
    }

    function _array_index($arr, $item) {
        foreach ($arr as $i=>$v) {
            if ($v === $item) return $i;
        }
        return -1;
    }

}


class Oktest_Fixture_Cleaner implements ArrayAccess {

    function __construct() {
        $this->items = array();
    }

    function add() {
        $this->concat(func_get_args());
        return $this;
    }

    function concat($arr) {
        //$this->items = array_merge($this->items, $arr);
        foreach ($arr as $item) {
            $this->items[] = $item;
        }
        return $this;
    }

    function clean() {
        foreach ($this->items as $path) {
            Oktest_Utils::rm_rf($path);
        }
    }

    /// implements ArrayAccess

    public function offsetSet($offset, $value) {
        if (is_null($offset)) $this->items[]        = $value;
        else                  $this->items[$offset] = $value;
    }

    public function offsetExists($offset) {
        return isset($this->items[$offset]);
    }

    public function offsetUnset($offset) {
        unset($this->items[$offset]);
    }

    public function offsetGet($offset) {
        return isset($this->items[$offset]) ? $this->items[$offset] : null;
    }

}


///
/// reporter
///

class Oktest_Reporter {
    function enter_all() {}
    function exit_all() {}
    function enter_testclass($testclass) {}
    function exit_testclass($testclass) {}
    function enter_testcase($testcase, $testname) {}
    function exit_testcase($testcase, $testname, $status, $exceptions) {}
}


class Oktest_BaseReporter extends Oktest_Reporter {

    var $separator = "----------------------------------------------------------------------";
    var $counts;
    var $color;

    function enter_all() {
        $this->counts = array('total'=>0, Oktest::PASSED=>0, Oktest::FAILED=>0, Oktest::ERROR=>0, Oktest::SKIPPED=>0);
        $this->_filenines = array();   // {'filepath'=>['lines']}
        $this->_start_time = microtime(true);
    }

    function exit_all() {
        $end_time = microtime(true);
        $dt = $end_time - $this->_start_time;
        echo '## '.$this->_build_counts_str()
             .'  '.$this->_build_elapsed_str($this->_start_time, $end_time)."\n";
        $this->_filenines = null;
    }

    function _build_elapsed_str($start, $end) {
        $dt = $end - $start;
        $min = intval($dt) / 60;
        $sec = $dt - $min;
        return $min ? sprintf("(elapsed %d:%02.3f)", $min, $sec)
                    : sprintf("(elapsed %.3f)", $sec);
    }

    function _build_counts_str() {
        $total = 0;
        $buf = array(null);
        foreach (array(Oktest::PASSED, Oktest::FAILED, Oktest::ERROR, Oktest::SKIPPED) as $st) {
            $n = $this->counts[$st];
            $total += $n;
            $s = $st.':'.$n;
            $buf[] = $n ? $this->colorize($s, $st) : $s;
        }
        $buf[0] = 'total:'.$total;
        $this->counts['total'] = $total;
        return join(', ', $buf);
    }

    function enter_testclass($testclass) {
        $this->_tuples = array();
    }

    function exit_testclass($testclass) {
        $this->report_exceptions($this->_tuples);
    }

    function enter_testcase($testcase, $testname) {
    }

    function exit_testcase($testcase, $testname, $status, $exceptions) {
        $this->counts[$status] += 1;
        if ($exceptions) {
            foreach ($exceptions as $ex) {
                $this->_tuples[] = array($testcase, $testname, $status, $ex);
            }
        }
    }

    // --------------------

    function report_exceptions($tuples) {
        if ($tuples) {
            foreach ($tuples as $i=>$tuple) {
                list($testcase, $testname, $status, $exception) = $tuple;
                echo $this->colorize($this->separator, "separator"); echo "\n";
                $this->report_exception($testcase, $testname, $status, $exception);
            }
            echo $this->colorize($this->separator, "separator"); echo "\n";
        }
    }

    function indicator($status, $colorize=true) {
        if      ($status === Oktest::PASSED)  $s = 'ok';
        else if ($status === Oktest::FAILED)  $s = 'Failed';
        else if ($status === Oktest::ERROR)   $s = 'ERROR';
        else if ($status === Oktest::SKIPPED) $s = 'skipped';
        else                            $s = '???';
        return $colorize ? $this->colorize($s, $status) : $s;
    }

    function status_char($status, $colorize=true) {
        if      ($status === Oktest::PASSED)  $s = '.';
        else if ($status === Oktest::FAILED)  $s = 'f';
        else if ($status === Oktest::ERROR)   $s = 'E';
        else if ($status === Oktest::SKIPPED) $s = 's';
        else                            $s = '?';
        return $colorize & $status !== Oktest::PASSED ? $this->colorize($s, $status) : $s;
    }

    function report_exception($testcase, $testname, $status, $exception) {
        $ex = $exception;
        $indicator = $this->indicator($status, true);
        echo '[', $indicator, '] ', get_class($testcase), ' > ', $testname, "\n";
        $class_name = $ex instanceof Oktest_AssertionFailed ? 'AssertionFailed' : get_class($ex);
        echo $class_name, ': ', $ex->getMessage(), "\n";
        //$this->report_backtrace($ex->getTrace());
        $trace = $ex->getTrace();
        $item = array('file'=>$ex->getFile(), 'line'=>$ex->getLine());
        array_unshift($trace, $item);
        $this->report_backtrace($trace);
    }

    function report_backtrace($trace) {
        foreach ($trace as $i=>$dict) {
            if ($this->skip_p($dict)) continue;
            $file = isset($dict['file']) ? $dict['file'] : null;
            $line = isset($dict['line']) ? $dict['line'] : null;
            if (isset($trace[$i+1])) {
                $d = $trace[$i+1];
                $func  = isset($d['function']) ? $d['function'] : null;
                $class = isset($d['class'])    ? $d['class']    : null;
                $type  = isset($d['type'])     ? $d['type']     : null;
                $location = $class && $func ? ($class . $type . $func)
                                            : ($class ? $class : $func);
            }
            else {
                $func = $class = $type = null;
            }
            $this->report_backtrace_entry($file, $line, $func, $class, $type);
        }
    }

    function report_backtrace_entry($file, $line, $func, $class, $type) {
        $location = $class && $func ? ($class . $type . $func)
                                    : ($class ? $class : $func);
        if ($location) {
            //echo '  File "', $file, '", line ', $line, ', in ', $location, "\n";
            echo '  ', $file, ':', $line, ' in ', $location, "\n";
        }
        else {
            //echo '  File "', $file, '", line ', $line, "\n";
            echo '  ', $file, ':', $line, "\n";
        }
        $linestr = $this->fetch_line($file, $line);
        if ($linestr) {
            echo '    ', trim($linestr), "\n";
        }
    }

    function fetch_line($filepath, $linenum) {
        if (! $filepath) return null;
        if (! isset($this->_filelines[$filepath])) {
            $this->_filelines[$filepath] = file($filepath);
        }
        return $this->_filelines[$filepath][$linenum-1];
    }

    function skip_p($dict) {
        if (Oktest::$debug) return false;
        if (! isset($dict['file'])) return true;
        if (basename($dict['file']) === 'Oktest.php') return true;
        if (isset($dict['class'])) {
            $class = $dict['class'];
            if ($class === 'Oktest') return true;
            if ($class === 'Oktest_AssertionObject' &&
                isset($dict['function']) && $dict['function'] === '__call')
                return true;
        }
        return false;
    }

    function colorize($s, $kind) {
        if ($this->color === null) {
            $this->color = Oktest::$color_available && $this->is_tty();
        }
        if (! $this->color) return $s;
        if ($kind === Oktest::PASSED)    return Oktest_Color::bold_green($s);
        if ($kind === Oktest::FAILED)    return Oktest_Color::bold_red($s);
        if ($kind === Oktest::ERROR)     return Oktest_Color::bold_red($s);
        if ($kind === Oktest::SKIPPED)   return Oktest_Color::bold_yellow($s);
        if ($kind === 'separator')       return Oktest_Color::red($s);
        if ($kind === 'subject')         return Oktest_Color::bold($s);
        return Oktest_Color::bold_magenta($s);
    }

    function is_tty() {
        //return posix_isatty(STDOUT);
        return true;
    }

    static $registered = array(
        'verbose'   => 'Oktest_VerboseReporter',
        'simple'    => 'Oktest_SimpleReporter',
        'plain'     => 'Oktest_PlainReporter',
    );

    function register_class($style, $klass) {
        self::$registered[$style] = $klass;
    }

    function get_registered_class($style) {
        return isset(self::$registered[$style]) ? self::$registered[$style] : null;
    }

}


class Oktest_VerboseReporter extends Oktest_BaseReporter {

    function enter_testclass($testclass) {
        parent::enter_testclass($testclass);
        echo '* ', $this->colorize($testclass, 'subject'), "\n";
        $this->_testclass = $testclass;
    }

    function exit_testclass($testclass) {
        parent::exit_testclass($testclass);
        $this->_testclass = null;
    }

    function enter_testcase($testcase, $testname) {
        parent::enter_testcase($testcase, $testname);
        $ref = new ReflectionMethod($this->_testclass, $testname);
        $filepath = $ref->getFileName();
        $lineno = $ref->getStartLine();
        $linestr = $this->fetch_line($filepath, $lineno-1);
        $linestr = trim($linestr);
        $testtitle = $testname;
        $testdesc  = null;
        if (preg_match('/^(?:\/\/+|#+): (.*)/', $linestr, $m)) {
            $testdesc = $m[1];
            if ($testdesc) $testtitle = $testdesc;
        }
        $this->_testdesc = $testdesc;
        if ($this->color && $this->is_tty()) {
            echo '  - [  ] ', $testtitle;
            fflush(STDOUT);
        }
    }

    function exit_testcase($testcase, $testname, $status, $exception) {
        $testdesc = $this->_testdesc;
        $this->_testdesc = null;
        if ($testdesc) {
            $testtitle = $testdesc;
            $testname .= "  # ".$testdesc;
        }
        else {
            $testtitle = $testname;
        }
        parent::exit_testcase($testcase, $testname, $status, $exception);
        if ($this->color && $this->is_tty()) {
            $this->_clear_line();
            fflush(STDOUT);
        }
        $indicator = $this->indicator($status, true);
        echo '  - [', $indicator, '] ', $testtitle, "\n";
    }

    function _clear_line() {
        //$s = "\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b\b";
        $s = "\010\010\010\010\010\010\010\010\010\010\010\010\010\010\010\010\010\010\010\010";
        echo $s, $s, $s, $s, $s, $s, $s, $s, $s, $s;
    }

}


class Oktest_SimpleReporter extends Oktest_BaseReporter {

    function enter_testclass($testclass) {
        parent::enter_testclass($testclass);
        echo '* ', $this->colorize($testclass, 'subject'), ': ';
    }

    function exit_testclass($testclass) {
        echo "\n";
        parent::exit_testclass($testclass);
    }

    function exit_testcase($testcase, $testname, $status, $exception) {
        parent::exit_testcase($testcase, $testname, $status, $exception);
        echo $this->status_char($status, true);
        fflush(STDOUT);
    }

}


class Oktest_PlainReporter extends Oktest_BaseReporter {

    function exit_testclass($testclass) {
        if ($this->_tuples) {
            echo "\n";
        }
        parent::exit_testclass($testclass);
    }

    function exit_all() {
        echo "\n";
        parent::exit_all();
    }

    function exit_testcase($testcase, $testname, $status, $exception) {
        parent::exit_testcase($testcase, $testname, $status, $exception);
        echo $this->status_char($status, true);
        fflush(STDOUT);
    }

}


class Oktest_Color {

    function bold($s)    { return "\033[0;1m".$s."\033[22m"; }
    //
    function black($s)   { return "\033[0;30m".$s."\033[0m"; }
    function red($s)     { return "\033[0;31m".$s."\033[0m"; }
    function green($s)   { return "\033[0;32m".$s."\033[0m"; }
    function yellow($s)  { return "\033[0;33m".$s."\033[0m"; }
    function blue($s)    { return "\033[0;34m".$s."\033[0m"; }
    function magenta($s) { return "\033[0;35m".$s."\033[0m"; }
    function cyan($s)    { return "\033[0;36m".$s."\033[0m"; }
    function white($s)   { return "\033[0;37m".$s."\033[0m"; }
    //
    function bold_black($s)   { return "\033[1;30m".$s."\033[0m"; }
    function bold_red($s)     { return "\033[1;31m".$s."\033[0m"; }
    function bold_green($s)   { return "\033[1;32m".$s."\033[0m"; }
    function bold_yellow($s)  { return "\033[1;33m".$s."\033[0m"; }
    function bold_blue($s)    { return "\033[1;34m".$s."\033[0m"; }
    function bold_magenta($s) { return "\033[1;35m".$s."\033[0m"; }
    function bold_cyan($s)    { return "\033[1;36m".$s."\033[0m"; }
    function bold_white($s)   { return "\033[1;37m".$s."\033[0m"; }
    //
    function _colorize($str) {  // for test data
        $str = preg_replace('/<R>(.*?)<\/R>/', "\033[1;31m\\1\033[0m", $str);
        $str = preg_replace('/<G>(.*?)<\/G>/', "\033[1;32m\\1\033[0m", $str);
        $str = preg_replace('/<Y>(.*?)<\/Y>/', "\033[1;33m\\1\033[0m", $str);
        $str = preg_replace('/<r>(.*?)<\/r>/', "\033[0;31m\\1\033[0m", $str);
        $str = preg_replace('/<b>(.*?)<\/b>/', "\033[0;1m\\1\033[22m", $str);
        return $str;
    }

}


///
/// tracer
///

class Oktest_Tracer {

    function __construct() {
        $this->called = array();
    }

    function _record($object, $method, $args, $return) {
        $this->called[] = new Oktest_CallObject($object, $method, $args, $return);
        return $this;
    }

    function trace($object, $methods=null, $values=null) {
        $args = func_get_args();
        if (count($args) == 0) {
            throw ErrorException("trace() requires target object.");
        }
        $object = array_shift($args);
        $values = null;
        if ($args) {
            $last_index = count($args) - 1;
            $values = is_array($args[$last_index]) ? array_pop($args) : null;
        }
        $methods = $args ? $args : null;
        return new Oktest_WrapperObject($this, $object, $methods, $values);
    }

    function fake($values) {
        return new Oktest_FakeObject($this, $values);
    }

}


class Oktest_CallObject {

    var $object;
    var $method;
    var $args;
    var $ret;

    function __construct($object, $method, $args, $ret) {
        $this->object = $object;
        $this->method = $method;
        $this->args   = $args;
        $this->ret    = $ret;
    }

}


class Oktest_WrapperObject {
    var $_object;
    var $_tracer;
    var $_values;
    var $_methods;

    function __construct($tracer, $object, $methods, $values) {
        $this->_tracer  = $tracer;
        $this->_object  = $object;
        $this->_methods = $methods ? $methods : null;
        $this->_values  = $values !== null ? $values : array();
    }

    function __call($method, $args) {
        $return = array_key_exists($method, $this->_values)
                  ? $this->_values[$method]
                  : call_user_func_array(array($this->_object, $method), $args);
        if ($this->_methods === null || in_array($method, $this->_methods)) {
            $this->_tracer->_record($this->_object, $method, $args, $return);
        }
        return $return;
    }

    function __get($name) {
        return array_key_exists($name, $this->_values)
               ? $this->_values[$name]
               : $this->_object->$name;
    }

    function __set($name, $value) {
        if (array_key_exists($name, $this->_values)) {
            $this->_values[$name] = $value;
        }
        else {
            $this->_object->$name = $value;
        }
    }

    function __isset($name) {
        return array_key_exists($name, $this->_values)
               ? isset($this->_values[$name])
               : isset($this->_object->$name);
    }

    function __unset($name) {
        if (array_key_exists($name, $this->_values)) {
            unset($this->_values[$name]);
        }
        else {
            unset($this->_object->$name);
        }
    }

}


class Oktest_FakeObject {

    var $_values;
    var $_tracer;

    function __construct($tracer, $values) {
        $this->_tracer = $tracer;   // Tracer
        $this->_values = $values;   // dict
    }

    function __call($method, $args) {
        $ret = array_key_exists($method, $this->_values) ? $this->_values[$method] : null;
        $this->_tracer->_record($this, $method, $args, $ret);
        return $ret;
    }

    function __get($name) {
        return $this->_values[$name];
    }

    function __set($name, $value) {
        $this->_values[$name] = $value;
    }

    function __isset($name) {
        return isset($this->_values[$name]);
    }

    function __unset($name) {
        unset($this->_values[$name]);
    }

}


///
/// main
///

class Oktest_MainApp {

    function __construct($script=null) {
        $this->script = $script;
    }

    function _new_cmdopt_parser() {
        $parser = new Cmdopt_Parser();
        $parser->opt('-h', '--help')                       ->desc('show help');
        $parser->opt('-v', '--version')                    ->desc('show version');
        $parser->opt('-s')->name('style')  ->arg('style')  ->desc('reporting style (verbose/simple/plain, or v/s/p)');
        $parser->opt('-p')->name('pattern')->arg('pattern')->desc("file pattern (default '*_test.php')");
        $parser->opt('-f')->name('filter') ->arg('pattern')->desc("filter class and/or method name (see examples below)");
        $parser->opt(      '--color')->arg(null, 'bool')   ;//->desc('enable/disable colorize');
        $parser->opt('-c')->name('color')                  ->desc('enable output color');
        $parser->opt('-C')->name('nocolor')                ->desc('disable output color');
        $parser->opt('-D')->name('debug')                  ->desc('debug mode');
        return $parser;
    }

    function run($args) {
        $parser = $this->_new_cmdopt_parser();
        $opts   = $parser->parse_args($args);
        $filenames = $args;
        //
        if ($opts['debug']) Oktest::$debug = true;
        Oktest::_log('$opts: ', $opts);
        Oktest::_log('$args: ', $args);
        //
        if ($opts['help']) {
            $this->_show_help($parser);
            return 0;
        }
        if ($opts['version']) {
            $this->_show_version();
            return 0;
        }
        //
        if (! $args) {
            //throw new Cmdopt_ParseError("error: missing test script or directory name.");
            echo "*** error: missing test script or directory name.\n";
            $this->_show_help($parser);
            return 1;
        }
        //
        $kwargs = $this->_handle_opts($opts);
        $pattern = $opts['pattern'] ? $opts['pattern'] : '*_test.php';
        Oktest::_log('$kwargs: ', $kwargs);
        //
        foreach ($filenames as $filename) {
            if (is_dir($filename)) {
                $this->_load_recursively($filename, $pattern);
            }
            else {
                require_once($filename);
            }
        }
        //
        $num_errors = Oktest::run($kwargs);
        Oktest::_log('$num_errors: ', $num_errors);
        return $num_errors;
    }

    function _show_help($parser) {
        echo 'Usage: php '.$this->script." [options] file [file2...]\n";
        echo $parser->options_help();
        echo "Example:\n";
        echo "  ## detect test srcripts in plain style\n";
        echo "  $ php Oktest.php -sp tests/*_test.php\n";
        echo "  ## detect all test srcripts under 'test' directory recursively\n";
        echo "  $ php Oktest.php -p '*_test.php' test\n";
        echo "  ## filter by test class name\n";
        echo "  $ php Oktest.php -f 'ClassName*' test\n";
        echo "  ## filter by test method name (starting with 'test')\n";
        echo "  $ php Oktest.php -f 'test_name*' test\n";
        echo "  ## filter by both class and method name\n";
        echo "  $ php Oktest.php -f 'ClassName*.test_name*' test\n";
    }

    function _show_version() {
        echo Oktest::VERSION, "\n";
    }

    function _handle_opts($opts) {
        $kwargs = array();
        if ($opts['style']) {
            $style = $opts['style'];
            $dict = array('v'=>'verbose', 's'=>'simple', 'p'=>'plain');
            if (isset($dict[$style])) $style = $dict[$style];
            $klass = Oktest_BaseReporter::get_registered_class($style);
            if (! $klass) {
                throw new Cmdopt_ParseError("$style: no such reporting style (expected verbose/simple/plain, or v/s/p).");
            }
            $kwargs['style'] = $style;
        }
        if ($opts['color']  ) $kwargs['color']  = true;
        if ($opts['nocolor']) $kwargs['color']  = false;
        if ($opts['filter'] ) $kwargs['filter'] = $opts['filter'];
        return $kwargs;
    }

    function _load_file_if_matched($filepath, $pattern) {
        if (fnmatch($pattern, basename($filepath))) {
            require_once($filepath);
        }
    }

    function _load_recursively($dirpath, $pattern) {
        foreach (scandir($dirpath) as $item) {
            if ($item === '.' || $item === '..') continue;
            $newpath = $dirpath.'/'.$item;
            if (is_dir($newpath)) {
                $this->_load_recursively($newpath, $pattern);
            }
            else if (is_file($newpath)) {
                $this->_load_file_if_matched($newpath, $pattern);
            }
            else {
                // nothing
            }
        }
    }

    function main($argv, $exit=true) {
        $script = array_shift($argv);
        $script = basename($script);
        $this->script = $script;
        try {
            $num_errors = $this->run($argv);
            $status = $num_errors;
        }
        catch (Cmdopt_ParseError $ex) {
            echo $script.': '.$ex->getMessage()."\n";
            $status = 1;
        }
        if ($exit) exit($status);
        return $status;
    }

}


///
/// command option parser
///

class Cmdopt_ParseError extends ErrorException {
}


class Cmdopt_Definition {
    var $opt_short;
    var $opt_long;
    var $name;
    var $arg_name;
    var $arg_type;
    var $arg_optional;
    var $multiple;
    var $desc;

    function get_name() {
        if ($this->name) return $this->name;
        if ($this->opt_long) return $this->opt_long;
        if ($this->opt_short) return $this->opt_short;
        return null;
    }

    function validate($val, $prefix) {
        $type = $this->arg_type;
        if ($type === 'int') {
            if (! preg_match('/^\d+$/', $val)) {
                throw new Cmdopt_ParseError($prefix.": int value expected.");
            }
            $val = intval($val);
        }
        else if ($type === 'float') {
            if (! preg_match('/^\d+\.\d+$/', $val)) {
                throw new Cmdopt_ParseError($prefix.": float value expected.");
            }
            $val = floatval($val);
        }
        else if ($type === 'bool') {
            if (is_bool($val)) return $val;
            if (is_null($val)) return true;
            if (is_string($val)) {
                if ($val === 'true' || $val === 'yes' || $val == 'on') return true;
                if ($val === 'false' || $val === 'no' || $val == 'off') return false;
            }
            throw new Cmdopt_ParseError($prefix.": boolean value (true or false) expected.");
        }
        return $val;   // TODO
    }

}


class Cmdopt_Builder {

    function __construct($def=null) {
        if (! $def) $def = new Cmdopt_Definition();
        $this->_def = $def;
    }

    function opt($opt1, $opt2=null) {
        foreach (array($opt1, $opt2) as $opt) {
            if (! $opt) continue;
            if (preg_match('/^-\w$/', $opt)) {
                $this->_def->opt_short = $opt{1};
            }
            else if (preg_match('/^--(\w[-\w]*)$/', $opt)) {
                $this->_def->opt_long = substr($opt, 2);
            }
            else {
                throw new ErrorException("'".$opt."': unexpected option.");
            }
        }
        return $this;
    }

    function name($name) {
        $this->_def->name = $name;
        return $this;
        return $this;
    }

    function arg($name, $type=null) {
        $this->_def->arg_name = $name;
        if ($type && ! in_array($type, array('str', 'int', 'float', 'bool'))) {
            throw new ErrorException("'".$type."': unknown arg type (str/int/float/bool).");
        }
        $this->_def->arg_type = $type;
        return $this;
    }

    function optional() {
        $this->_def->arg_optional = true;
        return $this;
    }

    function multiple() {
        $this->_def->multiple = true;
        return $this;
    }

    function desc($desc) {
        $this->_def->desc = $desc;
        return $this;
    }

}


class Cmdopt_Parser {

    function __construct() {
        $this->_defs = array();
    }

    function opt($opt1, $opt2=null) {
        $def = new Cmdopt_Definition();
        $this->_defs[] = $def;
        $builder = new Cmdopt_Builder($def);
        return $builder->opt($opt1, $opt2);
    }

    function _get_def_by_short_name($name) {
        foreach ($this->_defs as $def) {
            if ($def->opt_short === $name) return $def;
        }
        return null;
    }

    function _get_def_by_long_name($name) {
        foreach ($this->_defs as $def) {
            if ($def->opt_long === $name) return $def;
        }
        return null;
    }

    function _new_opts() {
        $opts = array();
        foreach ($this->_defs as $def) {
            $opts[$def->get_name()] = null;
        }
        return $opts;
    }

    function parse_args(&$args) {
        $opts = $this->_new_opts();
        while ($args) {
            $arg = $args[0];
            if ($arg === '--') break;
            if (! $arg) break;
            if ($arg{0} !== '-') break;
            $arg = array_shift($args);
            if (preg_match('/^--(\w[-\w]*)(?:=(.*))?$/', $arg, $m)) {
                $this->_parse_long_opt($opts, $arg, $m);
            }
            else if (preg_match('/^-/', $arg)) {
                $optstr = substr($arg, 1);
                $this->_parse_short_opts($opts, $optstr, $args);
            }
            else {
                break;
            }
        }
        return $opts;
    }

    function _parse_long_opt(&$opts, $arg, $m) {
        $name = $m[1];
        //$opt_val  = $m[2] === null ? true : $this->_str2value($m[2]);
        $val  = isset($m[2]) ? $m[2] : true;
        $def  = $this->_get_def_by_long_name($name);
        if (! $def) {
            throw $this->_err('--'.$name.': unknown option.');
        }
        $val = $def->validate($val, $arg);
        $opts[$def->get_name()] = $val;
    }

    function _parse_short_opts(&$opts, $optstr, &$args) {
        $len = strlen($optstr);
        for ($j = 0; $j < $len; $j++) {
            $ch = $optstr{$j};
            $def = $this->_get_def_by_short_name($ch);
            if (! $def) {
                throw $this->_err('-'.$ch.': unknown option.');
            }
            $dname = $def->get_name();
            if (! $def->arg_name) {
                $opts[$dname] = true;
            }
            else if ($def->arg_optional) {
                if ($j+1 === $len) {
                    $opts[$dname] = true;
                }
                else {
                    $val = substr($optstr, $j+1);
                    $opts[$dname] = $def->validate($val, '-'.$ch.' '.$val);
                }
                break;
            }
            else {
                if ($j+1 === $len) {
                    if (! $args) {
                        throw $this->_err('-'.$ch.': argument required.');
                    }
                    $val = array_shift($args);
                }
                else {
                    $val = substr($optstr, $j+1);
                }
                $opts[$dname] = $def->validate($val, '-'.$ch.' '.$val);
                break;
            }
        }
    }

    function _err($msg) {
        return new Cmdopt_ParseError($msg);
    }

    function _str2value($str) {
        if ($str === 'true' || $str === 'yes' || $str === 'on') return true;
        if ($str === 'false' || $str === 'no' || $str === 'off') return false;
        if ($str === 'null') return null;
        if (preg_match('/^\d+$/'))     return intval($str);
        if (preg_match('/^\d+.\d+$/')) return floatval($str);
        return $str;
    }

    function options_help($width=null) {
        $pairs = array();
        foreach ($this->_defs as $def) {
            if (! $def->desc) continue;
            $short = $def->opt_short;
            $long  = $def->opt_long;
            $arg   = $def->arg_name;
            $s = $def->arg_type === 'bool' ? '[=false]' : '';
            if ($short && $long) {
                $s = $arg ? "-$short, --${long}=$arg"
                          : "-$short, --${long}$s";
            }
            else if ($short) {
                $s = $arg ? "-$short $arg"
                          : "-$short";
            }
            else if ($long) {
                $s = $arg ? "    --${long}=$arg"
                          : "    --${long}$s";
            }
            $pairs[] = array($s, $def->desc);
        }
        if (! $width) {
            $width = 7;  // minimum
            foreach ($pairs as $pair) {
                $w = strlen($pair[0]) + 2;
                if ($width < $w) $width = $w;
            }
        }
        $format = "  %-".$width."s: %s\n";
        $buf = array();
        foreach ($pairs as $pair) {
            $buf[] = sprintf($format, $pair[0], $pair[1]);
        }
        return join('', $buf);
    }

}


if (isset($argv[0]) && basename($argv[0]) === basename(__FILE__)) {
    $app = new Oktest_MainApp();
    $app->main($argv);
}
