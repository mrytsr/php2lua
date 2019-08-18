<?php

include 'vendor/autoload.php';

class Test{
    function __construct($arg1, $arg2){
        //commet3
    }

    private function private_func($arga){

        switch($a){
        case 'xxx':
        case 'yyy':
        case 'zz':
        case 123:
            echo 'ii';
            return;
            break;
        default :
            echo 'iii';
            break;
        }
        echo 'private_func';
    }
    static function static_func($arg1){
    }
    public static function public_static_func($default_param = 'default_param_val'){
        echo 'static_func';
    }
    private static function private_static_func($arga){
        echo 'private_static_func';
    }
    public function public_func($arg){
        for($i = 0; $i < 10; $i++){
            print($i);
        }
    }
    function func($arga){



$ctrl_name = 'ctrl\\'.str_replace(
    ' ', '',
    ucwords(str_replace('_', ' ', (isset($params['r']) ? $params['r'] : 'bili')))
);

        $arr = [
            'ni',
            'hao',
        ];
        $callback = function($item){
            return $item.'_sufix';
        };
        array_walk($arr, $callback);

        $str1 = '!!!';
        $str = 'Hello '."World$str1".implode('@', explode($arr));

        $this->private_func('nih');
        self::private_static_func('iii');

        $upload_params = [
            'nihao'
        ];
        $post_params = [
            'ts' => time(),
            'buid' => 'XcodeLab:' . $upload_params['xcode_purpose'],
            'object' => [
                'md5' => $upload_params['xcode_md5'],
                'filesize' => (int)$upload_params['xcode_filesize'],
                'max_speed' => $upload_params['xcode_max_speed'] ? $upload_params['xcode_max_speed'] : '0',
                'object_id' => $filename
            ],
            'filename' => $filename,
            'callback' => Config::get('executor_url') . '?content_type=json&r=' . $this->executor() . '&method=api_notify&filename=' . $filename,
            'priority' => $xcode_priority ? $xcode_priority : 3,
            'role' => isset($upload_params['role']) ? $upload_params['role'] : 'all'
        ];

        $val3 = 112;
        $arr = [
            'key1' => 'val1',
            'key2' => 2,
            'key3' => $val3,
            'url' => [
                'A',
                'A',
                'A',
            ],
        ];
        foreach($arr as $k => $v){
            print($arr);
        }

        while($a < 3){
            $a--;
        }

        do{
            $a--;
        }while($a < 3);

        try{
            $arrx[11] = 'xx';
            echo 'iii';
        }catch(Error $a){
            echo $a;
        }

        // commet1
        // commet2
        // commet3
        // commet4

        /*
         * block_commit1
         * block_commit2
         * block_commit3
         */

        $arr = [
            'ni' => 'hao'
        ];

        if($b == test('ii')){
            print('this is if');
        }elseif($c == 'ii'){
            print('this is elseif');
        }else{
            print('this is else');
        }

        $first_segment = array_filter($format_files_array[$projection_source_fmt], function ($item) {
            return $item["vp"] == 1;
        })[0]['path'];

        if (count($vp_count) !== count(array_filter($cid_info['format_list'], function ($fmt) {
            return $fmt < 128;
        }))) {
        throw new \Exception("new number of fmts is diff to origin");
            }

    }
}

Test::static_func('ii');
$test = new Test('hello', 'world');
$test->public_func('nihao');
