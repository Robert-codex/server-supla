#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
dist_dir="${repo_root}/cloud-local/supla-cloud/web/dist"
web_dir="${repo_root}/cloud-local/supla-cloud/web"

edgefix_version="31"
edgefix_cache_tag="edgefix-v${edgefix_version}"
cloud_version_label="regnal-codex 01.04.26"

entry_base="${dist_dir}/index-DfO4cloU-limitsfix.js"
entry_source="${dist_dir}/index-DfO4cloU-limitsfix-upstream.js"
entry_alias="${dist_dir}/index-DfO4cloU-limitsfix-cloud-local.js"
entry_local="${dist_dir}/index-DfO4cloU-limitsfix-edgefix.js"
entry_legacy="${dist_dir}/index-DfO4cloU.js"
index_html="${web_dir}/index.html"

login_page_base="${dist_dir}/login-page-CfxWV-Jq.js"
login_page_source="${dist_dir}/login-page-CfxWV-Jq-upstream.js"
login_page_local="${dist_dir}/login-page-CfxWV-Jq-edgefix.js"

login_form_base="${dist_dir}/login-form-C8Lf4buW.js"
login_form_source="${dist_dir}/login-form-C8Lf4buW-upstream.js"
login_form_local="${dist_dir}/login-form-C8Lf4buW-edgefix.js"

resend_base="${dist_dir}/resend-account-activation-link-DP8hDyU_.js"
resend_source="${dist_dir}/resend-account-activation-link-DP8hDyU_-upstream.js"
resend_local="${dist_dir}/resend-account-activation-link-DP8hDyU_-edgefix.js"

two_factor_base="${dist_dir}/two-factor-authentication-DQochOGS.js"
two_factor_source="${dist_dir}/two-factor-authentication-DQochOGS-upstream.js"
two_factor_local="${dist_dir}/two-factor-authentication-DQochOGS-edgefix.js"

if [[ ! -f "${two_factor_source}" ]]; then
  if grep -q 'two-factor-authentication-DQochOGS-edgefix.js' "${two_factor_base}"; then
    echo "ERROR: Missing ${two_factor_source} but ${two_factor_base} looks patched already." >&2
    exit 1
  fi
  cp "${two_factor_base}" "${two_factor_source}"
fi

cp "${resend_source}" "${resend_local}"
perl -0pi -e \
  's#\./index-DfO4cloU-limitsfix\.js#./index-DfO4cloU-limitsfix-edgefix.js?v='"${edgefix_cache_tag}"'#g' \
  "${resend_local}"

cp "${two_factor_source}" "${two_factor_local}"
perl -0pi -e \
  's#\./index-DfO4cloU-limitsfix\.js#./index-DfO4cloU-limitsfix-edgefix.js?v='"${edgefix_cache_tag}"'#g; s#this\.setup=a,this\.recoveryCodes=\[\]#this.setup={...a,qrCodeUrl:a?.otpauthUri&&window.SuplaLocalQr?window.SuplaLocalQr.dataUrl(a.otpauthUri,256):""},this.recoveryCodes=[]#g; s#Two-factor authentication has been enabled\.#Uwierzytelnianie dwuskladnikowe zostalo wlaczone.#g; s#Recovery codes have been regenerated\.#Kody zapasowe zostaly wygenerowane ponownie.#g; s#Two-factor authentication has been disabled\.#Uwierzytelnianie dwuskladnikowe zostalo wylaczone.#g; s#Save these codes now\. Each code can be used only once\.#Zapisz te kody teraz. Kazdy kod mozna uzyc tylko raz.#g; s# Two-factor authentication is enabled for your web account\. # Uwierzytelnianie dwuskladnikowe jest wlaczone dla Twojego konta webowego. #g; s# Protect your SUPLA Cloud web account with a time-based authenticator code\. This does not change the login flow used by mobile client apps\. # Zabezpiecz konto SUPLA Cloud kodem czasowym z aplikacji uwierzytelniajacej. Nie zmienia to sposobu logowania w aplikacjach mobilnych. #g; s# Recovery codes left: # Pozostalo kodow zapasowych: #g; s#Step 1: Add the secret to your authenticator app#Krok 1: Dodaj sekret do aplikacji uwierzytelniajacej#g; s#Use manual entry in your authenticator app and copy this secret:#Uzyj recznego wpisania w aplikacji uwierzytelniajacej i skopiuj ten sekret:#g; s#readonly:""\},null,8,E\),e\[19\]\|\|\(e\[19\]=o\("p",\{class:"mt-3"\},"If your app supports importing an otpauth URI, use this value:",-1\)\)#readonly:""},null,8,E),t.setup.qrCodeUrl?(l(),n("div",{class:"text-center mt-3 mb-3"},[o("p",{class:"mb-2"},"Zeskanuj lokalny kod QR w aplikacji Google Authenticator lub innej kompatybilnej."),o("img",{src:t.setup.qrCodeUrl,alt:"Kod QR 2FA",class:"img-responsive center-block",style:{maxWidth:"256px",margin:"0 auto"}},null,8,["src"])])):d("",!0),e[19]||(e[19]=o("p",{class:"mt-3"},"Jesli Twoja aplikacja obsluguje import URI otpauth, uzyj tej wartosci:",-1))#g; s#Step 2: Confirm setup#Krok 2: Potwierdz konfiguracje#g; s#Authenticator code or recovery code#Kod z aplikacji lub kod zapasowy#g; s#Authenticator code#Kod z aplikacji uwierzytelniajacej#g; s#Regenerate recovery codes#Wygeneruj ponownie kody zapasowe#g; s#Generate new recovery codes#Wygeneruj nowe kody zapasowe#g; s#Disable two-factor authentication#Wylacz uwierzytelnianie dwuskladnikowe#g; s#Disable 2FA#Wylacz 2FA#g; s#Current password#Aktualne haslo#g; s#Enable 2FA#Wlacz 2FA#g; s#Start setup#Rozpocznij konfiguracje#g; s#Recovery codes#Kody zapasowe#g; s#Two-factor authentication#Uwierzytelnianie dwuskladnikowe#g; s#Cancel#Anuluj#g' \
  "${two_factor_local}"
