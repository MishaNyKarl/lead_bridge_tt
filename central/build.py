from pathlib import Path
import base64, gzip
root=Path(__file__).resolve().parent
source=(root/'action.php').read_text(encoding='utf-8')
accounts=(root/'accounts.php').read_text(encoding='utf-8').removeprefix('<?php')
source=source.replace("require_once __DIR__.'/accounts.php'; // __ACCOUNTS_MODULE__",accounts)
ui=(root/'ui.php').read_text(encoding='utf-8').removeprefix('<?php')
for key,name in [('SETTINGS','settings'),('HELP','circle-question-mark')]:
    ui=ui.replace('__ICON_'+key+'__',base64.b64encode((root/'icons'/(name+'.svg')).read_bytes()).decode('ascii'))
source=source.replace("require_once __DIR__.'/ui.php'; // __UI_MODULE__",ui)
source=source.replace('__COUNTRY_IP_BASE64__',base64.b64encode(gzip.compress((root/'ip-country.json').read_bytes(),mtime=0)).decode('ascii'))
script=base64.b64encode((root/'LeadBridge.gs').read_bytes()).decode('ascii')
(root/'dist').mkdir(exist_ok=True)
(root/'dist'/'action.php').write_text(source.replace('__SCRIPT_BASE64__',script),encoding='utf-8')
print('Built central/dist/action.php')
