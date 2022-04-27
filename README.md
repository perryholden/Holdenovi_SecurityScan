# Holdenovi_SecurityScan

This is a security extension that keeps track of any preexisting `<scripts>` in your database tables, if any.
It contains a CLI command to be run via cron. If any changes are detected (either a new script is added or an
existing script is modified), then you will be alerted via email.

## Instructions

1. Set up email configuration in Admin -> STORES -> Configuration -> GENERAL -> Store Email Addresses -> Security Scan Emails
   * Sender Name
   * Sender Email
   * Recipient Email
2. Verify that you have no malware or malicious scripts in the following database tables:
   * `cms_block`: `value`
   * `cms_page`: `content`
   * `core_config_data`: `content`, `layout_update_xml`
3. Run the following command to write the script status config to `var/scan`:
   * `bin/magento holdenovi:scan:database --set-status`
4. Set the following command to run via cron on any schedule you wish:
   * `bin/magento holdenovi:scan:database`

## Items to Note

1. This extension will not notify you in any of the following conditions:
   1. A script is *removed* from your tables.
   2. The order of unmodified scripts is changed.
2. If you remove any malware, then you will have to reset the status by running the command with the `--set-status` flag.
3. If you use Capistrano, add the `var/scan` folder to your shared directories, otherwise, your status file will lost in subsequent deployments.

## Testing scenarios

1. New record:
   1. New table
   2. Existing table, new key
   3. Existing table, existing key, new column
2. Modified record:
   1. Add new script to existing field
   2. Modify script in field

## TODO

* Add feature to allow additional (custom) table configs.
