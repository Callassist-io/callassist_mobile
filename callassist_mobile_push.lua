-- FreeSWITCH mod_lua CHANNEL_OUTGOING hook.
-- Install as a hook in lua.conf.xml.
if not event then return end

local function header(name)
    local value = event:getHeader(name)
    return value and value ~= "_undef_" and value or ""
end

local channel_name = header("Channel-Name")
local destination = header("Caller-Destination-Number")
if destination == "" then destination = header("variable_destination_number") end

-- Ring groups can create a loopback leg before the real outbound leg.
if channel_name:match("^loopback/") or not destination:match("^%+?%d+$") then return end

local domain_uuid = header("variable_domain_uuid")
local domain_name = header("variable_domain_name")
if domain_name == "" then domain_name = header("variable_sip_invite_domain") end
if domain_name == "" then domain_name = header("Caller-Context") end
if domain_uuid == "" and domain_name == "" then return end

local caller_id = header("Caller-Orig-Caller-ID-Number")
if caller_id == "" then caller_id = header("Caller-Caller-ID-Number") end
local caller_name = header("Caller-Orig-Caller-ID-Name")
local current_caller_name = header("Other-Leg-Caller-ID-Name")

-- The original caller profile can still contain the number after a dialplan
-- lookup changes caller_id_name. Read the live originating leg as well.
local originator_uuid = header("Other-Leg-Unique-ID")
if originator_uuid:match("^[0-9a-fA-F]+%-[0-9a-fA-F%-]+$") then
    pcall(function()
        local api = freeswitch.API()
        for _, variable in ipairs({"effective_caller_id_name", "caller_id_name"}) do
            local value = api:executeString("uuid_getvar " .. originator_uuid .. " " .. variable)
            if value and value ~= "" and value ~= "_undef_" and not value:match("^%-ERR")
                and value ~= caller_id then
                current_caller_name = value
                break
            end
        end
    end)
end

local function shell_quote(value)
    return "'" .. value:gsub("'", "'\\''") .. "'"
end

local args = {domain_uuid, domain_name, destination, caller_id, caller_name, current_caller_name}
local command = "/usr/bin/php /var/www/fusionpbx/app/callassist_mobile/push.php"
for _, value in ipairs(args) do command = command .. " " .. shell_quote(value) end
os.execute(command .. " > /dev/null &")
