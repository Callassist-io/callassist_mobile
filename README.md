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
```
often, and you will get latest features/bigfixes.

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