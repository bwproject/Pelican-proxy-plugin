#!/usr/bin/env python3
"""BW Proxy node agent. Run as root on the Pelican/Wings node."""
from __future__ import annotations

import hashlib
import ipaddress
import json
import logging
import os
import shlex
import signal
import subprocess
import time
import urllib.error
import urllib.request
from pathlib import Path

CONFIG = Path(os.environ.get("BWPROXY_CONFIG", "/etc/bwproxy/agent.json"))
RUNTIME = Path(os.environ.get("BWPROXY_RUNTIME", "/run/bwproxy"))
STATE = Path(os.environ.get("BWPROXY_STATE", "/var/lib/bwproxy/state.json"))
LOG = logging.getLogger("bwproxy-agent")
PRIVATE_NETS = tuple(ipaddress.ip_network(x) for x in (
    "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16",
    "127.0.0.0/8", "169.254.0.0/16", "fc00::/7", "::1/128",
))


def run(args: list[str], check: bool = True) -> subprocess.CompletedProcess:
    return subprocess.run(args, text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, check=check, timeout=15)


def validate_setting(setting: dict) -> None:
    if not isinstance(setting, dict):
        raise ValueError("server setting must be an object")
    if not isinstance(setting.get("server_uuid"), str) or not setting["server_uuid"] or len(setting["server_uuid"]) > 64:
        raise ValueError("invalid server_uuid")
    if setting.get("mode") not in ("off", "socks5", "amneziawg"):
        raise ValueError("invalid mode")
    if setting["mode"] == "socks5":
        host = setting.get("socks5_host")
        port = setting.get("socks5_port")
        if not isinstance(host, str) or not host or len(host) > 253 or any(c in host for c in "\r\n;{}"):
            raise ValueError("invalid SOCKS5 host")
        if not isinstance(port, int) or not 1 <= port <= 65535:
            raise ValueError("invalid SOCKS5 port")
        for key in ("socks5_username", "socks5_password"):
            value = setting.get(key) or ""
            if not isinstance(value, str) or len(value) > 1024 or any(c in value for c in "\r\n"):
                raise ValueError("invalid SOCKS5 credentials")
    if setting["mode"] == "amneziawg":
        interface = setting.get("awg_interface") or "awg0"
        if not isinstance(interface, str) or not __import__("re").fullmatch(r"[A-Za-z0-9_.-]{1,32}", interface):
            raise ValueError("invalid AmneziaWG interface")


def quote_redsocks(value: str) -> str:
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


def socks_config(setting: dict, port: int) -> str:
    rows = [
        "base { log_debug = off; log_info = on; log = stderr; daemon = off; redirector = iptables; }",
        "redsocks { local_ip = 0.0.0.0; local_port = %d; ip = %s; port = %d; type = socks5;" %
        (port, quote_redsocks(setting["socks5_host"]), setting["socks5_port"]),
    ]
    if setting.get("socks5_username"):
        rows.append(" login = %s;" % quote_redsocks(setting["socks5_username"]))
    if setting.get("socks5_password"):
        rows.append(" password = %s;" % quote_redsocks(setting["socks5_password"]))
    rows.append("}")
    return "\n".join(rows) + "\n"


def server_port(server_uuid: str) -> int:
    # Keep ports in an unprivileged range and deterministic across agent restarts.
    return 20000 + (int(hashlib.sha256(server_uuid.encode()).hexdigest()[:8], 16) % 20000)


def docker_addresses(server_uuid: str) -> tuple[str | None, list[str]]:
    candidates = ("server-" + server_uuid, server_uuid)
    for name in candidates:
        result = run(["docker", "inspect", name], check=False)
        if result.returncode != 0:
            continue
        try:
            obj = json.loads(result.stdout)[0]
            networks = obj.get("NetworkSettings", {}).get("Networks", {})
            ipv4 = None
            ipv6: list[str] = []
            for network in networks.values():
                address = network.get("IPAddress")
                if address:
                    parsed = ipaddress.ip_address(address)
                    if parsed.version == 4:
                        ipv4 = str(parsed)
                for candidate in (network.get("GlobalIPv6Address"), network.get("IPv6Address")):
                    if candidate:
                        parsed = ipaddress.ip_address(candidate)
                        if parsed.version == 6 and str(parsed) not in ipv6:
                            ipv6.append(str(parsed))
            return ipv4, ipv6
        except (ValueError, IndexError, KeyError, TypeError):
            continue
    return None, []


def ensure_chain(table: str, chain: str, parent: str) -> None:
    run(["iptables", "-t", table, "-N", chain], check=False)
    run(["iptables", "-t", table, "-C", parent, "-j", chain], check=False)
    check = run(["iptables", "-t", table, "-C", parent, "-j", chain], check=False)
    if check.returncode != 0:
        run(["iptables", "-t", table, "-I", parent, "1", "-j", chain])


