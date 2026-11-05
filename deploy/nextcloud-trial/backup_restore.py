#!/usr/bin/env python3
"""Complete local backup and isolated restore drill for this deployment. Never restores over production."""
from __future__ import annotations
import argparse, datetime, getpass, hashlib, json, os, re, shutil, subprocess, sys, tarfile, time, urllib.request, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parent; REPO=ROOT.parent.parent
APP='cognos-nextcloud-trial-app-1'; DB='cognos-nextcloud-trial-db-1'; CRON='cognos-nextcloud-trial-cron-1'; REDIS='cognos-nextcloud-trial-redis-1'
def run(args,*,out=None,raw=False,input=None):
    p=subprocess.run([str(x) for x in args],stdout=out or subprocess.PIPE,stderr=subprocess.PIPE,input=input,**({} if raw else {'text':True,'encoding':'utf-8'}))
    if p.returncode:
        logs=ROOT/'.restore-drills';logs.mkdir(exist_ok=True)
        log=logs/('operation-error-'+uuid.uuid4().hex+'.txt');log.write_bytes(p.stderr if isinstance(p.stderr,bytes) else (p.stderr or '').encode('utf-8'))
        raise RuntimeError('Operation failed: '+str(args[0])+' '+str(args[1])+'; private details: '+str(log))
    return p.stdout

def docker(*args,**kw):return run(['docker',*args],**kw)
def inspect(name):return json.loads(docker('inspect',name))[0]
def occ(container,*args):return docker('exec','-u','www-data','-w','/var/www/html',container,'php','occ',*args)
def save_json(path,data):path.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
def sha(path):
    h=hashlib.sha256()
    with path.open('rb') as f:
        for b in iter(lambda:f.read(4*1024*1024),b''):h.update(b)
    return h.hexdigest()
def artifact(path):return {'bytes':path.stat().st_size,'sha256':sha(path)}
def archive(path,source):
    with tarfile.open(path,'w:gz') as t:t.add(source,arcname='data',recursive=True)
def private_dir(path):
    path.mkdir(parents=True,exist_ok=False)
    if os.name=='nt':run(['icacls',path,'/inheritance:r','/grant:r',getpass.getuser()+':(OI)(CI)F','SYSTEM:(OI)(CI)F'])
def health():
    try:
        with urllib.request.urlopen('http://127.0.0.1:8643/health',timeout=3) as r:return json.load(r)
    except OSError:return None
def fingerprint(container,path):
    docker('cp',ROOT/'check_backup_state.php',container+':/tmp/check_backup_state.php')
    text=docker('exec','-u','www-data','-w','/var/www/html',container,'php','/tmp/check_backup_state.php');data=json.loads(text);save_json(path,data);return data

def file_fingerprint(container,path):
    docker('cp',ROOT/'check_backup_files.php',container+':/tmp/check_backup_files.php')
    data=json.loads(docker('exec',container,'php','/tmp/check_backup_files.php'));save_json(path,data);return data

def roles_fingerprint(container,user,exclude=''):
    role_sql="SELECT coalesce(json_agg(row_to_json(r) ORDER BY rolname),'[]'::json) FROM (SELECT rolname,rolsuper,rolinherit,rolcreaterole,rolcreatedb,rolcanlogin,rolreplication,rolbypassrls,rolconnlimit,rolvaliduntil,rolpassword FROM pg_authid WHERE left(rolname,3) <> 'pg_' AND rolname <> '"+exclude+"')r"
    # Role password verifiers remain only in memory; reports contain a count and digest.
    roles=json.loads(docker('exec',container,'psql','-U',user,'-d','postgres','-At','-c',role_sql))
    return {'count':len(roles),'sha256':hashlib.sha256(json.dumps(roles,sort_keys=True,separators=(',',':')).encode()).hexdigest()}

