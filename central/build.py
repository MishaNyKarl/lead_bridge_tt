from pathlib import Path
import base64, gzip, hashlib, json, io, zipfile
root=Path(__file__).resolve().parent
source=(root/'action.php').read_text(encoding='utf-8')
accounts=(root/'accounts.php').read_text(encoding='utf-8').removeprefix('<?php')
source=source.replace("require_once __DIR__.'/accounts.php'; // __ACCOUNTS_MODULE__",accounts)
manifest=json.loads((root/'oidc-vendor-sha256.json').read_text())
vendor={}
for path,digest in manifest.items():
    data=(root/'oidc-vendor'/path).read_bytes()
    if hashlib.sha256(data).hexdigest()!=digest:
        raise RuntimeError('OIDC dependency checksum mismatch: '+path)
    vendor[path]=data.decode('utf-8')
archive=io.BytesIO()
with zipfile.ZipFile(archive,'w',compression=zipfile.ZIP_DEFLATED) as z:
    for path,content in sorted(vendor.items()):
        info=zipfile.ZipInfo(path,(2026,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED
        z.writestr(info,content.encode('utf-8'))
bundle={'map':json.loads((root/'oidc-classmap.json').read_text()),'zip':base64.b64encode(archive.getvalue()).decode('ascii'),'sha256':hashlib.sha256(archive.getvalue()).hexdigest()}
oidc=(root/'opsvault.php').read_text(encoding='utf-8').removeprefix('<?php')
oidc=oidc.replace('__OIDC_LIBRARY_BASE64__',base64.b64encode(gzip.compress(json.dumps(bundle,ensure_ascii=False).encode(),mtime=0)).decode('ascii'))
source=source.replace("require_once __DIR__.'/opsvault.php'; // __OPSVAULT_MODULE__",oidc)
source=source.replace("require_once __DIR__.'/partner_poll.php'; // __POLL_MODULE__",(root/'partner_poll.php').read_text(encoding='utf-8').removeprefix('<?php'))
source=source.replace("require_once __DIR__.'/overview.php'; // __OVERVIEW_MODULE__",(root/'overview.php').read_text(encoding='utf-8').removeprefix('<?php'))
ui=(root/'ui.php').read_text(encoding='utf-8').removeprefix('<?php')
ui=ui.replace('__FAVICON_BASE64__',base64.b64encode((root/'assets'/'favicon.png').read_bytes()).decode('ascii'))
for key,name in [('SETTINGS','settings'),('HELP','circle-question-mark'),('COPY','copy')]:
    ui=ui.replace('__ICON_'+key+'__',base64.b64encode((root/'icons'/(name+'.svg')).read_bytes()).decode('ascii'))
source=source.replace("require_once __DIR__.'/ui.php'; // __UI_MODULE__",ui)
source=source.replace('__COUNTRY_IP_BASE64__',base64.b64encode(gzip.compress((root/'ip-country.json').read_bytes(),mtime=0)).decode('ascii'))
script=base64.b64encode((root/'LeadBridge.gs').read_bytes()).decode('ascii')
(root/'dist').mkdir(exist_ok=True)
(root/'dist'/'action.php').write_text(source.replace('__SCRIPT_BASE64__',script),encoding='utf-8')
print('Built central/dist/action.php')
