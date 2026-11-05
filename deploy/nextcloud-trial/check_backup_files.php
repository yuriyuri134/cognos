<?php
/** Standalone read-only filesystem manifest. No Nextcloud bootstrap and no file content output.
 * php check_backup_files.php [--output=/tmp/files.json] [--root=/var/www/html]
 */
declare(strict_types=1);
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,'Backup file fingerprint failed: '.get_class($e).' (no file content emitted)'.PHP_EOL);exit(1);});
$options=getopt('',['output:','root:']);$base=realpath($options['root']??'/var/www/html');
if($base===false||!is_dir($base))throw new RuntimeException('Snapshot root is not a directory');
$base=rtrim($base,DIRECTORY_SEPARATOR);$entries=[];$counts=['file'=>0,'directory'=>0,'symlink'=>0,'other'=>0];$bytes=0;
$excluded=static fn(string $relative):bool=>$relative==='config/zz-cognos-restore.config.php'||$relative==='data/nextcloud.log'||str_starts_with($relative,'data/nextcloud.log.');
$record=function(string $path,string $relative)use(&$entries,&$counts,&$bytes):void{
 $stat=lstat($path);if($stat===false)throw new RuntimeException('Unable to stat snapshot entry');
 $kind=$stat['mode']&0170000;$type=match($kind){0100000=>'file',0040000=>'directory',0120000=>'symlink',default=>'other'};
 $entry=['path'=>$relative,'type'=>$type,'uid'=>(int)$stat['uid'],'gid'=>(int)$stat['gid'],'mode'=>sprintf('%04o',$stat['mode']&07777)];
 if($type==='file'){$hash=hash_file('sha256',$path);if($hash===false)throw new RuntimeException('Unable to hash snapshot file');$entry['size']=(int)$stat['size'];$entry['sha256']=$hash;$bytes+=(int)$stat['size'];}
 elseif($type==='symlink'){$target=readlink($path);if($target===false)throw new RuntimeException('Unable to read snapshot symlink');$entry['target']=$target;}
 elseif($type==='other'){$entry['mode_type']=sprintf('%06o',$kind);}
 ++$counts[$type];$entries[$relative]=$entry;
};
$record($base,'.');
$walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
foreach($walk as $file){$path=$file->getPathname();$relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($base)+1));if($excluded($relative))continue;$record($path,$relative);}
ksort($entries,SORT_STRING);$hash=hash_init('sha256');
foreach($entries as $entry){$line=json_encode($entry,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);hash_update($hash,(string)strlen($line).':'.$line."\n");}
$report=['format'=>'cognos-backup-files-v1','scope'=>'Nextcloud html tree; regular files hashed, symlinks recorded without following','excluded_paths'=>['config/zz-cognos-restore.config.php'=>'isolated restore overlay; not source data','data/nextcloud.log'=>'operational log, excluded from data identity','data/nextcloud.log.*'=>'rotated operational logs, excluded from data identity'],'counts'=>$counts,'file_bytes'=>$bytes,'aggregate_sha256'=>hash_final($hash),'entries'=>array_values($entries)];
$json=json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
if(isset($options['output'])&&file_put_contents($options['output'],$json)===false)throw new RuntimeException('Unable to write file fingerprint');
echo $json;