python3 - "${two_factor_base}" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
data = path.read_text(encoding="utf-8")
data = data.replace(
    'import "./two-factor-authentication-DQochOGS-edgefix.js";',
    'import "./two-factor-authentication-DQochOGS-edgefix.js?v=edgefix-v31";',
).replace(
    'export { default } from "./two-factor-authentication-DQochOGS-edgefix.js";',
    'export { default } from "./two-factor-authentication-DQochOGS-edgefix.js?v=edgefix-v31";',
)
path.write_text(data, encoding="utf-8")
PY

cp "${login_form_source}" "${login_form_local}"
perl -0pi -e \
  's#\./index-DfO4cloU-limitsfix\.js#./index-DfO4cloU-limitsfix-edgefix.js?v='"${edgefix_cache_tag}"'#g; s#\./resend-account-activation-link-DP8hDyU_\.js#./resend-account-activation-link-DP8hDyU_-edgefix.js#g' \
  "${login_form_local}"
perl -0pi -e \
  's#type:"email",required:"",autocorrect#type:"email",required:"",autocomplete:"username",autocorrect#g; s#type:"password",placeholder#type:"password",autocomplete:"current-password",placeholder#g' \
  "${login_form_local}"
perl -0pi -e \
  's#mounted\(\)\{this\.username=this\.intitialUsername\|\|""\}#mounted(){this.username=this.intitialUsername||"",this.__THIS_NEXT_TICK__(()=>{const e=this.__THIS_EL__?.querySelector('\''input[name="_username"]'\''),t=this.__THIS_EL__?.querySelector('\''input[name="_password"]'\''),o=this.__THIS_EL__?.closest('\''form'\'');e&&e.setAttribute('\''autocomplete'\'','\''username'\''),t&&t.setAttribute('\''autocomplete'\'','\''current-password'\''),o&&o.setAttribute('\''autocomplete'\'','\''on'\'')})}#g' \
  "${login_form_local}"
perl -0pi -e \
  's#__THIS_NEXT_TICK__#\$nextTick#g; s#__THIS_EL__#\$el#g' \
  "${login_form_local}"

cp "${login_page_source}" "${login_page_local}"
perl -0pi -e \
  's#\./login-form-C8Lf4buW\.js#./login-form-C8Lf4buW-edgefix.js#g; s#\./index-DfO4cloU-limitsfix\.js#./index-DfO4cloU-limitsfix-edgefix.js?v='"${edgefix_cache_tag}"'#g; s#\./resend-account-activation-link-DP8hDyU_\.js#./resend-account-activation-link-DP8hDyU_-edgefix.js#g' \
  "${login_page_local}"
