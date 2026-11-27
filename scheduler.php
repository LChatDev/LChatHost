<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('LCHAT_INTERNAL',true);
require __DIR__.'/app.php';
require_once __DIR__.'/apis/productivity.php';
try{ensure_features();productivity_schema();echo json_encode(tools_dispatch(100),JSON_UNESCAPED_UNICODE).PHP_EOL;}
catch(Throwable $e){error_log('LChat scheduler: '.$e->getMessage());exit(1);}
