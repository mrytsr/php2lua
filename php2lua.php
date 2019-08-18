#!/usr/bin/env php
<?php
include 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;
use Symfony\Component\Yaml\Yaml;

$var_reg = [];

function to_snake($str){
    $dstr = preg_replace_callback('/([A-Z]+)/',function($matchs)
    {
        return '_'.strtolower($matchs[0]);
    },$str);
    return trim(preg_replace('/_{2,}/','_',$dstr),'_');
}

// $code = code_merge('ni', ['hao', 'ii'], ['xxii', 'oo']);
function cm(){
    $lines = func_get_args();
    $left = [];
    if(isset($lines[0])){
        $left = $lines[0];
    }
    if(is_string($left)){
        $left = [$left];
    }elseif(!is_array($left)){
        var_dump($lines);
        throw new \Exception('CODE_MERGE_LEFT_ERROR');
    }
    if(!isset($lines[1])){
        return $left;
    }

    $right = $lines[1];
    if(is_array($right) and empty($right)){
        $right = '';
    }
    if(is_string($right)){
        $right = [$right];
    }elseif(!is_array($right)){
        throw new \Exception('CODE_MERGE_RIGHT_ERROR');
    }
    $i = 0;
    if(count($left) and is_string($left[count($left)-1]) and is_string($right[0])){
       $left[count($left)-1] .= $right[0];
       $i = 1;
    }
    for(; $i < count($right); $i++){
        $left[] = $right[$i];
    }
    $lines2 = [$left];
    for($i = 2; $i < count($lines); $i++){
        $lines2[] = $lines[$i];
    }
    return call_user_func_array('cm', $lines2);
}

// code_implode(', ', ['function', 'hello', 'world'])
function ci($del, $code){
    $code2 = [];
    if(!is_array($code)){
        return $code;
    }
    foreach($code as $i => $c){
        if($c != '' and $c != null){
            $code2[] = $c;
            if($i != count($code) - 1){
                $code2[] = $del;
            }
        }
    }

    return call_user_func_array('cm', $code2);
}

function render_op($left, $op, $right){
    if($left['nodeType'] == 'Expr_Variable' or preg_match('/^Scalar_/', $left['nodeType'])){
        $left = render_stmt($left);
    }else{
        $left = cm('(', render_stmt($left), ')');
    }
    if($right['nodeType'] == 'Expr_Variable' or preg_match('/^Scalar_/', $right['nodeType'])){
        return cm($left, $op, render_stmt($right));
    }else{
        return cm($left, $op, '(', render_stmt($right), ')');
    }
}

function dump($obj, $depth = 4){
    echo Yaml::dump($obj, $depth);
}

function render_args($stmt_args){
    $args = array_map(function($item){
        return render_stmt($item);
    }, $stmt_args);
    return ci(', ', $args);
}

function render_params($stmt_params){
    $params = array_map(function($item){
        return $item['name'];
    }, $stmt_params);
    return ci(', ', $params);
}

