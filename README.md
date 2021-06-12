# Holdenovi_SecurityScan

## Instructions

1. Verify that you have no malware or malicious scripts in the following database tables:
  * `cms_block`
  * `cms_page`
  * `core_config_data`
2. Run the following command to write the script status config to `var/scan`:
  * `bin/magento holdenovi:scan:database --set-status`

### Known Issues

* Cannot find or hash script that exists between two fields.
* If you use Capistrano, add the `var/scan` folder to your shared directories, otherwise, your status file will lost in subsequent deployments.

## TODO

Add feature to allow additional table configs.
