<?php

/*
|--------------------------------------------------------------------------
| API Route Modules
|--------------------------------------------------------------------------
|
| Laravel applies the /api prefix and the api middleware group to this file.
| Each module owns its own routes; this file only registers enabled modules.
|
*/

require base_path('Modules/Identity/routes/api.php');
require base_path('Modules/Projects/routes/api.php');
require base_path('Modules/Tasks/routes/api.php');
require base_path('Modules/Teams/routes/api.php');
require base_path('Modules/Files/routes/api.php');
