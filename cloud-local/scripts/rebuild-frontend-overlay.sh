#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
dist_dir="${repo_root}/cloud-local/supla-cloud/web/dist"

entry_base="${dist_dir}/index-DfO4cloU-limitsfix.js"
entry_source="${dist_dir}/index-DfO4cloU-limitsfix-upstream.js"
entry_alias="${dist_dir}/index-DfO4cloU-limitsfix-cloud-local.js"
entry_local="${dist_dir}/index-DfO4cloU-limitsfix-edgefix.js"
entry_legacy="${dist_dir}/index-DfO4cloU.js"

login_page_base="${dist_dir}/login-page-CfxWV-Jq.js"
login_page_source="${dist_dir}/login-page-CfxWV-Jq-upstream.js"
login_page_local="${dist_dir}/login-page-CfxWV-Jq-edgefix.js"

login_form_base="${dist_dir}/login-form-C8Lf4buW.js"
login_form_source="${dist_dir}/login-form-C8Lf4buW-upstream.js"
login_form_local="${dist_dir}/login-form-C8Lf4buW-edgefix.js"

resend_base="${dist_dir}/resend-account-activation-link-DP8hDyU_.js"
resend_source="${dist_dir}/resend-account-activation-link-DP8hDyU_-upstream.js"
resend_local="${dist_dir}/resend-account-activation-link-DP8hDyU_-edgefix.js"

cp "${resend_source}" "${resend_local}"
perl -0pi -e \
  's#\./index-DfO4cloU-limitsfix\.js#./index-DfO4cloU-limitsfix-edgefix.js#g' \
  "${resend_local}"

cp "${login_form_source}" "${login_form_local}"
perl -0pi -e \
  's#\./index-DfO4cloU-limitsfix\.js#./index-DfO4cloU-limitsfix-edgefix.js#g; s#\./resend-account-activation-link-DP8hDyU_\.js#./resend-account-activation-link-DP8hDyU_-edgefix.js#g' \
  "${login_form_local}"

cp "${login_page_source}" "${login_page_local}"
perl -0pi -e \
  's#\./login-form-C8Lf4buW\.js#./login-form-C8Lf4buW-edgefix.js#g; s#\./index-DfO4cloU-limitsfix\.js#./index-DfO4cloU-limitsfix-edgefix.js#g; s#\./resend-account-activation-link-DP8hDyU_\.js#./resend-account-activation-link-DP8hDyU_-edgefix.js#g' \
  "${login_page_local}"

cp "${entry_source}" "${entry_local}"
perl -0pi -e \
  's#dist/login-page-CfxWV-Jq\.js#dist/login-page-CfxWV-Jq-edgefix.js#g; s#\./login-page-CfxWV-Jq\.js#./login-page-CfxWV-Jq-edgefix.js#g; s#dist/login-form-C8Lf4buW\.js#dist/login-form-C8Lf4buW-edgefix.js#g; s#dist/resend-account-activation-link-DP8hDyU_\.js#dist/resend-account-activation-link-DP8hDyU_-edgefix.js#g' \
  "${entry_local}"
perl -0pi -e \
  's!then\(\(\)=>V5\(\)\)\.then\(\(\)=>\$s\.use\(df\)\)\.then\(\(\)=>\$s\.mount\(\"#vue-container\"\)\);!then(()=>\$s.use(df)).then(()=>\$s.mount(\"#vue-container\"));!g' \
  "${entry_local}"

cat > "${entry_base}" <<'EOF'
import "./index-DfO4cloU-limitsfix-edgefix.js";
export * from "./index-DfO4cloU-limitsfix-edgefix.js";
EOF

cat > "${entry_legacy}" <<'EOF'
import "./index-DfO4cloU-limitsfix-edgefix.js";
export * from "./index-DfO4cloU-limitsfix-edgefix.js";
EOF

cat > "${entry_alias}" <<'EOF'
import "./index-DfO4cloU-limitsfix-edgefix.js";
export * from "./index-DfO4cloU-limitsfix-edgefix.js";
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

rm -f \
  "${dist_dir}/index-DfO4cloU-limitsfix-loginfix.js" \
  "${dist_dir}/login-page-CfxWV-Jq-loginfix.js" \
  "${dist_dir}/login-form-C8Lf4buW-loginfix.js" \
  "${dist_dir}/resend-account-activation-link-DP8hDyU_-loginfix.js" \
  "${dist_dir}/index-DfO4cloU-limitsfix-refsfix.js" \
  "${dist_dir}/index-DfO4cloU-limitsfix-refsfix2.js" \
  "${dist_dir}/index-DfO4cloU-limitsfix-refsfix3.js" \
  "${dist_dir}/login-page-CfxWV-Jq-refsfix.js" \
  "${dist_dir}/login-form-C8Lf4buW-refsfix.js" \
  "${dist_dir}/resend-account-activation-link-DP8hDyU_-refsfix.js" \
  "${dist_dir}/login-page-CfxWV-Jq-cloud-local.js" \
  "${dist_dir}/login-form-C8Lf4buW-cloud-local.js" \
  "${dist_dir}/resend-account-activation-link-DP8hDyU_-cloud-local.js"
