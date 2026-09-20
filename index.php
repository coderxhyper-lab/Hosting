<?php
declare(strict_types=1);

const BOT_TOKEN='7832316573:AAHtkWt2yNu8ai1lkKur06UxEM5CzPN1wa0';
const ADMIN_IDS=['8897821078'];
const OWNER_USERNAME='@HyperxVicky';
const APP_SECRET='CHANGE_THIS_TO_A_LONG_RANDOM_SECRET';
const DB_FILE=__DIR__.'/hosting.sqlite';
const PROJECT_ROOT=__DIR__.'/projects';
const RUNTIME_ROOT=__DIR__.'/runtime';
const MAX_UPLOAD_BYTES=52428800;

date_default_timezone_set('Asia/Kolkata');
if(!is_dir(PROJECT_ROOT)) @mkdir(PROJECT_ROOT,0750,true);
if(!is_dir(RUNTIME_ROOT)) @mkdir(RUNTIME_ROOT,0750,true);

function db():SQLite3{
 static $d;
 if($d instanceof SQLite3)return $d;
 $d=new SQLite3(DB_FILE);$d->busyTimeout(5000);$d->exec('PRAGMA journal_mode=WAL');
 $d->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,tg_id TEXT UNIQUE,username TEXT,first_name TEXT,hosting_access INTEGER DEFAULT 0,max_projects INTEGER DEFAULT 3,max_storage INTEGER DEFAULT 524288000,created_at INTEGER,last_seen INTEGER,blocked INTEGER DEFAULT 0)");
 $d->exec("CREATE TABLE IF NOT EXISTS projects(id INTEGER PRIMARY KEY AUTOINCREMENT,user_tg_id TEXT,name TEXT,slug TEXT,type TEXT,status TEXT DEFAULT 'pending',access_required INTEGER DEFAULT 1,runtime_pid INTEGER,runtime_port INTEGER,created_at INTEGER,updated_at INTEGER,UNIQUE(user_tg_id,slug))");
 $d->exec("CREATE TABLE IF NOT EXISTS access_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,tg_id TEXT,project_id INTEGER,status TEXT DEFAULT 'pending',created_at INTEGER)");
 $d->exec("CREATE TABLE IF NOT EXISTS logs(id INTEGER PRIMARY KEY AUTOINCREMENT,tg_id TEXT,project_id INTEGER,action TEXT,details TEXT,created_at INTEGER)");
 return $d;
}
function q(string $s,array $p=[]){$st=db()->prepare($s);foreach($p as $k=>$v)$st->bindValue($k,$v,is_int($v)?SQLITE3_INTEGER:SQLITE3_TEXT);return $st->execute();}
function one(string $s,array $p=[]):?array{$r=q($s,$p);$x=$r?$r->fetchArray(SQLITE3_ASSOC):false;return $x?:null;}
function allr(string $s,array $p=[]):array{$r=q($s,$p);$a=[];if($r)while($x=$r->fetchArray(SQLITE3_ASSOC))$a[]=$x;return $a;}
function logx(?string $tg,?int $pid,string $a,string $d=''):void{q("INSERT INTO logs(tg_id,project_id,action,details,created_at)VALUES(:t,:p,:a,:d,:n)",[':t'=>$tg,':p'=>$pid,':a'=>$a,':d'=>$d,':n'=>time()]);}
function admin(string $id):bool{return in_array((string)$id,array_map('strval',ADMIN_IDS),true);}
function tg(string $m,array $d=[]):array{$c=curl_init('https://api.telegram.org/bot'.BOT_TOKEN.'/'.$m);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$d,CURLOPT_TIMEOUT=>60]);$r=curl_exec($c);curl_close($c);return is_string($r)&&($j=json_decode($r,true))?$j:['ok'=>false];}
function send(string $id,string $t,?array $kb=null):void{$d=['chat_id'=>$id,'text'=>$t,'parse_mode'=>'HTML'];if($kb)$d['reply_markup']=json_encode($kb);tg('sendMessage',$d);}
function ans(string $id,string $t=''):void{tg('answerCallbackQuery',['callback_query_id'=>$id,'text'=>$t]);}
function edit(string $c,int $m,string $t,?array $kb=null):void{$d=['chat_id'=>$c,'message_id'=>$m,'text'=>$t,'parse_mode'=>'HTML'];if($kb)$d['reply_markup']=json_encode($kb);tg('editMessageText',$d);}
function kb():array{return ['keyboard'=>[[['text'=>'📤 Upload Project'],['text'=>'📦 My Projects']],[['text'=>'🔐 Hosting Access'],['text'=>'👤 My Account']],[['text'=>'ℹ️ Help']]],'resize_keyboard'=>true];}
function akb():array{return ['keyboard'=>[[['text'=>'👥 Users'],['text'=>'📦 All Projects']],[['text'=>'🔐 Access Requests'],['text'=>'📊 Statistics']],[['text'=>'🖥 Server Status'],['text'=>'📋 Logs']],[['text'=>'⬅️ User Menu']]],'resize_keyboard'=>true];}
function user(array $f):array{$id=(string)($f['id']??'');$u=one("SELECT * FROM users WHERE tg_id=:i",[':i'=>$id]);$n=time();if($u){q("UPDATE users SET username=:u,first_name=:f,last_seen=:n WHERE tg_id=:i",[':u'=>(string)($f['username']??''),':f'=>(string)($f['first_name']??''),':n'=>$n,':i'=>$id]);return one("SELECT * FROM users WHERE tg_id=:i",[':i'=>$id])?:$u;}q("INSERT INTO users(tg_id,username,first_name,created_at,last_seen)VALUES(:i,:u,:f,:n,:n)",[':i'=>$id,':u'=>(string)($f['username']??''),':f'=>(string)($f['first_name']??''),':n'=>$n]);return one("SELECT * FROM users WHERE tg_id=:i",[':i'=>$id])!;}
function root(string $tg):string{$d=PROJECT_ROOT.'/'.preg_replace('/[^0-9A-Za-z_-]/','_',$tg);if(!is_dir($d))@mkdir($d,0750,true);return $d;}
function clean(string $s):string{$s=preg_replace('/[^A-Za-z0-9._-]+/','-',trim($s));return substr(trim($s,'.-_')?:'project',0,50);}
function slug(string $s):string{return strtolower(preg_replace('/[^a-z0-9]+/','-',clean($s)))?:'project';}
function pdir(array $p):string{return root((string)$p['user_tg_id']).'/'.$p['slug'];}
function rr(string $d):void{if(!is_dir($d))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());@rmdir($d);}
function size(string $d):int{$n=0;if(!is_dir($d))return 0;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile())$n+=$f->getSize();return $n;}
function unzip(string $z,string $d):bool{$x=new ZipArchive;if($x->open($z)!==true)return false;for($i=0;$i<$x->numFiles;$i++){ $n=str_replace('\\','/',$x->getNameIndex($i));if(str_starts_with($n,'/')||str_contains($n,'../')||preg_match('/^[A-Za-z]:\//',$n)){ $x->close();return false;}}$ok=$x->extractTo($d);$x->close();return $ok;}
function type(string $d):string{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS));foreach($it as $f){if(!$f->isFile())continue;$e=strtolower($f->getExtension());if($e==='py')return'python';if($e==='php')return'php';if(in_array($e,['html','htm'],true))return'html';}return'unknown';}
function entry(string $d,string $t):?string{$names=$t==='python'?['bot.py','main.py','app.py','index.py']:($t==='php'?['index.php','bot.php','main.php']:['index.html','home.html']);foreach($names as $n)if(is_file($d.'/'.$n))return$d.'/'.$n;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile()&&(($t==='python'&&$f->getExtension()==='py')||($t==='php'&&$f->getExtension()==='php')||($t==='html'&&in_array(strtolower($f->getExtension()),['html','htm'],true))))return$f->getPathname();return null;}
function createProject(string $tg,string $file,string $name):array{$u=one("SELECT * FROM users WHERE tg_id=:t",[':t'=>$tg]);$c=(int)(one("SELECT COUNT(*) c FROM projects WHERE user_tg_id=:t",[':t'=>$tg])['c']??0);if($c>=(int)$u['max_projects'])return[false,'Project limit reached ('.$u['max_projects'].').'];$s0=slug(pathinfo($name,PATHINFO_FILENAME));$s=$s0;$i=2;while(one("SELECT id FROM projects WHERE user_tg_id=:t AND slug=:s",[':t'=>$tg,':s'=>$s]))$s=$s0.'-'.$i++;$d=root($tg).'/'.$s;@mkdir($d,0750,true);if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='zip'){$ok=unzip($file,$d);@unlink($file);}else{$ok=@rename($file,$d.'/'.clean($name));}if(!$ok){rr($d);return[false,'Could not save/extract project.'];}$sz=size($d);if($sz>(int)$u['max_storage']){rr($d);return[false,'Storage limit exceeded.'];}$t=type($d);if($t==='unknown'){rr($d);return[false,'PHP/Python/HTML project not detected.'];}$n=time();q("INSERT INTO projects(user_tg_id,name,slug,type,status,access_required,created_at,updated_at)VALUES(:t,:n,:s,:y,'pending',1,:c,:c)",[':t'=>$tg,':n'=>$name,':s'=>$s,':y'=>$t,':c'=>$n]);$id=(int)db()->lastInsertRowID();logx($tg,$id,'PROJECT_CREATED',$name);return[true,(string)$id];}
function shellok():bool{return function_exists('shell_exec')&&function_exists('exec');}
function start(array $p):array{if($p['status']==='running')return[true,'Already running.'];if($p['type']==='python'){if(!shellok())return[false,'Server process execution is disabled.'];$e=entry(pdir($p),'python');if(!$e)return[false,'Python entry file not found.'];$py=trim((string)@shell_exec('command -v python3 2>/dev/null'));if(!$py)$py=trim((string)@shell_exec('command -v python 2>/dev/null'));if(!$py)return[false,'Python is not installed.'];$r=RUNTIME_ROOT.'/'.$p['id'];@mkdir($r,0750,true);$log=$r.'/output.log';$cmd='cd '.escapeshellarg(pdir($p)).' && nohup '.escapeshellarg($py).' '.escapeshellarg($e).' > '.escapeshellarg($log).' 2>&1 & echo $!';$pid=(int)trim((string)@shell_exec($cmd));if($pid<=0)return[false,'Python process failed to start.'];q("UPDATE projects SET status='running',runtime_pid=:p,updated_at=:n WHERE id=:i",[':p'=>$pid,':n'=>time(),':i'=>(int)$p['id']]);return[true,'Python started. PID '.$pid];}if(in_array($p['type'],['php','html'],true)){q("UPDATE projects SET status='running',updated_at=:n WHERE id=:i",[':n'=>time(),':i'=>(int)$p['id']]);return[true,'Project is live at /site/'.$p['id'].'/'];}return[false,'Unknown project type.'];}
function stop(array $p):array{if($p['type']==='python'&&shellok()&&(int)$p['runtime_pid']>0){$pid=(int)$p['runtime_pid'];@shell_exec('kill '.$pid.' 2>/dev/null');usleep(250000);@shell_exec('kill -9 '.$pid.' 2>/dev/null');}q("UPDATE projects SET status='stopped',runtime_pid=NULL,updated_at=:n WHERE id=:i",[':n'=>time(),':i'=>(int)$p['id']]);return[true,'Project stopped.'];}
function owner():string{return 'Owner: @'.htmlspecialchars(ltrim(OWNER_USERNAME,'@'));}
function projects(string $tg):string{$r=allr("SELECT * FROM projects WHERE user_tg_id=:t ORDER BY id DESC",[':t'=>$tg]);if(!$r)return"📦 <b>My Projects</b>\n\nNo projects."; $s="📦 <b>My Projects</b>\n\n";foreach($r as $p)$s.="#{$p['id']} <code>".htmlspecialchars($p['slug'])."</code> | {$p['type']} | {$p['status']}\n";return$s;}
function adminUsers():array{$r=allr("SELECT * FROM users ORDER BY id DESC LIMIT 40");$b=[];foreach($r as $u)$b[]=[['text'=>($u['hosting_access']?'🟢 ':'🔴 ').($u['username']?'@'.$u['username']:$u['tg_id']),'callback_data'=>'u:'.$u['tg_id']]];return['inline_keyboard'=>$b];}