def backup(args):
    app=inspect(APP); db=inspect(DB); cron=inspect(CRON); redis=inspect(REDIS)
    if not app['State']['Running'] or not db['State']['Running']:raise RuntimeError('Start the source app/database before a backup')
    githead=run(['git','-C',REPO,'rev-parse','HEAD']).strip();dirty=run(['git','-C',REPO,'status','--porcelain','--untracked-files=all'])
    root=ROOT/'.backups';root.mkdir(exist_ok=True)
    target=root/('backup-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+uuid.uuid4().hex[:8]);private_dir(target)
    (target/'INCOMPLETE').write_text('Do not restore until manifest.json and COMPLETE exist.\n')
    original=json.loads(occ(APP,'status','--output=json'));was_maintenance=bool(original['maintenance'])
    h=health();bridge_stopped=False;app_stopped=False;cron_stopped=False
    if h and h.get('busy'):raise RuntimeError('An AI is working; no snapshot taken and no process stopped')
    if h and not args.bridge_pid:raise RuntimeError('Provide the verified idle bridge PID to freeze its local outbox, or stop the idle bridge first')
    if args.bridge_pid:
        ps="$p=Get-CimInstance Win32_Process -Filter 'ProcessId = "+str(args.bridge_pid)+"'; if($p){$p.CommandLine}"
        command=run(['powershell','-NoProfile','-Command',ps]).strip()
        if str(REPO/'tools/cognos-space-mcp/claude_bridge.py').lower() not in command.lower():raise RuntimeError('Bridge PID does not belong to this repository')
        if not h or not h.get('ok'):raise RuntimeError('Cannot verify bridge admission/idle state')
    m={'format':'cognos-local-backup-v1','created_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'source_git_head':githead,'source_dirty_paths':dirty.splitlines(),'nextcloud_version':original['versionstring'],'cognos_version':__import__('xml.etree.ElementTree',fromlist=['parse']).parse(ROOT/'cognos-app/appinfo/info.xml').getroot().findtext('version'),'images':{'app':app['Image'],'db':db['Image'],'redis':redis['Image']},'image_references':{'app':app['Config']['Image'],'db':db['Config']['Image'],'redis':redis['Config']['Image']},'runtime_paths':[],'artifacts':{},'exclusions':['global Codex/Claude authentication and browser profiles; reauthenticate providers separately','transient tunnel URL/state is not relaunched on restore'],'source_maintenance_before':was_maintenance}
    try:
        occ(APP,'maintenance:mode','--on')
        if cron['State']['Running']:docker('stop','--time','30',CRON);cron_stopped=True
        # Close the source web writers before freezing the separately persisted bridge outbox.
        docker('stop','--time','30',APP);app_stopped=True
        if h:
            checked=health()
            if not checked or checked.get('busy'):raise RuntimeError('Bridge changed during quiescence; no bridge process stopped')
            run(['powershell','-NoProfile','-Command','Stop-Process -Id '+str(args.bridge_pid)+' -ErrorAction Stop']);bridge_stopped=True
        print('Source writers frozen; archiving paired files, database and local delivery state.',flush=True)
        volume=next(x['Name'] for x in app['Mounts'] if x['Destination']=='/var/www/html')
        with (target/'database.dump').open('wb') as f:docker('exec',DB,'pg_dump','-U','nextcloud','-d','nextcloud','--format=custom','--create',out=f,raw=True)
        with (target/'database-globals.sql').open('wb') as f:docker('exec',DB,'pg_dumpall','-U','nextcloud','--globals-only',out=f,raw=True)
        save_json(target/'database-roles-state.json',roles_fingerprint(DB,'nextcloud'))
        # The separate bind-mounted app has its own matching archive; do not snapshot an underlying stale directory.
        with (target/'html.tar.gz').open('wb') as f:docker('run','--rm','--network','none','--mount','type=volume,source='+volume+',target=/source,readonly','--entrypoint','tar',app['Image'],'--exclude=./custom_apps/cognos','-C','/source','-czpf','-','.',out=f,raw=True)
        with (target/'app.tar.gz').open('wb') as f:docker('run','--rm','--network','none','--mount','type=bind,source='+str(ROOT/'cognos-app')+',target=/source,readonly','--entrypoint','tar',app['Image'],'-C','/source','-czpf','-','.',out=f,raw=True)
        paths=[('secrets',ROOT/'.secrets'),('bridge-outbox',Path(os.environ.get('COGNOS_BRIDGE_OUTBOX',str(ROOT/'evidence/claude-bridge/outbox')))),('bridge-runs',Path(os.environ.get('COGNOS_RUN_LOG_DIR',str(ROOT/'evidence/claude-bridge/runs')))),('bridge-spaces',Path(os.environ.get('COGNOS_SPACES_DIR',str(Path.home()/'Documents/cognos-spaces')))),('mcp-state',Path(os.environ.get('COGNOS_MCP_STATE_DIR',str(Path.home()/'.local/state/cognos-mcp')))),('local-state',ROOT/'.cognos-state'),('local-vault',ROOT/'.cognos-vault')]
        paths += [('mcp-session-'+str(i),p) for i,p in enumerate(sorted((ROOT/'evidence').glob('*/handoff-state')))]
        paths += [('fixture-outbox',args.extra_outbox)] if args.extra_outbox else []
        for i,mount in enumerate(app['Mounts']):
            if mount['Type']=='bind' and mount['Destination'].startswith('/mnt/'):
                # Use the runtime's actual acquired external material mount, not an assumed vault path.
                with (target/('external-'+str(i)+'.tar.gz')).open('wb') as f:docker('run','--rm','--network','none','--mount','type=bind,source='+mount['Source']+',target=/source,readonly','--entrypoint','tar',app['Image'],'-C','/source','-czpf','-','.',out=f,raw=True)
                m['runtime_paths'].append({'name':'external-'+str(i),'source':mount['Source'],'destination':mount['Destination'],'included':True})
        for label,p in paths:
            p=Path(p);present=p.exists();m['runtime_paths'].append({'name':label,'source':str(p),'included':present})
            if present:archive(target/('host-'+label+'.tar.gz'),p)
        shutil.copyfile(ROOT/'compose.yaml',target/'source-compose.yaml')
        # Fingerprint through a temporary CLI using the source volume and network while the web remains stopped.
        network=next(iter(app['NetworkSettings']['Networks']))
        helper='cognos-backup-fingerprint-'+uuid.uuid4().hex[:8]
        docker('create','--name',helper,'--network',network,'--mount','type=volume,source='+volume+',target=/var/www/html','--mount','type=bind,source='+str(ROOT/'cognos-app')+',target=/var/www/html/custom_apps/cognos,readonly','--mount','type=bind,source='+str(ROOT/'.secrets/db-password')+',target=/run/secrets/db_password,readonly','-e','REDIS_HOST=redis','-e','POSTGRES_PASSWORD_FILE=/run/secrets/db_password','--entrypoint','sleep',app['Image'],'infinity')
        try:docker('start',helper);fingerprint(helper,target/'database-state.json');file_fingerprint(helper,target/'files-state.json')
        finally:docker('rm','-f',helper)
    finally:
        if app_stopped:docker('start',APP)
        if app_stopped or not was_maintenance:
            if not was_maintenance:occ(APP,'maintenance:mode','--off')
        if cron_stopped:docker('start',CRON)
        if bridge_stopped:
            command=['powershell','-NoProfile','-ExecutionPolicy','Bypass','-File',REPO/'tools/cognos-space-mcp/start-bridge.ps1']
            if args.bridge_no_commands:command.append('-NoCommands')
            if args.bridge_no_self_merge:command.append('-NoSelfMerge')
            # Detached bridge children may inherit pipe handles; wait only for the launcher process.
            subprocess.run([str(x) for x in command],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)
    # This portable image export needs no paused writers and does not extend the service interruption.
    if not args.skip_images:
        print('Source resumed; saving the exact application/database/cache images.',flush=True)
        with (target/'images.tar').open('wb') as f:docker('image','save',*dict.fromkeys(m['images'].values()),out=f,raw=True)
    for name in ('backup_restore.py','check_backup_state.php','check_backup_files.php'):shutil.copyfile(ROOT/name,target/name)
    for p in sorted(target.iterdir()):
        if p.is_file() and p.name not in ('INCOMPLETE','manifest.json','COMPLETE'):m['artifacts'][p.name]=artifact(p)
    save_json(target/'manifest.json',m);(target/'COMPLETE').write_text('All listed artifact hashes must match before restoring.\n');(target/'INCOMPLETE').unlink()
    print(json.dumps({'backup':str(target),'artifacts':len(m['artifacts']),'bytes':sum(x['bytes'] for x in m['artifacts'].values()),'source_resumed':not was_maintenance},ensure_ascii=False),flush=True)

def verify_backup(path):
    path=path.resolve()
    if not (path/'COMPLETE').is_file() or (path/'INCOMPLETE').exists():raise RuntimeError('Backup is incomplete')
    m=json.loads((path/'manifest.json').read_text(encoding='utf-8'))
    if m.get('format')!='cognos-local-backup-v1':raise RuntimeError('Unknown backup format')
    required={'database.dump','database-globals.sql','database-roles-state.json','database-state.json','files-state.json','html.tar.gz','app.tar.gz','host-secrets.tar.gz'}
    if not required.issubset(m['artifacts']):raise RuntimeError('Backup lacks required database roles or source artifacts')
    for name,expected in m['artifacts'].items():
        if Path(name).name!=name or artifact(path/name)!=expected:raise RuntimeError('Backup artifact failed integrity validation: '+name)
    return m

def publish_ingress(state_file):
    """Publish only a fixed-target proxy. The restored application stays on its internal network."""
    state = json.loads(state_file.read_text(encoding='utf-8'))
    prefix = state['prefix']
    if not re.fullmatch(r'cognos-restore-[a-f0-9]{10}', prefix):
        raise RuntimeError('Invalid isolated restore identity')
    token = prefix.removeprefix('cognos-restore-')
    app, network, port = state['app'], state['network'], int(state['port'])
    if (app, network) != (prefix+'-app', prefix+'-net') or port == 8780 or not 1024 <= port <= 65535:
        raise RuntimeError('Invalid isolated restore target')
    app_info = inspect(app)
    net_info = inspect(network)
    if (app_info['Config']['Labels'].get('cognos.restore') != token or
        set(app_info['NetworkSettings']['Networks']) != {network} or not net_info['Internal'] or
        occ(app,'config:system:get','cognos_restore_quarantine').strip().lower() not in ('1','true')):
        raise RuntimeError('Application must remain quarantined on its dedicated internal network')
    proxy = prefix+'-ingress'
    config = f"""<VirtualHost *:80>
    ProxyRequests Off
    ProxyPreserveHost On
    ProxyPass / http://{app}:80/
    ProxyPassReverse / http://{app}:80/
</VirtualHost>
"""
    launch = "a2enmod proxy proxy_http >/dev/null && cat > /etc/apache2/sites-enabled/000-default.conf <<'COGNOS_PROXY'\n"+config+"COGNOS_PROXY\nexec apache2-foreground"
    docker('create','--name',proxy,'--label','cognos.restore='+token,'--label','cognos.restore.backend='+app,
           '--network','bridge','--publish',f'127.0.0.1:{port}:80','--entrypoint','sh',app_info['Image'],'-c',launch)
    docker('network','connect',network,proxy)
    docker('start',proxy)
    for _ in range(20):
        try:
            with urllib.request.urlopen(f'http://127.0.0.1:{port}/status.php',timeout=2) as response:
                status=json.load(response)
            if status['installed'] and not status['maintenance']:
                state['ingress']=proxy
                save_json(state_file,state)
                return state
        except (OSError, ValueError, KeyError):
            pass
        time.sleep(.5)
    raise RuntimeError('Isolated HTTP ingress did not become ready')

def drill(args):
    path=args.backup.resolve();m=verify_backup(path);token=uuid.uuid4().hex[:10];prefix='cognos-restore-'+token
    stage=ROOT/'.restore-drills'/token;stage.parent.mkdir(exist_ok=True);private_dir(stage)
    # This extractor processes only the local credential archive; it never executes source code on the host.
    with tarfile.open(path/'host-secrets.tar.gz') as t:t.extractall(stage/'credentials',filter='data')
    secret=stage/'bootstrap-db-password';secret.write_text(uuid.uuid4().hex+uuid.uuid4().hex,encoding='utf-8');bootstrap='restore_bootstrap_'+token
    state={'format':'cognos-isolated-restore-v1','prefix':prefix,'source_backup':str(path),'port':args.port,'app':prefix+'-app','db':prefix+'-db','redis':prefix+'-redis','network':prefix+'-net','html_volume':prefix+'-html','db_volume':prefix+'-db','config_overrides':['cognos_restore_quarantine=true','loopback drill URL/domains','maintenance mode after paired DB/files restore'],'no_cron':True,'no_host_bridge':True}
    save_json(stage/'state.json',state)
    missing=any(subprocess.run(['docker','image','inspect',image],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode for image in m['images'].values())
    if missing:
        if not (path/'images.tar').exists():raise RuntimeError('Exact images are absent and this backup omitted image export')
        docker('image','load','--input',path/'images.tar')
    for image in m['images'].values():docker('image','inspect',image)
    docker('network','create','--internal','--label','cognos.restore='+token,state['network'])
    for volume in (state['html_volume'],state['db_volume']):
        if subprocess.run(['docker','volume','inspect',volume],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0:raise RuntimeError('Refusing to reuse any existing restore volume')
        docker('volume','create','--label','cognos.restore='+token,volume)
    dbargs=['run','-d','--name',state['db'],'--label','cognos.restore='+token,'--network',state['network'],'--network-alias','db','--mount','type=volume,source='+state['db_volume']+',target=/var/lib/postgresql/data','--mount','type=bind,source='+str(secret)+',target=/run/secrets/db_password,readonly','-e','POSTGRES_DB=postgres','-e','POSTGRES_USER='+bootstrap,'-e','POSTGRES_PASSWORD_FILE=/run/secrets/db_password',m['images']['db']]
    docker(*dbargs);docker('run','-d','--name',state['redis'],'--label','cognos.restore='+token,'--network',state['network'],'--network-alias','redis',m['images']['redis'])
    deadline=time.monotonic()+60
    while True:
        p=subprocess.run(['docker','exec',state['db'],'pg_isready','-U',bootstrap,'-d','postgres'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        if p.returncode==0:break
        if time.monotonic()>deadline:raise RuntimeError('Restore database did not become ready; drill state preserved')
        time.sleep(.5)
    if not (path/'database-globals.sql').exists():raise RuntimeError('This older incomplete-format backup omitted database roles; it cannot be used for a full restore')
    docker('cp',path/'database-globals.sql',state['db']+':/tmp/globals.sql');docker('exec',state['db'],'psql','-U',bootstrap,'-d','postgres','-v','ON_ERROR_STOP=1','--file','/tmp/globals.sql')
    docker('cp',path/'database.dump',state['db']+':/tmp/restore.dump');docker('exec',state['db'],'pg_restore','-U',bootstrap,'-d','postgres','--create','--exit-on-error','/tmp/restore.dump')
    restored_roles=roles_fingerprint(state['db'],bootstrap,bootstrap);original_roles=json.loads((path/'database-roles-state.json').read_text(encoding='utf-8'))
    if restored_roles!=original_roles:raise RuntimeError('Restored database roles/credentials do not match the source')
    state['database_roles_match']=True
    mounts=['--mount','type=volume,source='+state['html_volume']+',target=/restore','--mount','type=bind,source='+str(path)+',target=/backup,readonly']
    docker('run','--rm','--network','none',*mounts,'--entrypoint','sh',m['images']['app'],'-c','tar -xzpf /backup/html.tar.gz -C /restore && mkdir -p /restore/custom_apps/cognos && tar -xzpf /backup/app.tar.gz -C /restore/custom_apps/cognos')
    # A separate late-loaded local overlay never modifies the original config archive or database grants.
    overlay=stage/'zz-cognos-restore.config.php';overlay.write_text("<?php\n$CONFIG=['cognos_restore_quarantine'=>true,'maintenance'=>true,'trusted_domains'=>['127.0.0.1','localhost','127.0.0.1:"+str(args.port)+"'],'overwrite.cli.url'=>'http://127.0.0.1:"+str(args.port)+"'];\n",encoding='utf-8')
    helper=prefix+'-files'
    docker('create','--name',helper,'--network','none','--mount','type=volume,source='+state['html_volume']+',target=/var/www/html','--entrypoint','sleep',m['images']['app'],'infinity')
    docker('start',helper)
    try:
        files=file_fingerprint(helper,stage/'restored-files-state.json');original_files=json.loads((path/'files-state.json').read_text(encoding='utf-8'))
        if files!=original_files:raise RuntimeError('Restored file content/ownership/modes do not match the frozen source; drill preserved')
        docker('cp',overlay,helper+':/var/www/html/config/zz-cognos-restore.config.php')
    finally:docker('rm','-f',helper)
    docker('create','--name',state['app'],'--label','cognos.restore='+token,'--network',state['network'],'--mount','type=volume,source='+state['html_volume']+',target=/var/www/html','--mount','type=bind,source='+str(stage/'credentials/data/db-password')+',target=/run/secrets/db_password,readonly','-e','POSTGRES_HOST=db','-e','POSTGRES_DB=nextcloud','-e','POSTGRES_USER=nextcloud','-e','POSTGRES_PASSWORD_FILE=/run/secrets/db_password','-e','REDIS_HOST=redis','-e','HERMES_API_URL=http://127.0.0.1:9/unavailable',m['images']['app'])
    docker('start',state['app'])
    restored=fingerprint(state['app'],stage/'restored-database-state.json');original=json.loads((path/'database-state.json').read_text(encoding='utf-8'))
    if restored['tables']!=original['tables']:raise RuntimeError('Restored canonical rows/schema do not match the frozen source; quarantine preserved')
    # Unset the overlay maintenance flag only after exact pre-access state comparison; keep the dispatch guard.
    overlay.write_text(overlay.read_text(encoding='utf-8').replace("'maintenance'=>true","'maintenance'=>false"),encoding='utf-8');docker('cp',overlay,state['app']+':/var/www/html/config/zz-cognos-restore.config.php')
    state['files_match']=True;state['files_checked']=sum(files['counts'].values());state['database_match']=True;state['database_tables']=len(original['tables']);state['backup_sha256']=sha(path/'manifest.json');state['status']='ready_quarantined';save_json(stage/'state.json',state)
    publish_ingress(stage/'state.json')
    print(json.dumps({'drill':str(stage),'url':'http://127.0.0.1:'+str(args.port),'container':state['app'],'canonical_tables_match':len(original['tables']),'quarantined':True},ensure_ascii=False),flush=True)

def main():
    parser=argparse.ArgumentParser(description=__doc__);sub=parser.add_subparsers(dest='command',required=True)
    b=sub.add_parser('backup');b.add_argument('--bridge-pid',type=int);b.add_argument('--bridge-no-commands',action='store_true');b.add_argument('--bridge-no-self-merge',action='store_true');b.add_argument('--extra-outbox',type=Path);b.add_argument('--skip-images',action='store_true')
    d=sub.add_parser('drill');d.add_argument('backup',type=Path);d.add_argument('--port',type=int,default=8781)
    v=sub.add_parser('verify');v.add_argument('backup',type=Path)
    p=sub.add_parser('publish-ingress');p.add_argument('state',type=Path)
    args=parser.parse_args()
    if args.command=='backup':backup(args)
    elif args.command=='verify':verify_backup(args.backup);print('All archived artifact hashes match')
    elif args.command=='publish-ingress':print(publish_ingress(args.state)['ingress'])
    else:
        if args.port==8780 or not 1024<=args.port<=65535:raise RuntimeError('Drill must use a distinct unprivileged local port')
        drill(args)
if __name__=='__main__':
    try:main()
    except Exception as error:print(str(error),file=sys.stderr);sys.exit(1)
