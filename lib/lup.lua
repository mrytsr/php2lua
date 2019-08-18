--
-- User: tangjunxing
-- Date : 2017/12/20
--

local lfs = nil --require 'lfs'
local serpent = require 'serpent'
local cjson = require 'cjson.safe'
local client = require 'lib.client'
local resolver = require "resty.dns.resolver"
local lrucache = require "resty.lrucache"
local ck = nil --require 'resty.cookie' or nil

--
-- Constants
--
local _M = {
    PATHINFO_DIRNAME = 1,
    PATHINFO_BASENAME = 2,
    PATHINFO_EXTENSION = 3,
    PATHINFO_FILENAME = 4,
    PHP_URL_SCHEME = 0,
    PHP_URL_HOST = 1,
    PHP_URL_PORT = 2,
    PHP_URL_USER = 3,
    PHP_URL_PASS = 4,
    PHP_URL_PATH = 5,
    PHP_URL_QUERY = 6,
    PHP_URL_FRAGMENT = 7,
    FILE_APPEND = 'a+',
    FILTER_VALIDATE_IP = 8,
    FILTER_VALIDATE_URL = 9,
}

-- function SecondsToClock(seconds)
--     local seconds = tonumber(seconds)
--     if seconds <= 0 then
--         return "00:00:00";
--     else
--         hours = string.format("%02.f", math.floor(seconds/3600));
--         mins = string.format("%02.f", math.floor(seconds/60 - (hours*60)));
--         secs = string.format("%02.f", math.floor(seconds - hours*3600 - mins *60));
--         return hours..":"..mins..":"..secs
--     end
-- end

--
-- PHP: 对象复制 - Manual
-- http://www.php.net/manual/zh/language.oop5.cloning.php
--
function _M.clone(orig)
    local orig_type = type(orig)
    local copy
    if orig_type == 'table' then
        copy = {}
        for orig_key, orig_value in next, orig, nil do
            copy[_M.clone(orig_key)] = _M.clone(orig_value)
        end
        setmetatable(copy, _M.clone(getmetatable(orig)))
    else
        copy = orig
    end
    return copy
end


--
--
--
function _M.include(name)
    local status, err = pcall(require, name)
    if not status then
        return nil
    end
    return err
end

