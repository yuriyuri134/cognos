"""Install a candidate Cognos app into a *quarantined restore*, never the live instance."""
from __future__ import annotations

import json
import re
from pathlib import Path
from xml.etree import ElementTree


def _version(value: str) -> tuple[int, ...]:
    if not re.fullmatch(r"\d+(?:\.\d+){1,3}", value):
        raise ValueError(f"Unsupported Cognos version: {value!r}")
    return tuple(int(part) for part in value.split("."))


def validate_candidate(directory: Path, production_app: Path, source_version: str) -> tuple[Path, str]:
    """Fail before Docker mutation for invalid/older/live sources."""
    source = directory.expanduser().resolve(strict=True)
    live = production_app.resolve(strict=True)
    if not source.is_dir() or source == live or live in source.parents or source in live.parents:
        raise ValueError("Candidate must be a separate app directory, not the live Cognos checkout")
    info = source / "appinfo" / "info.xml"
    if not info.is_file() or info.is_symlink():
        raise ValueError("Candidate appinfo/info.xml is missing or linked")
    root = ElementTree.parse(info).getroot()
    if root.findtext("id") != "cognos":
        raise ValueError("Candidate is not the Cognos Nextcloud app")
    current = root.findtext("version", "")
    if _version(current) < _version(source_version):
        raise ValueError(f"Candidate {current} is older than source backup {source_version}; no downgrade")
    return source, current


def install_candidate(candidate: Path, version: str, state: dict, image: str, docker, inspect, occ) -> dict:
    """Replace only the disposable restore volume, then upgrade while isolated.

    Failure leaves the candidate offline; it never exposes an unverified instance.
    """
    prefix = state.get("prefix", "")
    if (not re.fullmatch(r"cognos-restore-[a-f0-9]{10}", prefix)
            or state.get("app") != prefix + "-app"
            or state.get("network") != prefix + "-net"
            or state.get("html_volume") != prefix + "-html"
            or not state.get("no_cron") or not state.get("no_host_bridge")):
        raise RuntimeError("Not a dedicated isolated Cognos restore")
    app = state["app"]
    details = inspect(app)
    token = prefix.removeprefix("cognos-restore-")
    if (details["Config"]["Labels"].get("cognos.restore") != token
            or set(details["NetworkSettings"]["Networks"]) != {state["network"]}
            or occ(app, "config:system:get", "cognos_restore_quarantine").strip().lower() not in ("1", "true")):
        raise RuntimeError("Candidate restore must already be quarantined and on the internal network")
    docker("stop", app)
    script = ("set -eu; test -f /candidate/appinfo/info.xml; "
              "rm -rf /target/custom_apps/cognos; "
              "mkdir -p /target/custom_apps/cognos; "
              "cp -a /candidate/. /target/custom_apps/cognos/; "
              "chown -R 33:33 /target/custom_apps/cognos")
    docker("run", "--rm", "--network", "none",
           "--mount", "type=volume,source=" + state["html_volume"] + ",target=/target",
           "--mount", "type=bind,source=" + str(candidate) + ",target=/candidate,readonly",
           "--entrypoint", "sh", image, "-ec", script)
    docker("start", app)
    # Lint the candidate, including files outside the original bootstrap list.
    docker("exec", app, "sh", "-ec",
           "find /var/www/html/custom_apps/cognos -type f -name '*.php' -print | "
           "while IFS= read -r f; do php -l \"$f\" >/dev/null || exit 1; done")
    occ(app, "upgrade")
    status = json.loads(occ(app, "status", "--output=json"))
    if not status.get("installed") or status.get("needsDbUpgrade"):
        raise RuntimeError("Candidate upgrade did not reach an installed, current schema")
    if occ(app, "config:system:get", "cognos_restore_quarantine").strip().lower() not in ("1", "true"):
        raise RuntimeError("Quarantine disappeared during candidate upgrade")
    return {"version": version, "candidate_source": str(candidate), "schema_upgraded": True}
