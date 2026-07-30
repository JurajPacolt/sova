#!/usr/bin/env bash

set -euo pipefail

remote_ip_config=/etc/apache2/conf-enabled/sova-remoteip.conf
trusted_proxy_cidrs="${SOVA_TRUSTED_PROXY_CIDRS:-}"

if [[ -n "$trusted_proxy_cidrs" ]]; then
  if [[ ! "$trusted_proxy_cidrs" =~ ^[0-9A-Fa-f:.,/[:space:]]+$ ]]; then
    echo "SOVA_TRUSTED_PROXY_CIDRS contains an invalid character." >&2
    exit 78
  fi

  {
    printf '%s\n' 'RemoteIPHeader X-Forwarded-For'
    printf '%s\n' 'RemoteIPProxiesHeader X-Forwarded-By'

    IFS=',' read -ra cidrs <<< "$trusted_proxy_cidrs"
    for cidr in "${cidrs[@]}"; do
      cidr="${cidr//[[:space:]]/}"
      if [[ -n "$cidr" ]]; then
        printf 'RemoteIPTrustedProxy %s\n' "$cidr"
      fi
    done
  } > "$remote_ip_config"
else
  rm -f -- "$remote_ip_config"
fi

exec docker-php-entrypoint "$@"

