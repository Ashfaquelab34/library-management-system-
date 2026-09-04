<?php
declare(strict_types=1);
$public=__DIR__;
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
$uri=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/';
$uri=rawurldecode($uri);
if(str_starts_with($uri,'/api/')){
    header('Cache-Control: no-store');
    require dirname(__DIR__).'/api/index.php';
    exit;
}
if($uri==='/health'){header('Content-Type:application/json');echo json_encode(['ok'=>true,'app'=>'Library Management System']);exit;}
if($uri==='/'||$uri==='/index.php'){$file=$public.'/index.html';}else{
 $clean=ltrim($uri,'/');
 if(str_contains($clean,'..')){http_response_code(400);exit('Bad request');}
 $candidate=$public.'/'.$clean;
 if(is_file($candidate)){
   $ext=strtolower(pathinfo($candidate,PATHINFO_EXTENSION));
   $types=['css'=>'text/css; charset=utf-8','js'=>'application/javascript; charset=utf-8','json'=>'application/json; charset=utf-8','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','ico'=>'image/x-icon','html'=>'text/html; charset=utf-8'];
   header('Cache-Control: public, max-age=86400, stale-while-revalidate=3600');
   header('Content-Type: '.($types[$ext] ?? (mime_content_type($candidate) ?: 'application/octet-stream')));
   readfile($candidate);exit;
 }
 $routes=['login'=>'login.html','member-login'=>'member-login.html','register'=>'register.html','dashboard'=>'dashboard.html','student-dashboard'=>'dashboard.html','assistant-dashboard'=>'dashboard.html','books'=>'books.html','students'=>'members.html','members'=>'members.html','issues'=>'issues.html','returns'=>'returns.html','fines'=>'fines.html','reservations'=>'reservations.html','profile'=>'profile.html','activity'=>'activity.html','reports'=>'reports.html','settings'=>'settings.html','librarians'=>'librarians.html','setup'=>'setup.html','my-books'=>'my-books.html','authors'=>'authors.html','categories'=>'categories.html'];
 $file=isset($routes[$clean])?$public.'/'.$routes[$clean]:$public.'/index.html';
}
header('Content-Type: text/html; charset=utf-8');readfile($file);