function update(array $u):void{
 if(isset($u['callback_query'])){ $c=$u['callback_query'];$f=$c['from']??[];$me=user($f);$tg=(string)$me['tg_id'];$d=(string)($c['data']??'');$chat=(string)($c['message']['chat']['id']??$tg);$mid=(int)($c['message']['message_id']??0);ans((string)$c['id']);
  if(!admin($tg))return;
  [$a,$v]=array_pad(explode(':',$d,2),2,'');
  if(in_array($a,['start','stop','restart','delete'],true)){ $p=one("SELECT * FROM projects WHERE id=:i",[':i'=>(int)$v]);if(!$p){edit($chat,$mid,'❌ Project not found.');return;}if($a==='delete'){if($p['type']==='python')stop($p);rr(pdir($p));q("DELETE FROM projects WHERE id=:i",[':i'=>(int)$v]);edit($chat,$mid,'🗑 <b>Deleted.</b>');return;}if($a==='stop')$r=stop($p);else{if($a==='restart')stop($p);$r=start($p);}logx($tg,(int)$v,'ADMIN_'.$a,$r[1]);edit($chat,$mid,($r[0]?'✅ ':'❌ ').htmlspecialchars($r[1]));return;}
  if($a==='u'){ $x=one("SELECT * FROM users WHERE tg_id=:t",[':t'=>$v]);if(!$x){edit($chat,$mid,'Not found.');return;}edit($chat,$mid,"👤 <b>User</b>\nID: <code>{$x['tg_id']}</code>\nUsername: ".htmlspecialchars($x['username']?:'none')."\nAccess: ".($x['hosting_access']?'🟢 ON':'🔴 OFF'),['inline_keyboard'=>[[['text'=>'🔓 Grant','callback_data'=>'g:'.$v],['text'=>'🔒 Revoke','callback_data'=>'r:'.$v]]]]);return;}
  if($a==='g'||$a==='r'){q("UPDATE users SET hosting_access=:a WHERE tg_id=:t",[':a'=>$a==='g'?1:0,':t'=>$v]);edit($chat,$mid,($a==='g'?'✅ Access granted.':'🔒 Access revoked.'));if($a==='g')send($v,"✅ <b>Hosting access granted.</b>\n\nProjects still require Admin activation.");return;}
  return;
 }
 $m=$u['message']??null;if(!$m)return;$me=user($m['from']??[]);$tg=(string)$me['tg_id'];$chat=(string)($m['chat']['id']??$tg);$text=trim((string)($m['text']??''));if($me['blocked']&&!admin($tg)){send($chat,'🚫 Account blocked.');return;}
 if(isset($m['document'])){$doc=$m['document'];if((int)($doc['file_size']??0)>MAX_UPLOAD_BYTES){send($chat,'❌ Maximum upload is 50 MB.');return;}$gf=tg('getFile',['file_id'=>$doc['file_id']]);$path=$gf['result']['file_path']??'';if(!$path){send($chat,'❌ Download failed.');return;}$tmp=sys_get_temp_dir().'/host_'.bin2hex(random_bytes(8));$raw=@file_get_contents('https://api.telegram.org/file/bot'.BOT_TOKEN.'/'.$path);if($raw===false){send($chat,'❌ Download failed.');return;}file_put_contents($tmp,$raw);[$ok,$id]=createProject($tg,$tmp,$doc['file_name']??'project.zip');@unlink($tmp);if(!$ok){send($chat,'❌ '.htmlspecialchars($id));return;}$msg="✅ <b>Project uploaded.</b>\nProject ID: <code>{$id}</code>\n\n";$msg.=$me['hosting_access']?"⏳ Admin activation required.":"🔒 <b>Hosting access required.</b>\n".owner();send($chat,$msg);return;}
 if($text==='/start'||$text==='⬅️ User Menu'){send($chat,"🚀 <b>Hosting Bot</b>\n\nUpload PHP/Python/HTML/ZIP projects.\n\n⚠️ User cannot Start/Stop/Restart. Admin controls activation.",admin($tg)?akb():kb());return;}
 if($text==='/admin'&&admin($tg)){send($chat,'👑 <b>Admin Panel</b>',akb());return;}
 if($text==='👥 Users'&&admin($tg)){send($chat,'👥 <b>Users</b>',adminUsers());return;}
 if($text==='📦 All Projects'&&admin($tg)){ $r=allr("SELECT * FROM projects ORDER BY id DESC LIMIT 50");$s="📦 <b>All Projects</b>\n\n";foreach($r as $p)$s.="#{$p['id']} | {$p['user_tg_id']} | ".htmlspecialchars($p['slug'])." | {$p['type']} | {$p['status']}\n";send($chat,$s);return;}
 if($text==='🔐 Access Requests'&&admin($tg)){ $r=allr("SELECT * FROM access_requests WHERE status='pending' ORDER BY id DESC LIMIT 30");if(!$r){send($chat,'📨 No pending requests.');return;}foreach($r as $x)send($chat,"📨 Request #{$x['id']}\nUser: <code>{$x['tg_id']}</code>\nProject: #{$x['project_id']}",['inline_keyboard'=>[[['text'=>'🔓 Grant','callback_data'=>'g:'.$x['tg_id']],['text'=>'▶️ Start','callback_data'=>'start:'.$x['project_id']]]]]);return;}
 if($text==='📊 Statistics'&&admin($tg)){send($chat,"📊 <b>Statistics</b>\n\nUsers: ".(one("SELECT COUNT(*) c FROM users")['c']??0)."\nProjects: ".(one("SELECT COUNT(*) c FROM projects")['c']??0)."\nRunning: ".(one("SELECT COUNT(*) c FROM projects WHERE status='running'")['c']??0)."\nAccess enabled: ".(one("SELECT COUNT(*) c FROM users WHERE hosting_access=1")['c']??0));return;}
 if($text==='🖥 Server Status'&&admin($tg)){send($chat,"🖥 <b>Server</b>\nPHP: ".PHP_VERSION."\nShell: ".(shellok()?'ON':'OFF')."\nPython: ".(trim((string)@shell_exec('command -v python3 2>/dev/null'))?'FOUND':'NOT FOUND'));return;}
 if($text==='📋 Logs'&&admin($tg)){ $r=allr("SELECT * FROM logs ORDER BY id DESC LIMIT 30");$s="📋 <b>Logs</b>\n\n";foreach($r as $x)$s.="#{$x['id']} {$x['action']} ".htmlspecialchars((string)$x['details'])."\n";send($chat,$s);return;}
 if($text==='📤 Upload Project'){send($chat,"📤 <b>Upload Project</b>\n\nSend PHP, Python, HTML or ZIP.\nMax 50 MB.\n\nUpload does not automatically start it.");return;}
 if($text==='📦 My Projects'){send($chat,projects($tg));return;}
 if($text==='🔐 Hosting Access'){send($chat,$me['hosting_access']?"🟢 <b>Hosting access enabled.</b>\n\nAdmin activation is still required.":"🔒 <b>No hosting access.</b>\n\n".owner());return;}
 if($text==='👤 My Account'){send($chat,"👤 <b>Account</b>\nID: <code>{$tg}</code>\nProjects: ".(one("SELECT COUNT(*) c FROM projects WHERE user_tg_id=:t",[':t'=>$tg])['c']??0)."/{$me['max_projects']}\nAccess: ".($me['hosting_access']?'🟢 ON':'🔴 OFF'));return;}
 if($text==='ℹ️ Help'){send($chat,"ℹ️ <b>How it works</b>\n\n1. Upload project.\n2. It stays stopped.\n3. User cannot run it.\n4. Missing access → contact Owner.\n5. Admin grants access.\n6. Admin starts/stops/restarts/deletes.");return;}
 if(preg_match('/^\\/grant\\s+(\\d+)$/',$text,$x)&&admin($tg)){q("UPDATE users SET hosting_access=1 WHERE tg_id=:t",[':t'=>$x[1]]);send($chat,'✅ Access granted.');return;}
 if(preg_match('/^\\/revoke\\s+(\\d+)$/',$text,$x)&&admin($tg)){q("UPDATE users SET hosting_access=0 WHERE tg_id=:t",[':t'=>$x[1]]);send($chat,'🔒 Access revoked.');return;}
 if(preg_match('/^\\/startproject\\s+(\\d+)$/',$text,$x)&&admin($tg)){$p=one("SELECT * FROM projects WHERE id=:i",[':i'=>(int)$x[1]]);send($chat,$p?(start($p)[1]):'Project not found.');return;}
 if(preg_match('/^\\/stopproject\\s+(\\d+)$/',$text,$x)&&admin($tg)){$p=one("SELECT * FROM projects WHERE id=:i",[':i'=>(int)$x[1]]);send($chat,$p?(stop($p)[1]):'Project not found.');return;}
 send($chat,'Use the menu.',admin($tg)?akb():kb());
}

