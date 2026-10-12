#!/usr/bin/env python3
"""Root-only setup, JSON on stdin; secrets never appear in argv or output.

Application DB changes run as leadbridge. Root writes only protected control/etc paths.
The normal release receiver still deploys the single PHP artifact separately.
"""
import datetime, fcntl, json, os, pathlib, re, stat, subprocess, sys, tempfile, time

CONTROL = pathlib.Path('/var/lib/lead-bridge-control')
POOL = pathlib.Path('/etc/php/8.3/fpm/pool.d/leadbridge.conf')
SITE = pathlib.Path('/etc/nginx/sites-available/lead-bridge')
LOG = pathlib.Path('/etc/nginx/conf.d/lead-bridge-safe-log.conf')
DROP = pathlib.Path('/etc/systemd/system/lead-bridge.service.d/opsvault.conf')
ENV = CONTROL / 'opsvault.env'

def secure_directory(path):
    path = pathlib.Path(path)
    for part in (path, *path.parents):
        s = part.lstat()
        if not stat.S_ISDIR(s.st_mode) or s.st_uid != 0 or s.st_mode & 0o022:
            raise RuntimeError('Unsafe protected directory')

def secure_file(path, required=False):
    secure_directory(path.parent)
    if not path.exists() and not path.is_symlink():
        if required: raise RuntimeError('Required configuration is missing')
        return
    s = path.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_uid != 0 or s.st_nlink != 1 or s.st_mode & 0o022:
        raise RuntimeError('Unsafe protected file')

def atomic(path, data, mode=0o600):
    secure_file(path)
    fd, name = tempfile.mkstemp(prefix='.leadbridge-sso-', dir=path.parent)
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data); stream.flush(); os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name): os.unlink(name)

def validate(data):
    for name, pattern in {
        'client_id': r'[A-Za-z0-9_.-]{1,128}',
        'client_secret': r'[A-Za-z0-9._~+/:=-]{32,1024}',
        'encryption_key': r'[A-Za-z0-9_+/=-]{43,44}',
        'recovery_id': r'[A-Za-z0-9_.-]{1,80}',
        'recovery_login': r'[a-z0-9][a-z0-9_.-]{2,49}',
        'recovery_password': r'[A-Za-z0-9_-]{24,72}',
    }.items():
        if not isinstance(data.get(name), str) or not re.fullmatch(pattern, data[name]):
            raise RuntimeError('Invalid setup field: ' + name)
    import base64
    if len(base64.urlsafe_b64decode(data['encryption_key'])) != 32:
        raise RuntimeError('Invalid encryption key')
    if not isinstance(data.get('reservations'), list) or not data['reservations']:
        raise RuntimeError('Confirmed reservations are required before enabling JIT')
    users, subjects = set(), set()
    for row in data['reservations']:
        if not re.fullmatch(r'[A-Za-z0-9_.-]{1,80}', row.get('local_user_id', '')) or not re.fullmatch(r'[a-z0-9][a-z0-9_.-]{2,49}', row.get('local_login', '')) or not re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', row.get('subject', '')):
            raise RuntimeError('Invalid reservation')
        if row['local_user_id'] in users or row['subject'] in subjects:
            raise RuntimeError('Ambiguous reservation')
        users.add(row['local_user_id']); subjects.add(row['subject'])
    if data['recovery_id'] in users:
        raise RuntimeError('Recovery account cannot be reserved or linked')