function render_stmt($stmt, $father = null){
    global $var_reg;
    if(is_string($stmt)){
        return [$stmt];
    }elseif(is_array($stmt) and empty($stmt)){
        return [];
    }

    $nodeType = isset($stmt['nodeType'])?$stmt['nodeType']:null;

    if(!isset($stmt['nodeType'])){
        $code = [];
        $return = 0;
        foreach($stmt as $s){
            if($s['nodeType'] == 'Stmt_Return'){
                if($return){
                    $s['nodeType'] = 'Stmt_Return2';
                }
                $return++;
            }
            if(isset($s['attributes']['comments'])){
                $code = array_merge($code, render_stmt($s['attributes']['comments'], $stmt));
            }
            $code = array_merge($code, render_stmt($s, $stmt));
        }
        return $code;
    }
    $nodeType = $stmt['nodeType'];

    switch($nodeType){

    case 'Expr_Isset':
        $vars = array_map(function($item){
            return render_stmt($item);
        }, $stmt['vars']);
        return ci(', ', $vars);
    case 'Stmt_Unset':
        $vars = array_map(function($item){
            return render_stmt($item);
        }, $stmt['vars']);
        return cm('-- unset(', ci(', ', $vars), ')');
    case 'Const':
        $name = $stmt['name'];
        $value = render_stmt($stmt['value']);
        return cm('_M.', $name, ' = ', $value);
    case 'Expr_ClassConstFetch':
        $class = render_stmt($stmt['class']);
        $name = $stmt['name'];
        return cm($class, '.', $name);
    case 'Stmt_ClassConst':
        return array_map(function($const){
            return render_stmt($const);
        }, $stmt['consts']);
    case 'Scalar_MagicConst_Function':
        return ['-- Scalar_MagicConst_Function'];
    case 'Stmt_Continue':
        return ['goto continue'];
    case 'Stmt_Global':
        $vars = array_map(function($var){
            $name = $var['name'];
            return cm($name, ' = ', $name);
        },$stmt['vars']);
        return cm('local ', ci(', ', $vars));
    case 'Stmt_Static':
        $arr = [];
        $lines = array_map(function($var){
            $default = render_stmt($var['default']);
            $name = $var['name'];
            return cm(
                'if not ', $name, ' then',
                [cm($name, ' = ', $default)],
                'end'
            );
        },$stmt['vars']);
        foreach($lines as $line){
            foreach($line as $l){
                $arr[] = $l;
            }
        }
        return $arr;
    case 'Expr_Exit':
        return ['ngx.exit(0)'];
    case 'Expr_Closure':
        return cm(
            'function(', render_params($stmt['params']), ')',
                [render_stmt($stmt['stmts'])],
                'end'
            );
    case 'Expr_PropertyFetch':
        $var = render_stmt($stmt['var']);
        $name = $stmt['name'];
        if($var == 'this'){
            $var = 'self';
        }
        return cm($var, '.', $name);
    case 'Expr_AssignOp_Minus':
        $var = render_stmt($stmt['var']);
        $expr = render_stmt($stmt['expr']);
        return cm($var, ' = ', $var, ' - ', $expr);
    case 'Expr_AssignOp_Plus':
        $var = render_stmt($stmt['var']);
        $expr = render_stmt($stmt['expr']);
        return cm($var, ' = ', $var, ' + ', $expr);
    case 'Expr_AssignOp_Concat':
        $var = render_stmt($stmt['var']);
        $expr = render_stmt($stmt['expr']);
        return cm($var, ' = ', $var, '..', $expr);
    case 'Stmt_PropertyProperty':
        $name = $stmt['name'];
        if($stmt['default']){
            return cm($name, ' = ', render_stmt($stmt['default']));
        }else{
            return cm($name, ' = nil');
        }
    case 'Stmt_Property':
        $flags = $stmt['flags'];
        $props = array_map(function($prop) use($flags){
            return render_stmt($prop);
        }, $stmt['props']);
        switch($flags){
        case 12:
        case 4:
            return cm('local ', ci(', ', $props));
        default:
            return cm('-- NYI: Stmt_Property_', $flags);
        }
    case 'Expr_StaticPropertyFetch':
        return $stmt['name'];
    case 'Name_FullyQualified':
        return ci('.', $stmt['parts']);
    case 'Expr_Empty':
        return cm('lup.empty(', render_stmt($stmt['expr']), ')');
    case 'Expr_ErrorSuppress':
        return cm('lup.a(', render_stmt($stmt['expr'], $nodeType), ')');
    case 'Stmt_Break':
        foreach($father as $s){
            if($s['nodeType'] == 'Stmt_Return'){
                return [];
            }
        }
        return ['goto _break'];
    case 'Stmt_Switch':
        $cond = render_stmt($stmt['cond']);

        $cases = [];
        $conds = [];
        foreach($stmt['cases'] as $case){

            if($case['cond']){
                $conds[] = cm('[', render_stmt($case['cond']), ']=1');
            }
            if($case['stmts']){
                if($conds){
                    $cases[] = cm('if ({', ci(', ', $conds), '})[', $cond, '] then');
                }else{
                    $cases[] = cm('if true then');
                }
                $cases[] = cm(
                    [render_stmt($case['stmts'])], 
                    'end'
                );
                $conds = [];
            }

        }

        $cases[] = ['::_break::'];
        $arr = [];
        $arr[] = '-- switch => do-end';
        $arr[] = 'do';
        $arr = array_merge($arr, $cases);
        $arr[] = 'end';
        return $arr;

    case 'Scalar_Encapsed':
        $parts = array_map(function($part){
            return render_stmt($part);
        }, $stmt['parts']);
        return ci('..', $parts);
    case 'Expr_New':
        $class = render_stmt($stmt['class']);
        $args =  render_args($stmt['args']);
        return cm($class, '.new(', $args, ')');
    case 'Expr_FuncCall':
        $name = render_stmt($stmt['name']);
        $args = render_args($stmt['args']);
        if(@$father['nodeType'] == 'Expr_ErrorSuppress'){
            return cm($name, ', ', $args, ')');
        }else{
            return cm($name, '(', $args, ')');
        }
    case 'Expr_MethodCall':
        $var  = render_stmt($stmt['var']);
        $name = $stmt['name'];
        if(is_string($name)){
            $name = ["'$name'"];
        }else{
            $name = render_stmt($stmt['name']);
        }
        $args = render_args($stmt['args']);
        if(preg_match('/^\'[_a-zA-Z][_a-zA-Z0-9]*\'$/', $name[0])){
            return cm($var, ':', trim($name[0], '\''), '(', $args, ')');
        }else{
            array_unshift($args, $var);
            return cm($var, '[', $name, '](', ci(', ', $args), ')');
        }
    case 'Expr_StaticCall':
        $class  = render_stmt($stmt['class']);
        $name = render_stmt($stmt['name']);
        if($class[0] == 'self'){
            $class[0] = '_M';
        }
        if($class[0] == 'xconfig'){
            $args_count = count($stmt['args']);
            if($args_count == 1){
                return cm('config[', render_args($stmt['args']), ']');
            }elseif($args_count == 2){
                return cm('config[', render_args([$stmt['args'][0]]), '] or ', render_args([$stmt['args'][1]]));
            }else{
                throw new \Exception('CONFGI_GET_ARGS_MORE_THAN_2');
            }
        }else{
            $args = render_args($stmt['args']);
            if(preg_match('/^[_a-zA-Z][_a-zA-Z0-9]*$/', $name[0])){
                return cm($class, '.', trim($name[0], '\''), '(', $args, ')');
            }else{
                return cm($class, '[', $name, '](', $args, ')');
            }
        }
    case 'Expr_Ternary':
        $cond = render_stmt($stmt['cond']);
        $if = render_stmt($stmt['if']);
        $else = render_stmt($stmt['else']);
        return cm($cond, ' and ', $if, ' or ', $else);
    case 'Stmt_Class':
        $name = $stmt['name'];
        $stmts = render_stmt($stmt['stmts']);
        $arr = cm('', 'local _M = {}');
        foreach($stmts as $s){
            $arr[] = $s;
        }
        return array_merge($arr, ['return _M']);
    case 'Name':
        $parts = array_map(function($part){
            switch($part){
            case 'array_filter':
            case 'array_map':
            case 'array_reduce':
            case 'array_merge':
            case 'array_column':
            case 'mt_rand':
            case 'array_sum':
            case 'in_array':
            case 'dirname':
            case 'filter_var':
            case 'array_walk':
            case 'date_default_timezone_set':
            case 'getallheaders':
            case 'http_response_code':
            case 'intval':
            case 'json_decode':
            case 'json_encode':
            case 'JSON_UNESCAPED_SLASHES':
            case 'JSON_UNESCAPED_UNICODE':
            case 'ksort':
            case 'microtime':
            case 'parse_url':
            case 'parse_url':
            case 'pathinfo':
            case 'PATHINFO_BASENAME':
            case 'PATHINFO_FILENAME':
            case 'PHP_URL_PATH':
            case 'preg_match':
            case 'var_dump':
            case 'ucwords':
            case 'str_replace':
            case 'header':
            case 'uniqid':
            case 'substr':
            case 'date':
            case 'base_convert':
            case 'sprintf':
            case 'FILTER_VALIDATE_IP':
            case 'PATHINFO_EXTENSION':
            case 'time':
            case 'strtolower':
                return cm('lup.', $part);
            case 'sleep':
            case 'md5':
                return cm('ngx.', $part);
            case 'http_build_query':
                return 'ngx.encode_args';
            case 'Config':
            case 'Log':
            case 'Client':
            case 'Ctx':
            case 'Utils':
            case 'Cfile':
            case 'Msg':
            default:
                return to_snake($part);
            }
        }, $stmt['parts']);
        /* if($parts[0] == 'lib.config'){ */
        /*     return ['config.config']; */
        /* } */
        return ci('.', $parts);
    case 'Stmt_Throw':
        $expr = $stmt['expr'];
        if($expr['nodeType'] == 'Expr_New'){
            $error = render_args($expr['args']);
        }else{
            $error = $expr['name'];
        }
        return cm('error(', $error, ')');
    case 'Stmt_Catch':
        $types = render_stmt($stmt['types'][0]);
        $var = $stmt['var'];
        return cm(', function(', $var, ')',
            [render_stmt($stmt['stmts'])],
            'end'
        );
    case 'Stmt_TryCatch':
        $caches = render_stmt($stmt['catches'][0]);
        foreach($caches as $cache){
            $arr[] = $cache;
        }
        return cm(
            'local ok, ret = xpcall(function()',
                [render_stmt($stmt['stmts'])],
                'end', $caches, ')'
            );
    case 'Stmt_Echo':
        $exprs = array_map(function($item){
            return render_stmt($item);
        }, $stmt['exprs']);
        return cm('ngx.print(', ci(', ', $exprs), ')');
    case 'Stmt_UseUse':
        return render_stmt($stmt['name']);
    case 'Stmt_Use':
        $uses = array_map(function($use)use($nodeType){
            $name = render_stmt($use, $nodeType);
            $names = explode('.', $name[0]);
            $lastname = $names[count($names)-1];
            return cm('local ', $lastname, ' = require \'', $name, '\'');
        }, $stmt['uses']);
        return ci(',', $uses);
    case 'Expr_Include':
        $expr = $stmt['expr'];
        return cm('-- require(', render_stmt($expr), ')');
    case 'Expr_ArrayDimFetch':
        $dim = $stmt['dim'];
        if($dim){
            $dim = render_stmt($dim);
        }else{
            $dim = [''];
        }
        $var = render_stmt($stmt['var']);
        if(preg_match('/^\'[_a-zA-Z][_a-zA-Z0-9]*\'$/', $dim[0])){
            $var = cm($var, '.', trim($dim[0], '\''));
        }else{
            $var = cm($var, '[', $dim, ']');
        }
        return $var;
        dump($stmt);
        return [];
    case 'Stmt_Nop':
        return [];
    case 'Comment':
    case 'Comment_Doc':
        $count = 0;
        $ret = ltrim($stmt['text']);
        $ret = preg_replace('@\*/$@', ' ]]--', rtrim($ret), 1, $count);
        if($count){
            $ret = preg_replace('@^/\*@', '--[[ ', $ret, 1, $count);
        }else{
            $ret = preg_replace('@^//@', '-- ', ltrim($ret), 1, $count);
        }
        return [$ret];
    case 'Expr_UnaryMinus':
        return '-'.render_stmt($stmt['expr']);
    case 'Expr_ConstFetch':
        $name = render_stmt($stmt['name']);
        if($name[0] == 'null'){
            $name[0] = 'nil';
        }
        return $name;
    case 'Stmt_Return':
        if($stmt['expr']){
            $expr = render_stmt($stmt['expr']);
            return cm('return ', $expr);
        }else{
            return ['return nil'];
        }
    case 'Stmt_Return2':
        if($stmt['expr']){
            $expr = render_stmt($stmt['expr']);
            return cm('-- return ', $expr);
        }else{
            return ['-- return nil'];
        }
    case 'Stmt_Foreach':
        $key = $stmt['keyVar'];
        if(!$key){
            $key = '_';
        }else{
            $key = $stmt['keyVar']['name'];
        }
        $val = $stmt['valueVar']['name'];
        return cm(
            'for ', $key, ', ', $val, ' in ipairs(', render_stmt($stmt['expr']), ') do',
            [render_stmt($stmt['stmts'])],
            'end'
        );
    case 'Stmt_Do':
        $stmts = render_stmt($stmt['stmts']);
        array_unshift($stmts,  '::continue::');
        array_push($stmts, '::_break::');
        return cm(
            'repeat',
            [$stmts],
            'until ', render_stmt($stmt['cond'])
        );
    case 'Stmt_While':
        $stmts = render_stmt($stmt['stmts']);
        array_unshift($stmts,  '::continue::');
        array_push($stmts, '::_break::');
        return cm(
            'while ', render_stmt($stmt['cond']), ' do', 
            [$stmts],
            'end'
        );
    case 'Stmt_For':
        $stmts = render_stmt($stmt['stmts']);
        array_unshift($stmts,  '::continue::');
        array_push($stmts, '::_break::');
        return array_merge(['-- for => while'], render_stmt($stmt['init'][0]), cm(
            'while ', render_stmt($stmt['cond'][0]), ' do', 
            [$stmts],
            [render_stmt($stmt['loop'][0])],
            'end'
        ));
    case 'Stmt_ElseIf':
        $cond = render_stmt($stmt['cond']);
        return cm(
            'elseif ', $cond, ' then', 
            [render_stmt($stmt['stmts'])]
        );
    case 'Stmt_Else':
        return cm(
            'else',
            [render_stmt($stmt['stmts'])]
        );
    case 'Stmt_If':
        $cond = render_stmt($stmt['cond']);

        $elseifs = [];
        if($stmt['elseifs']){
            $elseifs = array_reduce($stmt['elseifs'], function($carry, $elseif){
                foreach(render_stmt($elseif) as $line){
                    $carry[] = $line;
                }
                return $carry;
            }, []);
        }
        $else = [];
        if($stmt['else']){
            $else = render_stmt($stmt['else']);
        }

        return cm(
            'if ', $cond, ' then', 
            [render_stmt($stmt['stmts'])], 
            $elseifs,
            $else,
            'end'
        );

    case 'Expr_PostDec':
        $name = $stmt['var']['name'];
        return cm($name, ' = ', $name, ' - 1');
    case 'Expr_PreInc':
    case 'Expr_PostInc':
        $name = $stmt['var']['name'];
        return cm($name, ' = ', $name, ' + 1');
    case 'Expr_BitwiseNot':
        return cm('bit.bnot(', render_stmt($stmt['expr']), ')');
    case 'Expr_BinaryOp_BitwiseAnd':
        return cm('bit.band(', render_stmt($stmt['left']), ', ', render_stmt($stmt['right']), ')');
    case 'Expr_BinaryOp_BitwiseOr':
        return cm('bit.bor(', render_stmt($stmt['left']), ', ', render_stmt($stmt['right']), ')');
    case 'Expr_BinaryOp_GreaterOrEqual':
        return render_op($stmt['left'], ' >= ', $stmt['right']);
    case 'Expr_BinaryOp_NotEqual':
    case 'Expr_BinaryOp_NotIdentical':
        return render_op($stmt['left'], ' ~= ', $stmt['right']);
    case 'Expr_BinaryOp_SmallerOrEqual':
        return render_op($stmt['left'], ' <= ', $stmt['right']);
    case 'Expr_BinaryOp_Div':
        return render_op($stmt['left'], ' / ', $stmt['right']);
    case 'Expr_BinaryOp_LogicalOr':
    case 'Expr_BinaryOp_BooleanOr':
    case 'Expr_BinaryOp_Coalesce':
        return render_op($stmt['left'], ' or ', $stmt['right']);
    case 'Expr_BinaryOp_Plus':
        return render_op($stmt['left'], ' + ', $stmt['right']);
    case 'Expr_BinaryOp_Mod':
        return render_op($stmt['left'], ' % ', $stmt['right']);
    case 'Expr_BinaryOp_Mul':
        return render_op($stmt['left'], ' * ', $stmt['right']);
    case 'Expr_BinaryOp_Smaller':
        return render_op($stmt['left'], ' < ', $stmt['right']);
    case 'Expr_BinaryOp_Greater':
        return render_op($stmt['left'], ' > ', $stmt['right']);
    case 'Expr_BinaryOp_Minus':
        return render_op($stmt['left'], ' - ', $stmt['right']);
    case 'Expr_BooleanNot':
        return cm('not ', render_stmt($stmt['expr']));
    case 'Expr_BinaryOp_LogicalAnd':
    case 'Expr_BinaryOp_BooleanAnd':
        return render_op($stmt['left'], ' and ', $stmt['right']);
    case 'Expr_BinaryOp_Identical':
    case 'Expr_BinaryOp_Equal':
        return render_op($stmt['left'], ' == ', $stmt['right']);
    case 'Expr_BinaryOp_Concat':
        return render_op($stmt['left'], '..', $stmt['right']);
    case 'Expr_Print':
        return cm('ngx.print(', render_stmt($stmt['expr']), ')');
    case 'Stmt_Namespace':
        return render_stmt($stmt['stmts']);
    case 'Expr_Variable':
        $name = $stmt['name'];
        switch($name){
        case '_SERVER':
        case '_GET':
        case '_COOKIE':
        case '_REQUEST':
        case '_POST':
            $name = cm('lup.', $name);
            break;
        case 'this':
            $name = ['self'];
            break;
        default:
            break;
        }
        return $name;
    case 'Scalar_DNumber':
    case 'Scalar_LNumber':
        return strval($stmt['value']);
    case 'Arg':
        return render_stmt($stmt['value']);
    case 'Expr_ArrayItem':
        $value = render_stmt($stmt['value']);
        if(empty($stmt['key'])){
            return cm($value, ',');
        }else{
            $key = render_stmt($stmt['key'], $stmt);
            if(preg_match('/^\'[_a-zA-Z][_a-zA-Z0-9]*\'$/', $key[0])){
                return cm(trim($key[0], '\''), ' = ', $value, ',');
            }else{
                return cm('[', $key, '] = ', $value, ',');
            }
        }
    case 'Expr_Array':
        $items = array_map(function($item){
            return render_stmt($item);
        }, $stmt['items']);
        return cm('{', $items, '}');
    case 'Scalar_EncapsedStringPart':
    case 'Scalar_String':
        $value = $stmt['value'];
        $value = preg_replace('/\n/', '\n', $value);
        $value = preg_replace('@\\\@', '\\\\\\', $value);
        return ["'$value'"];
    case 'Expr_Cast_Int':
        return cm('tonumber(', render_stmt($stmt['expr']), ')');
    case 'Expr_Assign':
        $var = render_stmt($stmt['var']);
        $expr = render_stmt($stmt['expr']);
        if(!isset($var_reg[$var[0]])){
            $var_reg[$var[0]] = true;
            if(preg_match('/^[_a-zAz][_0-9a-zAz]*$/', $var[0])){
                return cm('local ', $var, ' = ', $expr);
            }
        }
        return cm($var, ' = ', $expr);
    case 'Stmt_ClassMethod':
        $var_reg = [];
        $flags = $stmt['flags'];
        $name = $stmt['name'];
        $params = render_params($stmt['params']);
        $stmts = [render_stmt($stmt['stmts'])];
        if($name == '__construct'){
            $name = 'new';
            array_unshift($stmts, [
                '_M.__index = _M',
                'local self = {}',
            ]);
            $stmts[] = ['return setmetatable(self, _M)'];
            $flags = 0;
        }

        switch($flags){
        case 12:
        case 0:
        case 9: 
        case 8: // static_func
            return cm(
                'function _M.', $name, '(', $params, ')',
                $stmts,
                'end'
            );
        case 1:
            array_unshift($params, 'self');
            return cm(
                'function _M.', $name, '(', ci(', ', $params), ')',
                $stmts,
                'end'
            );
        case 4:
            return cm(
                'local ', $name, ' = function(', $params, ')',
                    $stmts,
                    'end'
                );
        default:
            return ['NYI: '.$nodeType.'_'.$flags];
        }
    case 'Stmt_Function':
        $var_reg = [];
        $name = $stmt['name'];
        return cm(
            'function ', $name, '(', render_params($stmt['params']), ')',
            [render_stmt($stmt['stmts'])],
            'end'
        );
    case 'Scalar_MagicConst_Dir':
        return ;
    default:
        $startLine = $stmt['attributes']['startLine'];
        $endLine = $stmt['attributes']['endLine'];
        global $file;
        $lines = file($file);

        echo "NYI: $file $nodeType\n";
        for($l = $startLine - 1; $l < $endLine; $l++){
            echo 'NYI: '.$lines[$l];
        }
        return ["NYI: $nodeType"];
    }
}

