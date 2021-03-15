Datatables 1.10.21
-------------------

https://github.com/DataTables/DataTables and https://github.com/DataTables/DataTablesSrc

Instructions to import Datatble in mod_bigbluebuttonbn

1. Download the latest release from https://github.com/DataTables/DataTables/tree/master/media

2. copy 'jquery.dataTables.js' into 'amd/src/local/libraries/jquery.dataTables.js'
   Add /* eslint-disable */ in the beginning of the file
3. copy the jquery.dataTables.css into the main folder
   Add /* stylelint-disable */ at the beginning of the files
   Change url("../images/...") path into url("./images/");
4. copy images into css/images/
5. Add $PAGE->requires->css('/mod/bigbluebuttonbn/css/jquery.dataTables.css'); to each page requiring the use
of datatable

Note: Datatable are used in recording page only so they might be replaced by dynamictables from 3.9 +
(although the sorting/filtering might work better in the datatable version).


