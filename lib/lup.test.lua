#!/usr/local/acache/bin/resty

local lup = require 'lib.lup'

lup.var_dump(lup.gethostbyname('baidu.com', '8.8.8.8,8.8.4.4'))

lup.var_dump(lup.gethostbyname('baidu.com'))

lup.var_dump(lup.file_get_contents('http://baidu.com'))

lup.var_dump(lup.file_get_contents('/etc/issue'))

lup.var_dump(type('nihao'))

local a1 = {
    {ni = 'hao'},
    {wo = 'buhao'},
}

local a2 = {
    {ni1 = 'hao1'},
    {wo1 = 'buhao1'},
}
lup.var_dump(lup.array_merge(a1, a2))