--
-- PHP: gethostbyname - Manual
-- http://php.net/manual/zh/function.gethostbyname.php
-- gethostbyname('baidu.com', '8.8.8.8')
--
local resolved = lrucache.new(1000)
function _M.gethostbyname(hostname, dns)

    local nameservers = {}
    local key = hostname..(dns or '')
    local val, slate_val = resolved:get(key)
    if val then
        return val
    end
    slate_val = slate_val or hostname

    --
    -- Httpdns
    --
    if _M.filter_var(dns, _M.FILTER_VALIDATE_URL) then
        local s = dns:gsub('_DOMAIN_', hostname)
        local answers = _M.explode(';', client.get(s).body)
        val = answers[math.floor(_M.rand()) % #answers + 1]
    else
        --
        -- Nameservers from param_dns or (local, resolv.conf, google)
        --
        if type(dns) == 'string' then
            nameservers = _M.explode(',', dns)
        else
            nameservers = {'119.29.29.29', '127.0.0.1',}
            for _, line in ipairs(_M.explode('\n', _M.file_get_contents('/etc/resolv.conf'))) do
                table.insert(nameservers, _M.explode(nil, line)[2])
            end
        end

        local r, err = resolver:new{
            nameservers = nameservers,
            retrans = 2,
            timeout = 1000,
        }

        if not r then
            ngx.log(ngx.ERR, "FAILED_TO_INIT_", err)
            return slate_val
        end

        local answers, err, tries = r:query(hostname, nil, {})
        if not answers then
            ngx.log(ngx.ERR, "FAILED_TO_QUERY_", err, '_RETRY_', table.concat(tries, ","))
            return slate_val
        end

        if answers.errcode then
            ngx.log(ngx.ERR, "DNS_RETURNED_ERROR_", answers.errcode, "_", answers.errstr)
            return slate_val
        end

        local addresses = {}
        for _, ans in ipairs(answers) do
            if ans['type'] == 1 then
                table.insert(addresses, ans.address)
            end
        end
        val = addresses[math.floor(_M.rand()) % #addresses + 1]

    end

    if val then
        resolved:set(key, val, 30)
    end
    return val or slate_val
end

--
-- PHP: filter_var - Manual
-- http://php.net/manual/zh/function.filter-var.php
--
function _M.filter_var(variable, filter)
    if _M.empty(variable) then
        return false
    elseif filter == _M.FILTER_VALIDATE_IP then
        return ngx.re.match(variable, [[^\d{1,3}(\.\d{1,3}){3}$]], 'jo') and true
    elseif filter == _M.FILTER_VALIDATE_URL then
        return ngx.re.match(variable, [[^(?:(\w+):)?//([^:/\?]+)(?::(\d+))?([^\?]*)\??(.*)]], "jo") and true
    end
    return false
end

--
-- PHP: empty - Manual
-- http://php.net/manual/zh/function.empty.php
--
function _M.empty(v)
    return not v or v == '' or v == 0
end

--
-- PHP: var_dump - Manual
-- http://php.net/manual/zh/function.var-dump.php
--
function _M.var_dump(expression)
    print(serpent.block(expression))
end

--
-- PHP: var_export - Manual
-- http://php.net/manual/zh/function.var-export.php
--
function _M.var_export(expression)
    return serpent.block(expression, {comment = false, indent = '    '})
end

--
-- PHP: sys_getloadavg - Manual
-- http://php.net/manual/zh/function.sys-getloadavg.php
--
function _M.sys_getloadavg()
    local e = _M.explode(nil, _M.file_get_contents('/proc/loadavg'))
    local r = {}
    for k, v in ipairs(e) do
        r[k] = tonumber(v)
    end
    return r
end

--
-- PHP: scandir - Manual
-- http://php.net/manual/zh/function.scandir.php
--
function _M.scandir(directory)
    local i, t, popen = 0, {}, io.popen
    local pfile = popen('ls -a "'..directory..'"')
    for filename in pfile:lines() do
        i = i + 1
        t[i] = filename
    end
    pfile:close()
    return t
end

--
-- PHP: is_dir - Manual
-- http://php.net/manual/zh/function.is-dir.php
--
function _M.is_dir(filename)
    local attr = lfs.attributes(filename)
    return attr and attr.mode == 'directory'
end

--
-- PHP: shuffle - Manual
-- http://php.net/manual/zh/function.shuffle.php
--
function _M.shuffle(t)
    math.randomseed(os.clock())
    local tmp, index
    for i=1, #t-1 do
        index = math.random(i, #t)
        if i ~= index then
            tmp = t[index]
            t[index] = t[i]
            t[i] = tmp
        end
    end
end

--
-- PHP: in_array - Manual
-- http://php.net/manual/zh/function.in-array.php
--
function _M.in_array(needle , haystack)
    if haystack then
        for _, v in pairs(haystack) do
            if v == needle then
                return true
            end
        end
    end
    return false
end


--
-- PHP: array_merge - Manual
-- http://php.net/manual/zh/function.array-merge.php
--
function _M.array_merge(array1, array2)
    array1 = array1 or {}
    array2 = array2 or {}
    local res = {}
    for k, v in pairs(array1) do
        res[k] = v
    end
    for k, v in pairs(array2) do
        if type(k) == 'number' then
            table.insert(res, v)
        else
            res[k] = v
        end
    end
    return res
end

--
-- PHP: 错误控制运算符 - Manual
-- http://php.net/manual/zh/language.operators.errorcontrol.php
--
function _M.a(f, ...)
    local status, ret = pcall(f, ...)
    if status then
        return ret
    end
    ngx.log(ngx.ERR, serpent.line(ret))
    return nil
end

--
-- PHP: $_REQUEST - Manual
-- http://php.net/manual/en/reserved.variables.request.php
--
function _M._REQUEST()
    local params = {}
    local uri_args = ngx.req.get_uri_args()
    if type(uri_args) == 'table' then
        for k, v in pairs(uri_args) do
            params[k] = v
        end
    end
    local body_args = {}
    local http_content_type = ngx.var.http_content_type or ''
    if ngx.re.match(http_content_type, '^application/json\\b', 'jo') then
        ngx.req.read_body()
        body_args = cjson.decode(ngx.req.get_body_data())
    elseif http_content_type == 'application/x-www-form-urlencoded' then
        ngx.req.read_body()
        body_args = ngx.req.get_post_args()
    end
    if type(body_args) == 'table' then
        for k, v in pairs(body_args) do
            params[k] = v
        end
    end
    return params
end

--
-- PHP: trim - Manual
-- http://php.net/manual/zh/function.trim.php
--
function _M.trim(str, character_mask)
    character_mask = character_mask or '%s'
    return str:match('^'..character_mask..'*(.*)'):match('(.-)'..character_mask..'*$')
end

--
-- PHP: gethostname - Manual
-- http://php.net/manual/en/function.gethostname.php
--
local hostname
function _M.gethostname()
    if not hostname then
        hostname = _M.trim(_M.exec('hostname'))
    end
    return hostname
end

--
-- PHP: hex2bin - Manual
-- http://php.net/manual/zh/function.hex2bin.php
--
function _M.hex2bin(data)
    return ((data or ''):gsub('..', function (cc)
        return string.char(tonumber(cc, 16) or 0)
    end))
end

function _M._bson2json(tb)
    local meta = getmetatable(tb) or {}
    if meta.__tostring then
        local v = tostring(tb)
        if meta._NAME == 'INT64' then
            return tonumber(v)
        end
        return v
    elseif type(tb) ~= 'table' then
        return tb
    end
    local res = {}
    for k, v in pairs(tb) do
        if type(k) == 'number' then
            table.insert(res, _M._bson2json(v))
        else
            res[k] = _M._bson2json(v)
        end
    end
    return res
end

function _M.json_encode(obj)
    return cjson.encode(_M._bson2json(obj))
end

function _M.json_decode(str)
    return cjson.decode(str)
end

function _M.system(cmd)
    local shell = require 'resty.shell'
    if not lfs.attributes('/var/run/shell.sock') then
        os.execute('sockproc /var/run/shell.sock')
        assert(lfs.attributes('/var/run/shell.sock'), 'SHELL_SOCK_NOT_FOUND')
    end
    local _, out = shell.execute(cmd, {socket = "unix:/var/run/shell.sock"})
    return out
end

function _M.passthru(cmd)
    local fifo = '/tmp/'.._M.rand()
    if not lfs.attributes(fifo) then
        _M.exec('mkfifo '..fifo)
    end
    os.execute(cmd..' > '..fifo..' &')
    local f = io.open(fifo)
    io.input(f)
    local line
    while 1 do
        line = io.read()
        if not line then
            break
        end
        ngx.say(line)
        ngx.flush(true)
    end
    ngx.flush(true)
    io.close()
    _M.unlink(fifo)
end

--
-- PHP: exec - Manual
-- http://php.net/manual/en/function.exec.php
--
function _M.exec(cmd)
    local tmpout = os.tmpname()
    local tmperr = os.tmpname()
    local ret = os.execute('('..cmd..') >'..tmpout..' 2>'..tmperr)
    local stdout = _M.file_get_contents(tmpout)
    os.remove(tmpout)
    local stderr = _M.file_get_contents(tmperr)
    os.remove(tmperr)
    ngx.log(ngx.INFO,
        ' cmd: ', cmd,
        ' ret: ', ret,
        ' stderr: ', stderr,
        ' stdout: ', stdout
    )
    if ret then
        return stdout
    end
    return nil, cjson.encode{
        ret = ret,
        stdout = stdout,
        stderr = stderr
    }
end

--
-- PHP: unlink - Manual
-- http://php.net/manual/zh/function.unlink.php
--
function _M.unlink(path)
    return _M.exec('rm -f '..path)
end

--
-- PHP: unlink - Manual
-- http://php.net/manual/zh/function.rename.php
--
function _M.rename(src, dst)
    return _M.exec('mv '..src..' '..dst)
end

--
-- PHP: file_get_contents - Manual
-- http://php.net/manual/zh/function.file-get-contents.php
--
function _M.file_get_contents(file)
    if _M.filter_var(file, _M.FILTER_VALIDATE_URL) then
        return (client.get(file)).body
    else
        local f, err = io.open(file, 'r')
        if not f then
            return nil, err
        end
        local content = f:read('*a')
        f:close()
        return content
    end
end

--
-- PHP: file_put_contents - Manual
-- http://php.net/manual/zh/function.file-put-contents.php
--
function _M.file_put_contents(file, content, flag)
    local f, err = io.open(file, flag or 'w')
    if not f then
        return nil, err
    end
    f:write(content)
    f:close()
end

--
-- PHP: copy - Manual
-- http://php.net/manual/zh/function.copy.php
--
function _M.copy(src, dest)
    local dest_tmp = dest..os.clock()
    local f, err
    f, err = io.open(src, 'rb')
    if not f then
        return nil, err
    end
    local content = f:read('*a')
    f:close()
    f, err = io.open(dest_tmp, 'w')
    if not f then
        return nil, err
    end
    f:write(content)
    f:close()
    return os.rename(dest_tmp, dest)
    -- return _M.exec('cp '..src..' '..dst)
end

--
-- http://php.net/manual/en/function.parse-url.php
--
function _M.parse_url(url, component)

    local m, err = ngx.re.match(url, [[^(?:(\w+):)?//([^:/\?]+)(?::(\d+))?([^\?]*)\??(.*)]], "jo")

    if not m then
        return nil, "BAD_URL_" .. url .. ", ERR_" .. (err or '')
    end

    local p = {
        [_M.PHP_URL_SCHEME] = m[1] or 'http',
        [_M.PHP_URL_HOST] = m[2],
        [_M.PHP_URL_PORT] = tonumber(m[3]),
        [_M.PHP_URL_PATH] = m[4] or '/',
        [_M.PHP_URL_QUERY] = m[5],
    }

    if component then
        return p[component]
    end
    return {
        scheme = p[_M.PHP_URL_SCHEME],
        host = p[_M.PHP_URL_HOST],
        port = p[_M.PHP_URL_PORT],
        path = p[_M.PHP_URL_PATH],
        query = p[_M.PHP_URL_QUERY],
    }

end

--
-- PHP: is_executable - Manual
-- http://php.net/manual/en/function.is-executable.php
--
function _M.is_executable(path)
    local attr = lfs.attributes(path)
    if attr then
        if string.sub(attr.permissions, 3, 3) == 'x' then
            return true
        end
    end
    return false
end

--
-- PHP: uniqid - Manual
-- http://php.net/manual/zh/function.uniqid.php
--
function _M.uniqid()
    return ngx.md5(ngx.time() + os.clock() + ngx.worker.pid())
end

--
-- PHP: file_exists - Manual
-- http://php.net/manual/zh/function.file-exists.php
--
function _M.file_exists(path)
    local f = io.open(path or '', 'r')
    if not f then
        return false
    end
    io.close(f)
    return true
end

--
-- PHP: filemtime - Manual
-- http://php.net/manual/zh/function.filemtime.php
--
function _M.filemtime(path)
    local attributes = lfs.attributes(path)
    if attributes then
        return attributes.change
    end
    return false
end

--
-- PHP: filesize - Manual
-- http://php.net/manual/zh/function.filesize.php
--
function _M.filesize(path)
    return (lfs.attributes(path) or {}).size
end

--
-- PHP: basename - Manual
-- http://php.net/manual/zh/function.basename.php
--
function _M.basename(str)
    return string.gsub(str, "(.*/)(.*)", "%2")
end

--
-- PHP: pathinfo - Manual
-- http://php.net/manual/zh/function.pathinfo.php
--
function _M.pathinfo(path, options)
    local pos = string.len(path)
    local extpos = pos + 1
    while pos > 0 do
        local b = string.byte(path, pos)
        if b == 46 then -- 46 = char "."
            extpos = pos
        elseif b == 47 then -- 47 = char "/"
            break
        end
        pos = pos - 1
    end
    local dirname = string.sub(path, 1, pos)
    local basename = string.sub(path, pos + 1)
    extpos = extpos - pos
    local filename = string.sub(basename, 1, extpos - 1)
    local extension = string.sub(basename, extpos + 1)
    local ret = {
        [_M.PATHINFO_DIRNAME] = dirname,
        [_M.PATHINFO_BASENAME] = basename,
        [_M.PATHINFO_FILENAME] = filename,
        [_M.PATHINFO_EXTENSION] = extension
    }
    if options then
        return ret[options]
    end
    return {
        dirname = ret[_M.PATHINFO_DIRNAME],
        basename = ret[_M.PATHINFO_BASENAME],
        filename = ret[_M.PATHINFO_FILENAME],
        extension = ret[_M.PATHINFO_EXTENSION],
    }
end

--
-- PHP: dirname - Manual
-- http://php.net/manual/zh/function.dirname.php
--
function _M.dirname(path)
    local dirname = path:gsub("[^/]+/*$", ""):gsub("/$", "")
    if dirname == "" then
        return path
    end
    return dirname
end

--
-- PHP: mkdir - Manual
-- http://php.net/manual/zh/function.mkdir.php
--
function _M.mkdir(path, mode, recursive)
    if lfs.attributes(path) then
        return true
    end
    local parent = _M.dirname(path)
    if not lfs.attributes(parent) then
        assert(recursive, 'mkdir(): No such file or directory')
        _M.mkdir(parent, mode, recursive)
    end
    assert(lfs.mkdir(path))
    if mode then
        os.execute("chmod "..mode.." "..path)
    end
    return true
end

function _M.get_files(path, prepend_path_to_filenames)
    if path:sub(-1) ~= '/' then
        path = path..'/'
    end
    local pipe = io.popen('ls '..path..' 2> /dev/null')
    local output = pipe:read'*a'
    pipe:close()
    -- If your file names contain national characters
    -- output = convert_OEM_to_ANSI(output)
    local files = {}
    for filename in output:gmatch('[^\n]+') do
        if prepend_path_to_filenames then
            filename = path..filename
        end
        table.insert(files, filename)
    end
    return files
end

--
-- PHP: rand - Manual
-- http://php.net/manual/zh/function.rand.php
--
function _M.rand()
    math.randomseed((os.time()%100000000)*1000000+os.clock()*1000000)
    return math.random()*100000000000000
end

--
-- PHP: disk_free_space - Manual
-- http://php.net/manual/en/function.disk-free-space.php
--
function _M.disk_free_space(path)
    return tonumber(_M.explode('\n', _M.exec('df --output=avail '..path))[2]) or false
end

function _M.disk_used_space(path)
    return tonumber(_M.explode('\n', _M.exec('df --output=used '..path))[2]) or false
end

--
-- PHP: disk_total_space - Manual
-- http://php.net/manual/en/function.disk-total-space.php
--
function _M.disk_total_space(path)
    return tonumber(_M.explode('\n', _M.exec('df --output=size '..path))[2]) or false
end

--
-- PHP: strtr - Manual
-- http://php.net/manual/en/function.strtr.php
--
function _M.strtr(str, from, to)
    local ret = {string.byte(str, 1, string.len(str))}
    local f = {string.byte(from, 1, string.len(from))}
    local t = {string.byte(to, 1, string.len(to))}
    local d = {}
    for i = 1, #from do
        d[f[i]] = t[i]
    end
    for k, v in pairs(ret) do
        if d[v] then
            ret[k] = d[v]
        end
    end
    return string.char(unpack(ret))
end

--
-- PHP: explode - Manual
-- http://php.net/manual/zh/function.explode.php
--
function _M.explode(sep, str)
    local cols = {}
    for m in (str or ''):gmatch('[^'..(sep or '%s').."]+") do
        cols[#cols + 1] = m
    end
    return cols
end

--
-- PHP: md5_file - Manual
-- http://php.net/manual/zh/function.md5-file.php
--
function _M.md5_file(path)
    local out, err = io.popen('md5sum '..path..'|awk \'{print $1}\'')
    if not out then
        return err
    end
    local ret = out:read(32)
    io.close()
    return ret
end

function _M.user_verify(caller, appkey)
    local cookie, err = ck:new()
    if cookie then
        local session_id, err = cookie:get('_AJSESSIONID')
        local username, err = cookie:get('username')
        if username then
            return true
        end
        local ts = os.time()
        local params = {
            caller = caller,
            encrypt = 'md5',
            ts = ts,
            session_id = session_id
        }
        appkey = appkey or ''
        params.sign = ngx.md5(ngx.encode_args(params)..appkey)
        local dashboard_url = 'http://dashboard-mng.bilibili.co/api/session/verify'
        local res, err = pcall(client.post, dashboard_url, {form_params=params, retry=1})
        local data = _M.json_decode(res) or {}
        if not res or data.code ~= 0 then
            ngx.redirect('https://dashboard-mng.bilibili.co/login.html?caller='..caller)
            return
        end
        return true
    end
end

return _M