perl -0pi -e \
  's#type:s\.useRecoveryCode\?"text":"tel",placeholder#type:s.useRecoveryCode?"text":"tel",autocomplete:s.useRecoveryCode?"off":"one-time-code",placeholder#g' \
  "${login_page_local}"

cp "${entry_source}" "${entry_local}"
perl -0pi -e \
  's#dist/login-page-CfxWV-Jq\.js#dist/login-page-CfxWV-Jq-edgefix.js#g; s#\./login-page-CfxWV-Jq\.js#./login-page-CfxWV-Jq-edgefix.js#g; s#dist/login-form-C8Lf4buW\.js#dist/login-form-C8Lf4buW-edgefix.js#g; s#dist/resend-account-activation-link-DP8hDyU_\.js#dist/resend-account-activation-link-DP8hDyU_-edgefix.js#g' \
  "${entry_local}"

python3 - "${entry_local}" <<'PY'
import sys

path = sys.argv[1]
with open(path, "r", encoding="utf-8") as handle:
    data = handle.read()

def patch_locale_bootstrap(src: str) -> str:
    start = src.find("V5=()=>{")
    if start == -1:
        raise RuntimeError("Missing V5 locale bootstrap function")
    end = src.find(",Mm=", start)
    if end == -1:
        raise RuntimeError("Missing Mm function boundary after V5")
    segment = src[start:end]
    segment2 = segment.replace(
        't=(window.navigator.userLanguage||window.navigator.language||"en").substring(0,2)',
        't=(()=>{try{return localStorage.getItem("supla-web-locale")||""}catch{return""}})()||(window.navigator.userLanguage||window.navigator.language||"pl").substring(0,2)',
    ).replace(
        'FS.map(s=>s.value).includes(t)||(t="en")',
        'FS.map(s=>s.value).includes(t)||(t="pl")',
    )
    if segment2 == segment:
        raise RuntimeError("Failed to patch V5 locale bootstrap segment")
    return src[:start] + segment2 + src[end:]

data2 = patch_locale_bootstrap(data)

# Persist language selection when user changes it in UI.
data2 = data2.replace(
    'ft.defaultLocale=e;const t=br();',
    'ft.defaultLocale=e;try{localStorage.setItem("supla-web-locale",e)}catch{}const t=br();',
)

# Ensure locale messages are loaded even if the app starts with the same locale value.
mm_from = 'Mm=e=>{Ti.global.locale.value!==e&&Promise.resolve($S.includes(e)?!0:VS(e)).then(()=>{Ti.global.locale.value=e,'
mm_to = 'Mm=e=>{(Ti.global.locale.value!==e||!$S.includes(e))&&Promise.resolve($S.includes(e)?!0:VS(e)).then(()=>{Ti.global.locale.value=e,'
if mm_from not in data2:
    raise RuntimeError("Missing expected Mm locale switch function")
data2 = data2.replace(mm_from, mm_to)

# Make Mm return a Promise so V5() can be awaited before mount.
mm_start_from = 'Mm=e=>{(Ti.global.locale.value!==e||!$S.includes(e))&&Promise.resolve($S.includes(e)?!0:VS(e)).then(()=>{Ti.global.locale.value=e,'
mm_start_to = 'Mm=e=>{return (Ti.global.locale.value!==e||!$S.includes(e))?Promise.resolve($S.includes(e)?!0:VS(e)).then(()=>{Ti.global.locale.value=e,'
if mm_start_from not in data2:
    raise RuntimeError("Missing expected patched Mm start segment")
data2 = data2.replace(mm_start_from, mm_start_to)

mm_end_from = 't.username&&t.userData?.locale!==e&&t.updateUserLocale(e)})},g$='
mm_end_to = 't.username&&t.userData?.locale!==e&&t.updateUserLocale(e)}):Promise.resolve(!0)},g$='
if mm_end_from not in data2:
    raise RuntimeError("Missing expected Mm end segment for Promise return")
data2 = data2.replace(mm_end_from, mm_end_to)

# Prevent infinite recursion on translation chunk load failure.
data2 = data2.replace(
    '.catch(()=>Mm("en"))',
    '.catch(()=>{try{Ti.global.locale.value="en"}catch{};try{ft.defaultLocale="en"}catch{};})',
)

if data2 == data:
    print(f"ERROR: Failed to patch locale bootstrap in {path}", file=sys.stderr)
    sys.exit(1)

