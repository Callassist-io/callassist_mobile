# CallAssist Mobile FusionPBX App

## What is CallAssist Mobile FusionPBX App?

####Manage business calls from your mobile phone
CallAssist Mobile for FusionPBX* increases the flexibility, customer focus and professionalism of companies, especially for employees who work on the go. It ensures that customer communication is consistent and professional, while allowing the team to communicate more efficiently.

*CallAssist Mobile only works on a FusionPBX platform

## Software Requirements

- [ ] FusionPBX

## How to Install CallAssist Mobile on FusionPBX

YOU **REALLY** NEED TO DO **ALL** FOLLOWING STEPS



### 1 As root do the following:

```
cd /var/www/fusionpbx/app;
git clone https://github.com/Callassist-io/callassist_mobile.git;
chown -R www-data:www-data callassist_mobile;
git config --global --add safe.directory /var/www/fusionpbx/app/callassist_mobile;
```

### 2 Login as superadmin to your FusionPBX Web GUI:

Menu->Advanced->Upgrade, check:
- App Defaults
- Menu Defaults
- Permission Defaults

then click "Execute"

### 4 Logout from FusionPBX and login as a normal user, you will find:

Menu->Applications->CallAssist Mobile


### 5 Upgrading After Install

```
cd /var/www/fusionpbx/app/callassist_mobile;
git pull;
cd ..;
chown -R www-data:www-data callassist_mobile;
```
often, and you will get latest features/bigfixes.

### Push notifications for forwarded calls

The push hook listens for outbound call legs, including calls created by ring groups.
The notification includes the original caller name and number when they differ.
Install `callassist_mobile_push.lua` in the FreeSWITCH scripts directory (usually
`/usr/share/freeswitch/scripts/`). Add this line inside `<settings>` in
`/etc/freeswitch/autoload_configs/lua.conf.xml`:

```xml
<!-- CallAssist Mobile: push notifications for calls forwarded to the mobile app -->
<hook event="CHANNEL_OUTGOING" script="callassist_mobile_push.lua"/>
```

The hook runs `push.php` from the installed app directory; PHP CLI and cURL
must be available on the FreeSWITCH host. `reloadxml` alone does not activate
a new Lua event hook. The hook is read when `mod_lua` loads. On FusionPBX
installations where `mod_lua` cannot be unloaded, schedule a FreeSWITCH restart
after changing `lua.conf.xml`:

```bash
systemctl restart freeswitch
```

The restart interrupts active calls. Then test one direct forwarded call and
one call through a ring group.

A normal update of the CallAssist app updates `push.php` but does not install
the FreeSWITCH script again. After an app or FusionPBX upgrade, copy the
current `callassist_mobile_push.lua` to the scripts directory and verify that
the hook is still present in `lua.conf.xml`.

Central superadministrators can open **Push status** from the CallAssist Mobile page to
check whether the installed Lua matches the app version and whether
`lua.conf.xml` contains the hook exactly once. The status check supports
the common package and source-install paths. The web server must be able to
read the configuration file to inspect it. The page cannot determine whether
FreeSWITCH has loaded a newly added hook; restart FreeSWITCH after changing
`lua.conf.xml`.

The **Apply push configuration** button copies the bundled Lua into the
FreeSWITCH scripts directory when it is missing or differs, and adds the hook
to `lua.conf.xml` when needed. It makes a timestamped backup before editing
the XML. The web server account needs write access to the FreeSWITCH scripts
and configuration directories. The button does not restart FreeSWITCH.

### 6 Remove CallAssist mobile from FusionPBX

#### 6.1 Remove the code
```
cd /var/www/fusionpbx/app;
rm -r callassist_mobile;
```

#### 6.2 Remove the Defaults 

Menu->Advanced->Upgrade, check:
- App Defaults
- Menu Defaults
- Permission Defaults

then click "Execute"
