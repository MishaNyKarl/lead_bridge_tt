"""Copy audited Composer source dependencies; no install/network step in build/deploy."""
import hashlib, json, pathlib, re, shutil

root = pathlib.Path(__file__).resolve().parents[1]
installed = root / '.private/opsvault/php'
target = root / 'central/oidc-vendor'
mapping = {}
text = (installed / 'vendor/composer/autoload_classmap.php').read_text()
for name, path in re.findall(r"'([^']+)' => \$vendorDir \. '([^']+)'", text):
    name = name.replace('\\\\', '\\')
    if name.startswith('Composer\\'):
        continue
    path = path.lstrip('/')
    mapping[name] = path
for path in sorted(set(mapping.values()) | {'phpseclib/phpseclib/phpseclib/bootstrap.php'}):
    dest = target / path
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(installed / 'vendor' / path, dest)
for package in ['jumbojett/openid-connect-php', 'phpseclib/phpseclib', 'paragonie/constant_time_encoding', 'paragonie/random_compat']:
    for source in (installed / 'vendor' / package).glob('*'):
        if source.name.lower().startswith(('license', 'copying')):
            dest = target / package / source.name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, dest)
for name in ['composer.json', 'composer.lock']:
    shutil.copyfile(installed / name, root / 'central' / ('oidc-' + name))
(root / 'central/oidc-classmap.json').write_text(json.dumps(mapping, indent=2, sort_keys=True))
manifest = {p.relative_to(target).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(target.rglob('*')) if p.is_file()}
(root / 'central/oidc-vendor-sha256.json').write_text(json.dumps(manifest, indent=2, sort_keys=True))
print(f'Vendored {len(manifest)} source/license files')
