--
-- tjx@20190822
--
local _M = {}

function _M.new(name)
    local self = {}
    self.name = name
    return setmetatable(self, {__index = _M})
end

function _M.draw(self)
    local color = self.color or 'default_color'
    print('this is draw'..self.name..' color: '..color)
end

function _M.static_draw()
    print('this is static_draw')
end

function _M.set_color(self, color)
    self.color = color
end

return _M