def configure_firewall(settings: list[dict], addresses: dict[str, str], ipv6_addresses: dict[str, list[str]]) -> None:
    # Dedicated chains only; never flush the host's firewall or change its default route.
    ensure_chain("nat", "BWPROXY_TCP", "PREROUTING")
    ensure_chain("filter", "BWPROXY_UDP", "FORWARD")
    ensure_chain("mangle", "BWPROXY_MARK", "PREROUTING")
    ensure_chain("filter", "BWPROXY_KILL", "FORWARD")
    ensure_chain("nat", "BWPROXY_AWG_NAT", "POSTROUTING")
    run(["ip6tables", "-N", "BWPROXY_V6"], check=False)
    run(["ip6tables", "-C", "FORWARD", "-j", "BWPROXY_V6"], check=False)
    if run(["ip6tables", "-C", "FORWARD", "-j", "BWPROXY_V6"], check=False).returncode != 0:
        run(["ip6tables", "-I", "FORWARD", "1", "-j", "BWPROXY_V6"])
    run(["ip6tables", "-F", "BWPROXY_V6"])
    for table, chain in (("nat", "BWPROXY_TCP"), ("filter", "BWPROXY_UDP"),
                         ("mangle", "BWPROXY_MARK"), ("filter", "BWPROXY_KILL"), ("nat", "BWPROXY_AWG_NAT")):
        run(["iptables", "-t", table, "-F", chain])

    # Local/private destinations must continue to work on Docker networks.
    private_v4 = ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16",
                  "127.0.0.0/8", "169.254.0.0/16")
    for subnet in private_v4:
        run(["iptables", "-t", "nat", "-A", "BWPROXY_TCP", "-d", subnet, "-j", "RETURN"])
        run(["iptables", "-t", "filter", "-A", "BWPROXY_UDP", "-d", subnet, "-j", "RETURN"])
        run(["iptables", "-t", "mangle", "-A", "BWPROXY_MARK", "-d", subnet, "-j", "RETURN"])
        run(["iptables", "-t", "filter", "-A", "BWPROXY_KILL", "-d", subnet, "-j", "RETURN"])

    for subnet in ("fc00::/7", "fe80::/10", "::1/128"):
        run(["ip6tables", "-A", "BWPROXY_V6", "-d", subnet, "-j", "RETURN"])
    desired_awg: dict[str, dict[str, int]] = {}
    for item in settings:
        ip = addresses.get(item["server_uuid"])
        if not ip or item["mode"] == "off":
            continue
        # IPv6 policy routing is not implemented yet: block external IPv6 to prevent bypass.
        for ipv6 in ipv6_addresses.get(item["server_uuid"], []):
            run(["ip6tables", "-A", "BWPROXY_V6", "-s", ipv6, "-j", "DROP"])
        if item["mode"] == "socks5":
            port = server_port(item["server_uuid"])
            run(["iptables", "-t", "nat", "-A", "BWPROXY_TCP", "-s", ip, "-p", "tcp",
                 "-j", "REDIRECT", "--to-ports", str(port)])
            # SOCKS5 UDP support is not assumed. Fail closed instead of leaking UDP direct.
            run(["iptables", "-t", "filter", "-A", "BWPROXY_UDP", "-s", ip, "!", "-p", "tcp", "-j", "DROP"])
        elif item["mode"] == "amneziawg":
            mark, table_id = route_ids(item["server_uuid"])
            priority = 10000 + mark
            desired_awg[item["server_uuid"]] = {"mark": mark, "table": table_id, "priority": priority}
            run(["iptables", "-t", "mangle", "-A", "BWPROXY_MARK", "-s", ip, "-j", "MARK", "--set-mark", str(mark)])
            interface = item.get("awg_interface") or "awg0"
            run(["iptables", "-t", "nat", "-A", "BWPROXY_AWG_NAT", "-s", ip, "-o", interface, "-j", "MASQUERADE"])
            run(["iptables", "-t", "filter", "-A", "BWPROXY_KILL", "-s", ip, "!", "-o", interface, "-j", "DROP"])
            run(["ip", "route", "replace", "default", "dev", interface, "table", str(table_id)], check=False)
            rule = run(["ip", "rule", "show"], check=False).stdout
            if ("fwmark 0x%x" % mark not in rule.lower() and "fwmark %d" % mark not in rule) or ("lookup %d" % table_id not in rule and "table %d" % table_id not in rule):
                run(["ip", "rule", "add", "priority", str(priority),
                     "fwmark", str(mark), "table", str(table_id)], check=False)

    STATE.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    try:
        previous = json.loads(STATE.read_text())
    except (OSError, ValueError):
        previous = {}
    previous_awg = previous.get("awg_rules", {}) if isinstance(previous, dict) else {}
    for server_uuid, rule in previous_awg.items():
        if server_uuid in desired_awg:
            continue
        run(["ip", "rule", "del", "priority", str(rule["priority"]),
             "fwmark", str(rule["mark"]), "table", str(rule["table"])], check=False)
        run(["ip", "route", "flush", "table", str(rule["table"])], check=False)
    STATE.write_text(json.dumps({"awg_rules": desired_awg}, sort_keys=True))
    STATE.chmod(0o600)


