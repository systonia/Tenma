<?php
// For tests/http.rs: settings from the command line and from a php.ini
echo json_encode([ini_get('memory_limit'), ini_get('date.timezone'), ini_get('precision'), ini_get('upload_max_filesize')]), "\n";