with open(path, "w", encoding="utf-8") as handle:
    handle.write(data2)
PY
python3 - "${entry_local}" <<'PY'
import sys

path = sys.argv[1]
with open(path, "r", encoding="utf-8") as handle:
    data = handle.read()

# Keep upstream bootstrap behavior (no global mount guards),
# but remove forced initial English language and make unauthenticated login resilient.
from_chain = 'Y9.fetchConfig().then(()=>K9.fetchUser()).then(()=>VS("en")).then(()=>V5()).then(()=>$s.use(df)).then(()=>$s.mount("#vue-container"));'
to_chain = 'Y9.fetchConfig().then(()=>K9.fetchUser().catch(()=>null)).then(()=>V5()).then(()=>$s.use(df)).then(()=>$s.mount("#vue-container"));'

if from_chain not in data:
    print(f"ERROR: Missing expected upstream bootstrap chain in {path}", file=sys.stderr)
    sys.exit(1)

data = data.replace(from_chain, to_chain)

with open(path, "w", encoding="utf-8") as handle:
    handle.write(data)
PY

perl -0pi -e \
  's#UNKNOWN_VERSION#'"${cloud_version_label}"'#g' \
  "${entry_local}"

perl -0pi -e \
  's#dist/([A-Za-z0-9._-]+\.js)(?:\\?v=[A-Za-z0-9._-]+)?#dist/$1?v='"${edgefix_cache_tag}"'#g' \
  "${entry_local}"

