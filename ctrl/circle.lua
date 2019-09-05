
local shape = require 'ctrl.shape'

local _M = shape.new()

function _M.new(name)
    local self = {}
    setmetatable(self, {__index = _M})
    self.name = name
    -- self.new(name)
    return self
end

function _M.round(self)
    print('this is round in circle')
end

function _M.static_round()
    print('this is static_round in circle')
end

function _M.print(self, filename)
    print(filename)
end

return _M
