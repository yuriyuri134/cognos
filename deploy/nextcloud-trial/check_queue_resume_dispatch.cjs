const fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const data=JSON.parse(fs.readFileSync(path.join(__dirname,'evidence/queue-resume.json'),'utf8'));
const output=execFileSync('docker',['exec','-u','www-data','-w','/var/www/html','cognos-nextcloud-trial-app-1','php','/tmp/check_queue_resume_dispatch.php',Buffer.from(JSON.stringify(data)).toString('base64')],{encoding:'utf8'});
console.log(output);fs.writeFileSync(path.join(__dirname,'evidence/queue-resume-dispatch.json'),JSON.stringify({result:'pass',checks:output.trim().split('\n')},null,2));