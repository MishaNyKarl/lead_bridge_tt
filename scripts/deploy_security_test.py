"""Regression: unsafe control paths must fail before any deployment operations."""
import os
import pathlib
import subprocess
import tempfile

source = pathlib.Path('deploy/receive-release.sh').read_text()
with tempfile.TemporaryDirectory() as tmp:
    root = pathlib.Path(tmp)
    control = root / 'control'
    control.mkdir(mode=0o755)
    sentinel = root / 'sentinel'
    sentinel.write_text('must remain unchanged')
    script = root / 'deploy.sh'
    # Isolate the path/ownership checks for an unprivileged test runner.
    source = source.replace('control=/var/lib/lead-bridge-control', f'control={control}')
    source = source.replace('/var /var/lib "$control" /var/www /var/www/lead-bridge', '"$control"')
    for name in ['dir', 'path']:
        source = source.replace(f'$(stat -c %u "${name}") == 0', f'$(stat -c %u "${name}") == {os.getuid()}')
    # A regression must not reach any real deployment commands.
    source = source.replace('stage=$(mktemp', 'exit 99\nstage=$(mktemp')
    script.write_text(source)
    for name in ['deploy.lock', 'dispatch.lock']:
        (control / name).touch(mode=0o644)
    def check_failure(label):
        result = subprocess.run(['bash', str(script)], capture_output=True)
        assert result.returncode not in (0, 99), (label, result.returncode)
        assert sentinel.read_text() == 'must remain unchanged'
        print('PASS', label)
    for name in ['deploy.lock', 'dispatch.lock']:
        path = control / name
        path.unlink()
        path.symlink_to(sentinel)
        check_failure(name + ' symlink refused')
        path.unlink()
        os.link(sentinel, path)
        check_failure(name + ' hardlink refused')
        path.unlink()
        path.touch(mode=0o644)
        path.chmod(0o666)
        check_failure(name + ' writable lock refused')
        path.chmod(0o644)
    control.chmod(0o777)
    check_failure('writable control directory refused')
    control.chmod(0o755)
    assert subprocess.run(['bash', str(script)], capture_output=True).returncode == 99
    print('PASS valid protected control paths accepted')
    # noclobber must refuse an attacker-supplied pause link, even a dangling one.
    for target in [sentinel, root / 'missing']:
        pause = control / 'deploy.pause'
        pause.symlink_to(target)
        result = subprocess.run(['bash', '-c', 'set -o noclobber; : > "$1"', 'test', str(pause)], capture_output=True)
        assert result.returncode != 0
        assert sentinel.read_text() == 'must remain unchanged'
        assert not (root / 'missing').exists()
        pause.unlink()
    print('PASS pause symlinks refused without touching targets')
