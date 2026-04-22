export SEMGREP_APP_TOKEN=$(op read "op://HomeLab/SEMGREP_APP_TOKEN/credential")

curl -s "https://semgrep.dev/api/v1/deployments/jim_unibrain_org/findings?dedup=true&ref=main&repos=unibrain1%2Felanregistry" \
  --header "Authorization: Bearer $SEMGREP_APP_TOKEN" | jq '.findings[] | {
    id,
    severity,
    rule: .rule_name,
    file: .location.file_path,
    line: .location.line,
    message: .rule_message,
    cwe: .rule.cwe_names,
    url: .line_of_code_url
  }'