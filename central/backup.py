#!/usr/bin/python3
"""Consistent local snapshot. Run as root; encrypted app fields remain encrypted."""
import sqlite3, pathlib, shutil, datetime, os, tarfile
os.umask(0o077)
base=pathlib.Path('/var/backups/lead-bridge')
base.mkdir(mode=0o700,parents=True,exist_ok=True)
stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
work=base/('.'+stamp)
work.mkdir(mode=0o700)
source=sqlite3.connect('file:/var/lib/lead-bridge/bridge.sqlite?mode=ro',uri=True)
target=sqlite3.connect(work/'bridge.sqlite')
source.backup(target)
assert target.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
target.close();source.close()
shutil.copyfile('/var/lib/lead-bridge/master.key',work/'master.key')
shutil.copyfile('/var/www/lead-bridge/action.php',work/'action.php')
archive=base/(stamp+'.tar.gz')
with tarfile.open(archive,'w:gz') as out:
    for f in work.iterdir():out.add(f,arcname=f.name)
for f in work.iterdir():f.unlink()
work.rmdir()
for old in sorted(base.glob('*.tar.gz'))[:-14]:old.unlink()
print('Backup created:',archive.name)