/* Hosted PHP/HTML route: only Admin-started projects are exposed. */
function serve():bool{
 $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/';
 if(!preg_match('#^/site/(\\d+)(?:/(.*))?$#',$path,$m))return false;
 $p=one("SELECT * FROM projects WHERE id=:i",[':i'=>(int)$m[1]]);
 if(!$p||$p['status']!=='running'||!in_array($p['type'],['php','html'],true)){http_response_code(404);echo'Project unavailable.';return true;}
 $root=realpath(pdir($p));$rel=$m[2]??'';if(!$root||str_contains($rel,'..')||str_starts_with($rel,'/')){http_response_code(403);echo'Forbidden.';return true;}
 $target=realpath($root.'/'.($rel?:($p['type']==='php'?'index.php':'index.html')));
 if(!$target||!str_starts_with($target,$root)||!is_file($target)){http_response_code(404);echo'Not found.';return true;}
 if(strtolower(pathinfo($target,PATHINFO_EXTENSION))==='php'){chdir(dirname($target));include $target;return true;}
 $mime=['html'=>'text/html; charset=utf-8','htm'=>'text/html; charset=utf-8','css'=>'text/css','js'=>'application/javascript','json'=>'application/json','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml','webp'=>'image/webp','txt'=>'text/plain'];
 header('Content-Type:'.($mime[strtolower(pathinfo($target,PATHINFO_EXTENSION))]??'application/octet-stream'));readfile($target);return true;
}
if(serve())exit;

if($_SERVER['REQUEST_METHOD']==='POST'){
 if(BOT_TOKEN==='PUT_YOUR_BOT_TOKEN_HERE'){http_response_code(500);exit('Configure BOT_TOKEN.');}
 $u=json_decode(file_get_contents('php://input'),true);if(is_array($u))update($u);echo'OK';exit;
}
if(isset($_GET['health'])){header('Content-Type:application/json');echo json_encode(['ok'=>true,'php'=>PHP_VERSION,'database'=>is_file(DB_FILE),'shell'=>shellok(),'python'=>trim((string)@shell_exec('command -v python3 2>/dev/null'))!=='']);exit;}
if(isset($_GET['setwebhook'])){
 if(BOT_TOKEN==='PUT_YOUR_BOT_TOKEN_HERE')exit('Configure BOT_TOKEN first.');
 $base=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').strtok($_SERVER['REQUEST_URI']??'/','?');
 $r=tg('setWebhook',['url'=>$base]);header('Content-Type:text/plain');echo($r['ok']??false)?'Webhook set: '.$base:'Webhook error';exit;
}
echo'Hosting Bot is running.';
?>
