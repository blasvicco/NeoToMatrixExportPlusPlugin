# NeoToMatrixExportPlusPlugin

This is a [Craft CMS](http://craftcms.com) [Console Plugin](https://docs.craftcms.com/api/v2/consolecommands/BaseCommand.html) that allows to the users run a [Neo Field](https://github.com/benjamminf/craft-neo) to [Matrix](https://docs.craftcms.com/v2/matrix-fields.html) and [SuperTable](https://github.com/verbb/super-table) combination Fields migration by Field ID.

The migration script will set any nested field as a SuperTable field inside the Matrix Block of the root level, so only first level of nesting is preserved.
Any nested Neo field set as available in top level, will be migrated as a root Matrix Block.
The script also can remove the not used nested fields if the argument `clean` is set as true in the execution of the script.

## Example:
In the shell:
```SHELL
cd {craft application root folder}
./app/etc/console/yiic neomigrate fieldId={neoFieldId / required} clean={true / optional}
```