APP_SETUP = r'''<?php
try {
 $a=json_decode(base64_decode('__INPUT__'),true,32,JSON_THROW_ON_ERROR);
 $db=new PDO('sqlite:/var/lib/lead-bridge/bridge.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000; BEGIN IMMEDIATE');
 $q=function($s,$p=[])use($db){$q=$db->prepare($s);$q->execute($p);return $q;};
 $before=$q('SELECT id,login,password,epoch,role,active FROM users ORDER BY id')->fetchAll();
 $owners=$q('SELECT id,owner FROM entities ORDER BY id')->fetchAll();
 $db->exec('CREATE TABLE IF NOT EXISTS sso_links(user_id TEXT NOT NULL REFERENCES users(id),issuer TEXT NOT NULL,subject TEXT NOT NULL,PRIMARY KEY(issuer,subject),UNIQUE(user_id,issuer));
 CREATE TABLE IF NOT EXISTS sso_attempts(browser TEXT PRIMARY KEY,data TEXT NOT NULL,expires INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS sso_reservations(user_id TEXT NOT NULL REFERENCES users(id),issuer TEXT NOT NULL,subject TEXT NOT NULL,PRIMARY KEY(issuer,subject),UNIQUE(user_id,issuer))');
 $issuer='https://portalwiki.world/sso';
 $old=$q('SELECT v FROM settings WHERE k="sso_client_id"')->fetchColumn();
 if($old!==false&&$old!==''&&$old!==$a['client_id'])throw new RuntimeException('client conflict');
 $ids=[];
 foreach($a['reservations'] as $r){
  if(!$q('SELECT 1 FROM users WHERE id=? AND login=? AND active=1',[$r['local_user_id'],$r['local_login']])->fetchColumn())throw new RuntimeException('local account mismatch');
  foreach(['sso_links','sso_reservations'] as $table){
   $rows=$q('SELECT user_id,subject FROM '.$table.' WHERE issuer=? AND (subject=? OR user_id=?)',[$issuer,$r['subject'],$r['local_user_id']])->fetchAll();
   foreach($rows as $old)if($old['user_id']!==$r['local_user_id']||$old['subject']!==$r['subject'])throw new RuntimeException('identity conflict');
  }
  $q('INSERT INTO sso_reservations VALUES(?,?,?) ON CONFLICT(issuer,subject) DO NOTHING',[$r['local_user_id'],$issuer,$r['subject']]);$ids[]=$r['local_user_id'];
 }
 $recovery=$q('SELECT * FROM users WHERE id=? OR login=?',[$a['recovery_id'],$a['recovery_login']])->fetchAll();
 if(!$recovery)$q('INSERT INTO users VALUES(?,?,?,?,?,?,?)',[$a['recovery_id'],$a['recovery_login'],'Аварийный администратор',password_hash($a['recovery_password'],PASSWORD_DEFAULT),'admin',1,bin2hex(random_bytes(16))]);
 elseif(count($recovery)!==1||$recovery[0]['id']!==$a['recovery_id']||$recovery[0]['login']!==$a['recovery_login']||$recovery[0]['role']!=='admin'||!$recovery[0]['active']||!password_verify($a['recovery_password'],$recovery[0]['password']))throw new RuntimeException('recovery conflict');
 if($q('SELECT 1 FROM sso_links WHERE user_id=?',[$a['recovery_id']])->fetchColumn())throw new RuntimeException('recovery linked');
 foreach(['sso_client_id'=>$a['client_id'],'sso_link_ids'=>implode(',',$ids),'sso_gate'=>'1','sso_jit'=>'1'] as $k=>$v)$q('INSERT INTO settings VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v',[$k,$v]);
 foreach($before as $old){$now=$q('SELECT id,login,password,epoch,role,active FROM users WHERE id=?',[$old['id']])->fetch();if($old!==$now)throw new RuntimeException('existing account changed');}
 if($owners!==$q('SELECT id,owner FROM entities ORDER BY id')->fetchAll())throw new RuntimeException('owners changed');
 $q('INSERT INTO audit(created,event,subject,actor) VALUES(?,?,?,?)',[gmdate('c'),'opsvault_configured','JIT enabled','system']);
 $db->exec('COMMIT');echo json_encode(['configured'=>true,'existing_users_preserved'=>count($before),'entities_preserved'=>count($owners),'reservations'=>count($ids),'jit'=>true]);
}catch(Throwable $e){if(isset($db))try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}fwrite(STDERR,"OpsVault setup rejected; private data not logged\n");exit(1);}
'''

