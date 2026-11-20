#!/usr/bin/env python3
"""Reproduce native admin additions from the exact original published bundle.

No dependency downloads. The caller supplies the pinned original dist directory.
Changes to upstream symbols or signatures fail before publishing any output.
"""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import tempfile

ROOT = Path(__file__).resolve().parents[1]
UPSTREAM_COMMIT = 'ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7'

def write_text(path, text):
    with path.open('w', encoding='utf-8', newline='\n') as stream:
        stream.write(text)

def replace(text, before, after):
    if text.count(before) != 1:
        raise SystemExit('Upstream admin signature changed: ' + before[:95])
    return text.replace(before, after, 1)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True, type=Path)
    parser.add_argument('--output', type=Path, default=ROOT / 'public/assets/admin')
    parser.add_argument('--user-source', type=Path)
    parser.add_argument('--user-output', type=Path, default=ROOT / 'theme/Xboard/assets/umi-native.js')
    args = parser.parse_args()
    manifest = json.loads((args.source / 'manifest.json').read_text())
    entry = manifest['index.html']['file']
    original = (args.source / entry).read_bytes()
    expected = 'f04f09a95bfdb04fa5132e336bf87c364d4e360cb95774872ff18eb69e9bcd77'
    if hashlib.sha256(original).hexdigest() != expected:
        raise SystemExit('Original admin bundle SHA256 does not match the reviewed version')
    text = original.decode('utf-8')
    text = replace(text, 'protocol_settings:fy(hy()).default({}).nullable(),listen_address:',
                   'backend_profile:cy().optional().default("legacy"),protocol_settings:fy(hy()).default({}).nullable(),listen_address:')
    text = replace(text, 'protocol_settings:{},listen_address:"",machine_id:null,enabled:null}),[]),',
                   'backend_profile:"legacy",protocol_settings:{},listen_address:"",machine_id:null,enabled:null}),[]),')
    text = replace(text, 'Q.jsx(o5t,{serverType:l,value:w,onChange:L})',
                   'Q.jsx(XBNodeProtocol,{form:x,serverType:l,value:w,onChange:L})')
    text = replace(text, 'x.reset({...i,...o,transfer_enable_gb:n,protocol_settings:e})',
                   'x.reset({...i,...o,rate:String(o.rate??"1"),port:String(o.port??""),server_port:String(o.server_port??""),parent_id:String(o.parent_id??"0"),code:o.code??"",transfer_enable_gb:n,protocol_settings:e})')
    text = replace(text, 'className:"flex h-[75vh] min-h-[500px] flex-col",children:',
                   'className:"flex flex-col",style:{height:"min(75vh, calc(90vh - 180px))",minHeight:0},children:')
    text = replace(text, 'if(!l)return void gE.error(e("form.type.select_error"));const t=x.getValues()',
                   'if(!l)return void gE.error(e("form.type.select_error"));if(Object.keys(x.formState.errors).length||!await x.trigger())return;const t=x.getValues()')
    text = replace(text, 'Q.jsx(R3t,{machineId:e.id,open:t,t:i})',
                   'Q.jsx(XBMachineInstall,{machineId:e.id,open:t,t:i})')
    # Keep the local Cap provider inside the upstream security settings form.
    # Configuration remains visible while disabled; do not enable CAPTCHA here.
    text = replace(text, 'o.watch("captcha_enable")&&Q.jsxs(Q.Fragment,{children:[Q.jsx($y,{control:o.control,name:"captcha_type",',
                   'Q.jsxs(Q.Fragment,{children:[Q.jsx($y,{control:o.control,name:"captcha_type",')
    text = replace(text, 'Q.jsx(Lzt,{value:"turnstile",children:e("safe.form.captcha.type.options.turnstile")})',
                   'Q.jsx(Lzt,{value:"turnstile",children:e("safe.form.captcha.type.options.turnstile")}),Q.jsx(Lzt,{value:"cap",children:"Cap（本机自建）"})')
    text = replace(text, '"recaptcha"===o.watch("captcha_type")&&Q.jsxs(Q.Fragment,',
                   '"cap"===o.watch("captcha_type")&&Q.jsx(XBCapSettings,{configuration:a?.data?.safe}),"recaptcha"===o.watch("captcha_type")&&Q.jsxs(Q.Fragment,')
    # Append into the same ES module: the original root, router, auth and
    # design-system bindings are reused by the editable source component.
    text += '\n' + (ROOT / 'resources/js/admin-v2bx.js').read_text(encoding='utf-8').rstrip() + '\n'
    args.output.mkdir(parents=True, exist_ok=True)
    for name in ['assets', 'locales']:
        shutil.copytree(args.source / name, args.output / name, dirs_exist_ok=True)
    target = 'assets/index-v2bx-native.js'
    write_text(args.output / target, text)
    manifest['index.html']['file'] = target
    write_text(args.output / 'manifest.json', json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
    provenance = {'upstream_commit': UPSTREAM_COMMIT, 'upstream_entry': entry,
                  'upstream_sha256': expected, 'source_sha256': hashlib.sha256((ROOT / 'resources/js/admin-v2bx.js').read_text(encoding='utf-8').encode()).hexdigest(),
                  'output_sha256': hashlib.sha256((args.output / target).read_bytes()).hexdigest()}
    write_text(args.output / 'native-build.json', json.dumps(provenance, indent=2) + '\n')
    print(json.dumps(provenance, indent=2))
    if args.user_source:
        source = args.user_source.read_bytes()
        expected_user = '69ff1e68dd44b84f803e631367b6fc9dfd52e79d049067fa52cfa859031f20dc'
        if hashlib.sha256(source).hexdigest() != expected_user:
            raise SystemExit('Original user bundle SHA256 does not match')
        user = source.decode('utf-8')
        target_user = args.user_output
        write_text(target_user, user)
        print('Generated native user subscription controls:', hashlib.sha256(target_user.read_bytes()).hexdigest())

if __name__ == '__main__':
    main()