function dumpcode($code, $indent){
    $i = $indent;
    if(is_string($code)){
        while(--$i > 0){
            echo '    ';
        }
        echo $code."\n";
    }else{
        foreach($code as $sub_code){
            dumpcode($sub_code, $indent + 1);
        }
    }
}

try {
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    $path = $argv[1];
    if(!file_exists($path)){
        echo "FILE_NOT_EXISTS: $path\n";        
        return;
    }

    $output_dir = isset($argv[2])?$argv[2]:null;

    $files = [];
    if(is_file($path)){
        $files = [$path];
    }else{
        $files = array_map(function($file)use($path){
            return "$path/$file";
        }, scandir($path));
    }
    array_map(function($f)use($parser, $path, $output_dir){
        global $file;
        $file = $f;
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        $filename = pathinfo($f, PATHINFO_FILENAME);
        if($ext == 'php'){
            $ast = json_decode(json_encode($parser->parse(file_get_contents($f))), true);
            $code = render_stmt($ast);

            ob_start();
            echo "#!/usr/bin/env resty\n";
            echo "--\n";
            echo "-- tjx@20190815\n";
            echo "-- php2lua: $f\n";
            echo "--\n";
            echo "local lup = require 'lib.lup'\n";
            dumpcode($code, 0);
            $buffer = ob_get_clean();

            if(!$output_dir){
                echo $buffer;
                return;
            }
            $output_file = $output_dir.'/'.to_snake($filename).'.lua';
            if(file_exists($output_file)){
                $output_file = $output_dir.'/'.to_snake($filename).'.php.lua';
                echo "IGNORED: $f\t=> $output_file\n";
            }else{
                echo "PHP2LUA: $f\t=> $output_file\n";
                file_put_contents($output_file, $buffer);
            }
        }
    }, $files);
} catch (PhpParser\Error $e) {
    echo "\nPARSE_ERROR: \n\n    ", $e->getMessage(), "\n";
}