def main():
    if os.geteuid() != 0: raise RuntimeError('Run as the server administrator')
    data=json.loads(sys.stdin.buffer.read(65537))
    validate(data)
    secure_directory(CONTROL)
    for name in ('deploy.lock','dispatch.lock'): secure_file(CONTROL/name,True)
    locks=[]
    for name in ('deploy.lock','dispatch.lock'):
        fd=os.open(CONTROL/name,os.O_RDONLY|os.O_NOFOLLOW)
        deadline=time.monotonic()+180
        while True:
            try: fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);break
            except BlockingIOError:
                if time.monotonic()>=deadline:os.close(fd);raise RuntimeError('Dispatch lock timeout')
                time.sleep(.25)
        locks.append(fd)
    for path in (POOL,SITE): secure_file(path,True)
    secure_directory(LOG.parent)
    secure_directory(DROP.parent)
    for path in (LOG,DROP,ENV): secure_file(path)
    values={'BRIDGE_SSO_CLIENT_SECRET':data['client_secret'],'BRIDGE_SSO_ENCRYPTION_KEY':data['encryption_key'],'BRIDGE_SSO_EMERGENCY_IDS':data['recovery_id']}
    env_bytes=''.join(f'{key}="{value}"\n' for key,value in values.items()).encode()
    if ENV.exists() and ENV.read_bytes()!=env_bytes: raise RuntimeError('Existing client secrets differ; rotation requires a separate procedure')
    backup=CONTROL/('opsvault-backup-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S')+'-'+os.urandom(4).hex())
    backup.mkdir(mode=0o700); secure_directory(backup)
    originals={path:path.read_bytes() if path.exists() else None for path in (POOL,SITE,LOG,DROP,ENV)}
    for number,(path,content) in enumerate(originals.items()):
        if content is not None: atomic(backup/str(number),content)
    subprocess.run(['systemctl','start','lead-bridge-backup.service'],check=True,capture_output=True)
    try:
        pool=POOL.read_text()
        pool='\n'.join(line for line in pool.splitlines() if not any(line.strip().startswith('env['+key+']') for key in values))+'\n'
        pool+=''.join(f'env[{key}] = "{value}"\n' for key,value in values.items())
        atomic(POOL,pool.encode()); atomic(ENV,env_bytes)
        atomic(DROP,('[Service]\nEnvironmentFile='+str(ENV)+'\n').encode())
        log="log_format lead_bridge_safe escape=json '{\"time\":\"$time_iso8601\",\"method\":\"$request_method\",\"path\":\"$uri\",\"status\":$status}';\n"
        atomic(LOG,log.encode(),0o644)
        site=SITE.read_text()
        if 'lead_bridge_safe' not in site:
            if site.count('server {')!=2: raise RuntimeError('Unexpected site structure')
            site=site.replace('server {','server {\n    access_log /var/log/nginx/lead-bridge-access.log lead_bridge_safe;')
            for marker in ('location = / {','location = /action.php {'):
                if marker not in site: raise RuntimeError('Callback location missing')
                site=site.replace(marker,marker+'\n        error_log /dev/null;')
        atomic(SITE,site.encode(),0o644)
        for command in (['nginx','-t'],['php-fpm8.3','-t']):
            if subprocess.run(command,capture_output=True).returncode: raise RuntimeError('Service configuration validation failed')
        import base64
        script=APP_SETUP.replace('__INPUT__',base64.b64encode(json.dumps(data).encode()).decode())
        result=subprocess.run(['runuser','-u','leadbridge','--','php'],input=script.encode(),capture_output=True,env={**os.environ,**values})
        if result.returncode: raise RuntimeError('Application configuration was not committed')
        subprocess.run(['systemctl','daemon-reload'],check=True,capture_output=True)
        subprocess.run(['systemctl','reload','nginx'],check=True,capture_output=True)
        subprocess.run(['systemctl','reload','php8.3-fpm'],check=True,capture_output=True)
        # The normal release receiver restarts the worker after deployment.
        print(result.stdout.decode())
        print('Protected server configuration installed; use the normal release receiver')
    except Exception:
        for path,content in originals.items():
            if content is None:
                secure_file(path)
                if path.exists(): path.unlink()
            else: atomic(path,content,0o600 if path in (POOL,ENV,DROP) else 0o644)
        subprocess.run(['systemctl','daemon-reload'],capture_output=True)
        raise
    finally:
        for fd in reversed(locks):fcntl.flock(fd,fcntl.LOCK_UN);os.close(fd)

if __name__=='__main__':
    try: main()
    except Exception:
        print('OpsVault server setup failed; secrets and payloads are not logged',file=sys.stderr);sys.exit(1)