def route_ids(server_uuid: str) -> tuple[int, int]:
    value = int(hashlib.sha256(server_uuid.encode()).hexdigest()[:6], 16)
    mark = 1000 + (value % 50000)
    table_id = 10000 + (value % 20000)
    return mark, table_id


def stop_stale_redsocks(active: set[str], processes: dict[str, subprocess.Popen]) -> None:
    for server_uuid in list(processes):
        proc = processes[server_uuid]
        if server_uuid not in active or proc.poll() is not None:
            if proc.poll() is None:
                proc.terminate()
                try:
                    proc.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    proc.kill()
            processes.pop(server_uuid, None)


def apply(settings: list[dict], processes: dict[str, subprocess.Popen], hashes: dict[str, str]) -> None:
    valid = []
    addresses: dict[str, str] = {}
    ipv6_addresses: dict[str, list[str]] = {}
    for item in settings:
        validate_setting(item)
        if item["mode"] == "off":
            continue
        ip, ipv6 = docker_addresses(item["server_uuid"])
        if not ip:
            LOG.warning("Container for server %s is not running; skipping until next poll", item["server_uuid"])
            continue
        addresses[item["server_uuid"]] = ip
        ipv6_addresses[item["server_uuid"]] = ipv6
        valid.append(item)

    socks_active = {x["server_uuid"] for x in valid if x["mode"] == "socks5"}
    stop_stale_redsocks(socks_active, processes)
    RUNTIME.mkdir(mode=0o700, parents=True, exist_ok=True)

    for item in valid:
        if item["mode"] != "socks5":
            continue
        server_uuid = item["server_uuid"]
        port = server_port(server_uuid)
        content = socks_config(item, port)
        digest = hashlib.sha256(content.encode()).hexdigest()
        if server_uuid in processes and processes[server_uuid].poll() is None and hashes.get(server_uuid) == digest:
            continue
        if server_uuid in processes:
            processes[server_uuid].terminate()
            try:
                processes[server_uuid].wait(timeout=3)
            except subprocess.TimeoutExpired:
                processes[server_uuid].kill()
        path = RUNTIME / (hashlib.sha256(server_uuid.encode()).hexdigest()[:24] + ".conf")
        path.write_text(content)
        path.chmod(0o600)
        proc = subprocess.Popen(["redsocks", "-c", str(path)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        processes[server_uuid] = proc
        hashes[server_uuid] = digest

    configure_firewall(valid, addresses, ipv6_addresses)


def fetch_desired(config: dict) -> dict:
    url = config["panel_url"].rstrip("/") + "/api/bwproxy/v1/nodes/" + str(config["node_id"]) + "/desired"
    request = urllib.request.Request(url, headers={
        "Authorization": "Bearer " + config["token"],
        "Accept": "application/json",
        "User-Agent": "bwproxy-agent/0.2.0",
    })
    with urllib.request.urlopen(request, timeout=int(config.get("timeout", 10))) as response:
        data = json.load(response)
    if data.get("schema_version") != 1 or str(data.get("node_id")) != str(config["node_id"]) or not isinstance(data.get("servers"), list):
        raise ValueError("invalid desired-state response")
    return data


def main() -> None:
    logging.basicConfig(level=os.environ.get("LOG_LEVEL", "INFO"),
                        format="%(asctime)s %(levelname)s %(message)s")
    config = json.loads(CONFIG.read_text())
    if not config.get("panel_url", "").startswith("https://"):
        raise SystemExit("panel_url must use HTTPS")
    if not config.get("node_id") or not config.get("token"):
        raise SystemExit("node_id and token are required")
    processes: dict[str, subprocess.Popen] = {}
    hashes: dict[str, str] = {}
    interval = max(5, min(int(config.get("poll_interval", 15)), 120))
    while True:
        try:
            desired = fetch_desired(config)
            apply(desired["servers"], processes, hashes)
        except Exception as exc:
            # Fail closed: the existing firewall rules remain in place if the panel is unreachable.
            LOG.error("Reconciliation failed: %s", exc)
        time.sleep(interval)


if __name__ == "__main__":
    main()
