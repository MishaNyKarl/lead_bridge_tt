"""Exercise privileged setup path validation and data preservation in an isolated fixture."""
import importlib.util, json, os, pathlib, subprocess, tempfile

path=pathlib.Path(__file__).resolve().parents[1]/'deploy/configure-opsvault.py'
spec=importlib.util.spec_from_file_location('ops_setup',path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def reject(fn,label):
    try:fn()
    except (RuntimeError,ValueError,TypeError):print('PASS '+label);return
    raise AssertionError(label)
sample={'client_id':'lead-bridge','client_secret':'x'*40,'encryption_key':'eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg=',
        'recovery_id':'recovery_id','recovery_login':'recovery-login','recovery_password':'recovery_password_123456789',
        'reservations':[{'local_user_id':'admin','local_login':'admin','subject':'11111111-1111-4111-8111-111111111111'}]}
m.validate(sample)
for key,value in [('client_secret','x'*40+'\n'),('client_id','bad"'),('encryption_key','x'*44),('recovery_id','admin'),('reservations',[])]:
    reject(lambda:m.validate({**sample,key:value}),'setup rejects '+key)
reject(lambda:m.validate({**sample,'reservations':sample['reservations']*2}),'ambiguous reservations rejected')
with tempfile.TemporaryDirectory(prefix='bridge-sso-setup-') as folder:
    root=pathlib.Path(folder)
    # secure_directory must reject /tmp's writable parent regardless of fixture ownership.
    reject(lambda:m.secure_directory(root),'root refuses application-writable ancestors')
    dbdir=root/'data';dbdir.mkdir()
    init=r'''<?php $d=new PDO('sqlite:__DB__');$d->exec('CREATE TABLE users(id TEXT PRIMARY KEY,login TEXT UNIQUE,name TEXT,password TEXT,role TEXT,active INTEGER,epoch TEXT);CREATE TABLE entities(id TEXT PRIMARY KEY,owner TEXT);CREATE TABLE settings(k TEXT PRIMARY KEY,v TEXT);CREATE TABLE audit(id INTEGER PRIMARY KEY,created TEXT,event TEXT,subject TEXT,actor TEXT)');$q=$d->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?)');$q->execute(['admin','admin','Original',password_hash('original-password-123',PASSWORD_DEFAULT),'admin',1,'original-epoch']);$d->exec('INSERT INTO entities VALUES("route","admin")');'''.replace('__DB__',str(dbdir/'bridge.sqlite'))
    subprocess.run(['php'],input=init.encode(),check=True,capture_output=True)
    import base64,sqlite3
    db=sqlite3.connect(dbdir/'bridge.sqlite');before=db.execute('SELECT * FROM users').fetchall();owners=db.execute('SELECT * FROM entities').fetchall();db.close()
    source=m.APP_SETUP.replace('/var/lib/lead-bridge/bridge.sqlite',str(dbdir/'bridge.sqlite')).replace('__INPUT__',base64.b64encode(json.dumps(sample).encode()).decode())
    result=subprocess.run(['php'],input=source.encode(),capture_output=True,check=True)
    assert json.loads(result.stdout)['configured'] is True
    db=sqlite3.connect(dbdir/'bridge.sqlite');assert db.execute('SELECT * FROM users WHERE id="admin"').fetchall()==before;assert db.execute('SELECT * FROM entities').fetchall()==owners
    assert db.execute('SELECT v FROM settings WHERE k="sso_jit"').fetchone()[0]=='1';assert db.execute('SELECT count(*) FROM sso_links').fetchone()[0]==0
    assert db.execute('SELECT count(*) FROM sso_reservations').fetchone()[0]==1
    print('PASS bootstrap preserves users/hash/epoch/owners, enables JIT and reserves without linking')
    db.close();subprocess.run(['php'],input=source.encode(),capture_output=True,check=True)
    print('PASS bootstrap is idempotent and never rotates recovery credentials')
    bad={**sample,'reservations':[dict(sample['reservations'][0],local_user_id='foreign')]}
    failed=m.APP_SETUP.replace('/var/lib/lead-bridge/bridge.sqlite',str(dbdir/'bridge.sqlite')).replace('__INPUT__',base64.b64encode(json.dumps(bad).encode()).decode())
    result=subprocess.run(['php'],input=failed.encode(),capture_output=True);assert result.returncode!=0
    assert sample['client_secret'].encode() not in result.stderr and sample['recovery_password'].encode() not in result.stderr
    print('PASS unknown account aborts and credentials are absent from diagnostics')
