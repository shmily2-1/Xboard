<?php

return [
    'enabled' => PHP_OS_FAMILY === 'Windows',
    'version' => '20261114-d826a3b-win-php85-cap',
    'commit' => 'd826a3b32f73bde24acce2a3ab033c6538ce121d',
    'backup_root' => env('WINDOWS_BACKUP_DIR', 'D:/backup/Xboard-dorocloud.net'),
    'mysqldump' => env('WINDOWS_MYSQLDUMP', 'D:/BtSoft/mysql/MySQL5.7/bin/mysqldump.exe'),
];