for f in "${dist_dir}"/*.js; do
  perl -0pi -e \
    's#\./index-DfO4cloU-limitsfix-edgefix\.js(?:\?v=[A-Za-z0-9._-]+)?#./index-DfO4cloU-limitsfix-edgefix.js?v='"${edgefix_cache_tag}"'#g; s#dist/index-DfO4cloU-limitsfix-edgefix\.js(?:\?v=[A-Za-z0-9._-]+)?#dist/index-DfO4cloU-limitsfix-edgefix.js?v='"${edgefix_cache_tag}"'#g' \
    "${f}"
done

python3 - "${index_html}" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
data = path.read_text(encoding="utf-8")
if '/dist/qrcode-local.js?v=edgefix-v31' not in data:
    data = data.replace(
    '<script type="module" crossorigin src="/dist/index-DfO4cloU-limitsfix-edgefix.js?v=edgefix-v31"></script>',
        '<script src="/dist/qrcode-local.js?v=edgefix-v31"></script>\n  <script type="module" crossorigin src="/dist/index-DfO4cloU-limitsfix-edgefix.js?v=edgefix-v31"></script>',
    )
path.write_text(data, encoding="utf-8")
PY

cat > "${index_html}" <<EOF
<!DOCTYPE html>
<html lang="">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="theme-color" content="#00732C">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" href="/favicon.ico">
    <title>SUPLA Cloud</title>
    <style>
        @media (min-height: 500px) and (min-width: 768px) {
            html, body {height: 100%;}
        }

        #page-preloader {display: flex;flex-direction: column;justify-content: center;height: 100%;text-align: center;font-family: 'Quicksand', sans-serif;}

        #page-preloader img {width: 90%;max-width: 200px;margin: 0 auto;}

        #page-preloader .if-too-long {opacity: 0;transition: opacity 1s linear;padding-top: 30px;}

        #page-preloader.too-long .if-too-long {opacity: 1;}

        #page-preloader noscript {color: #f60;}

        .invisible { visibility: hidden; }
        .hidden { display: none; }
    </style>
  <script>
    // Minimal in-page diagnostics: if JS fails early, show something actionable instead of a white screen.
    (function () {
        var preloader = document.getElementById('page-preloader');
        var vue = document.getElementById('vue-container');
        var tooLong = function (reason) {
            try {
                if (vue) { vue.classList.add('hidden'); }
                if (preloader) {
                    preloader.classList.remove('hidden');
                    preloader.classList.add('too-long');
                    var box = document.createElement('pre');
                    box.style.whiteSpace = 'pre-wrap';
                    box.style.maxWidth = '900px';
                    box.style.margin = '20px auto 0';
                    box.style.padding = '12px';
                    box.style.textAlign = 'left';
                    box.style.background = '#fff';
                    box.style.border = '1px solid #ddd';
                    box.style.borderRadius = '8px';
                    box.textContent = 'Frontend init failed (' + ${edgefix_cache_tag@Q} + ').\\n\\n' + String(reason || '');
                    preloader.appendChild(box);
                }
            } catch (e) {}
        };

        window.addEventListener('error', function (e) {
            tooLong(e && (e.error && (e.error.stack || e.error.message) || e.message) || e);
        });
        window.addEventListener('unhandledrejection', function (e) {
            tooLong(e && e.reason || e);
        });

        setTimeout(function () {
            try {
                // If the app did not render anything, keep the preloader visible with a hint.
                if (vue && (!vue.childNodes || vue.childNodes.length === 0)) {
                    tooLong('Vue container is empty after 8 seconds.');
                }
            } catch (e) {}
        }, 8000);
    })();
  </script>
  <script type="module" crossorigin src="/dist/index-DfO4cloU-limitsfix-edgefix.js?v=${edgefix_cache_tag}"></script>
  <link rel="stylesheet" crossorigin href="/dist/index-DwGX3pD9.css">
</head>
<body>
<span class="hidden">{% block beforeVue %}{% endblock %}</span>
<div id="vue-container" class="hidden"></div>
<div id="page-preloader">
    <img src="/assets/img/preloaders/loader_1c_200.gif">
    <noscript>
        <h3>SUPLA-Cloud will not work without Javascript</h3>
    </noscript>
    <p class="if-too-long text-muted">
        if it takes too long, try refreshing the page
    </p>
</div>
<script>
    setTimeout(function () {
        var preloader = document.getElementById('page-preloader');
        if (preloader) {
            preloader.className = 'too-long';
        }
    }, 8000);
</script>
</body>
</html>
EOF

cat > "${entry_base}" <<EOF
import "./index-DfO4cloU-limitsfix-edgefix.js?v=${edgefix_cache_tag}";
export * from "./index-DfO4cloU-limitsfix-edgefix.js?v=${edgefix_cache_tag}";
EOF

cat > "${entry_legacy}" <<EOF
import "./index-DfO4cloU-limitsfix-edgefix.js?v=${edgefix_cache_tag}";
export * from "./index-DfO4cloU-limitsfix-edgefix.js?v=${edgefix_cache_tag}";
EOF

cat > "${entry_alias}" <<EOF
import "./index-DfO4cloU-limitsfix-edgefix.js?v=${edgefix_cache_tag}";
export * from "./index-DfO4cloU-limitsfix-edgefix.js?v=${edgefix_cache_tag}";
EOF

cat > "${login_page_base}" <<'EOF'
import "./login-page-CfxWV-Jq-edgefix.js";
export { default } from "./login-page-CfxWV-Jq-edgefix.js";
EOF

cat > "${login_form_base}" <<'EOF'
export { L } from "./login-form-C8Lf4buW-edgefix.js";
EOF

cat > "${resend_base}" <<'EOF'
export { R } from "./resend-account-activation-link-DP8hDyU_-edgefix.js";
EOF

cat > "${two_factor_base}" <<'EOF'
import "./two-factor-authentication-DQochOGS-edgefix.js";
export { default } from "./two-factor-authentication-DQochOGS-edgefix.js";
EOF

rm -f \
  "${dist_dir}/index-DfO4cloU-limitsfix-loginfix.js" \
  "${dist_dir}/login-page-CfxWV-Jq-loginfix.js" \
  "${dist_dir}/login-form-C8Lf4buW-loginfix.js" \
  "${dist_dir}/resend-account-activation-link-DP8hDyU_-loginfix.js" \
  "${dist_dir}/two-factor-authentication-DQochOGS-loginfix.js" \
  "${dist_dir}/index-DfO4cloU-limitsfix-refsfix.js" \
  "${dist_dir}/index-DfO4cloU-limitsfix-refsfix2.js" \
  "${dist_dir}/index-DfO4cloU-limitsfix-refsfix3.js" \
  "${dist_dir}/login-page-CfxWV-Jq-refsfix.js" \
  "${dist_dir}/login-form-C8Lf4buW-refsfix.js" \
  "${dist_dir}/resend-account-activation-link-DP8hDyU_-refsfix.js" \
  "${dist_dir}/login-page-CfxWV-Jq-cloud-local.js" \
  "${dist_dir}/login-form-C8Lf4buW-cloud-local.js" \
  "${dist_dir}/resend-account-activation-link-DP8hDyU_-cloud-local.js"

rm -f "${dist_dir}"/index-DfO4cloU-limitsfix-edgefix-v*.js
