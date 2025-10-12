#!/usr/bin/env python3
import argparse
import base64
import ipaddress
import socket
import struct
import time
import random
from concurrent.futures import ThreadPoolExecutor, as_completed

# ----------------------------- Config Defaults -----------------------------

MAINNET_MAGIC = b"\xf9\xbe\xb4\xd9"  # F9BEB4D9
PROTOCOL_VERSION = 70016              # modern Core; negotiate sendaddrv2
SERVICE_NODE_NETWORK = 0
USER_AGENT = b"/Satoshi:30.0.0/"
DEFAULT_PORT = 8333
NETWORK_ID_TORV3 = 0x04               # BIP155 Tor v3
RECV_TIMEOUT = 8.0
HANDSHAKE_TIMEOUT = 10.0
SOCKS_DEFAULT = ("127.0.0.1", 9050)
CONCURRENCY_DEFAULT = 20
CHUNK_SIZE_DEFAULT = 1000

# ----------------------------- Utilities -----------------------------------

def log(msg):
    ts = time.strftime("%H:%M:%S")
    print(f"[{ts}] {msg}", flush=True)

def sha256d(b):
    import hashlib
    return hashlib.sha256(hashlib.sha256(b).digest()).digest()

def varint(n):
    if n < 0xfd:
        return struct.pack("<B", n)
    elif n <= 0xffff:
        return b"\xfd" + struct.pack("<H", n)
    elif n <= 0xffffffff:
        return b"\xfe" + struct.pack("<I", n)
    else:
        return b"\xff" + struct.pack("<Q", n)

def varstr(b):
    return varint(len(b)) + b

def pad_command(cmd):
    return cmd + (b"\x00" * (12 - len(cmd)))

def msg(command, payload=b""):
    header = MAINNET_MAGIC + pad_command(command) + struct.pack("<I", len(payload)) + sha256d(payload)[:4]
    return header + payload

# ----------------------------- Address parsing -----------------------------

def _strip_brackets_ipv6(host):
    # Accept [2001:db8::1] or 2001:db8::1
    if host.startswith("[") and host.endswith("]"):
        return host[1:-1]
    return host

def parse_host_port(line, default_port=DEFAULT_PORT):
    """
    Accepts:
      - v3 .onion (with optional :port)
      - IPv4 (with optional :port)
      - IPv6 with or without brackets (with optional :port)
    Returns tuple: (kind, host, port)
      kind in {"onion","ipv4","ipv6"}
    """
    s = line.strip()
    if not s or s.startswith("#"):
        return None

    # .onion
    if s.endswith(".onion") or s.lower().endswith(".onion"):
        return ("onion", s, default_port)
    if ".onion:" in s.lower():
        host, port = s.rsplit(":", 1)
        return ("onion", host, int(port))

    # Bracketed IPv6: [addr]:port OR [addr]
    if s.startswith("["):
        # Expect [ipv6]:port or [ipv6]
        if "]:" in s:
            host, port = s.split("]:", 1)
            host = host[1:]
            return ("ipv6", host, int(port))
        elif s.endswith("]"):
            host = s[1:-1]
            return ("ipv6", host, default_port)

    # Split by last colon; if RHS numeric it's a port
    host = s
    port = None
    if ":" in s:
        # If it's an IPv6 without brackets, it will have many colons; try to parse as IP first
        try:
            ipaddress.IPv6Address(_strip_brackets_ipv6(s))
            # IPv6 without explicit port
            return ("ipv6", _strip_brackets_ipv6(s), default_port)
        except ValueError:
            # maybe host:port (IPv4 or hostname)
            parts = s.rsplit(":", 1)
            if len(parts) == 2 and parts[1].isdigit():
                host, port = parts[0], int(parts[1])
            else:
                host, port = s, default_port
    if port is None:
        port = default_port

    # IPv4?
    try:
        ipaddress.IPv4Address(host)
        return ("ipv4", host, port)
    except ValueError:
        pass

    # IPv6?
    try:
        ipaddress.IPv6Address(_strip_brackets_ipv6(host))
        return ("ipv6", _strip_brackets_ipv6(host), port)
    except ValueError:
        pass

    # Fallback: treat as hostname (IPv4-style connect); if it's .onion we’d have caught earlier
    return ("ipv4", host, port)

def load_peer_list(path, default_port=DEFAULT_PORT):
    out = []
    with open(path, "r", encoding="utf-8") as f:
        for line in f:
            p = parse_host_port(line, default_port)
            if p:
                out.append(p)
    return out

def load_onion_advertise_list(path, default_port=DEFAULT_PORT):
    out = []
    with open(path, "r", encoding="utf-8") as f:
        for line in f:
            s = line.strip()
            if not s or s.startswith("#"):
                continue
            if s.endswith(".onion"):
                out.append((s, default_port))
            elif ".onion:" in s:
                h, p = s.rsplit(":", 1)
                out.append((h, int(p)))
    return out

def chunk_list(lst, n):
    for i in range(0, len(lst), n):
        yield lst[i:i + n]

# ------------------ Tor v3 .onion -> BIP155 addr bytes ---------------------

def onion_v3_to_pubkey32(onion_host):
    """
    Convert 'abcdefghijkl...56chars.onion' -> 32-byte ed25519 pubkey (drop checksum+version).
    """
    host = onion_host.strip().lower()
    if host.endswith(".onion"):
        host = host[:-6]
    if len(host) != 56:
        raise ValueError("Invalid v3 onion length (expected 56 base32 chars)")
    raw = base64.b32decode(host.upper())
    if len(raw) != 35:
        raise ValueError("Unexpected decoded length for onion address")
    return raw[:32]

# ----------------------------- SOCKS5 to Tor -------------------------------

def socks5_connect(sock, proxy_host, proxy_port, dest_host, dest_port, timeout=10.0):
    sock.settimeout(timeout)
    sock.connect((proxy_host, proxy_port))
    # Greeting: ver=5, nmethods=1, method=0 (no auth)
    sock.sendall(b"\x05\x01\x00")
    resp = sock.recv(2)
    if len(resp) != 2 or resp[0] != 0x05 or resp[1] != 0x00:
        raise IOError("SOCKS5: no-auth not accepted")
    # CONNECT request (domain name or IP string; Tor accepts both)
    host_b = dest_host.encode("idna")
    if len(host_b) > 255:
        raise ValueError("Hostname too long for SOCKS5")
    req = b"\x05\x01\x00\x03" + bytes([len(host_b)]) + host_b + struct.pack(">H", dest_port)
    sock.sendall(req)
    # Reply
    hdr = sock.recv(4)
    if len(hdr) != 4 or hdr[0] != 0x05 or hdr[1] != 0x00:
        rep = hdr[1] if len(hdr) >= 2 else None
        raise IOError(f"SOCKS5 CONNECT failed (rep={rep})")
    atyp = hdr[3]
    if atyp == 1:   # IPv4
        _ = sock.recv(4 + 2)
    elif atyp == 3: # domain
        ln = sock.recv(1)[0]
        _ = sock.recv(ln + 2)
    elif atyp == 4: # IPv6
        _ = sock.recv(16 + 2)
    else:
        raise IOError("SOCKS5: unknown ATYP in reply")
    return sock

# ------------------------ Bitcoin P2P Handshake ----------------------------

def build_version_payload(peer_host, peer_port):
    version = PROTOCOL_VERSION
    services = SERVICE_NODE_NETWORK
    timestamp = int(time.time())
    addr_recv_services = 0
    ip16 = b"\x00" * 16  # :: (unspecified)
    port_be = struct.pack(">H", peer_port)
    addr_yours = struct.pack("<Q", addr_recv_services) + ip16 + port_be
    addr_from = struct.pack("<Q", 0) + ip16 + port_be
    nonce = random.getrandbits(64)
    ua = varstr(USER_AGENT)
    start_height = 0
    relay = b"\x00"
    payload = (
        struct.pack("<i", version) +
        struct.pack("<Q", services) +
        struct.pack("<q", timestamp) +
        addr_yours +
        addr_from +
        struct.pack("<Q", nonce) +
        ua +
        struct.pack("<i", start_height) +
        relay
    )
    return payload

def recv_msg(sock, timeout=RECV_TIMEOUT):
    sock.settimeout(timeout)
    header = b""
    while len(header) < 24:
        chunk = sock.recv(24 - len(header))
        if not chunk:
            raise IOError("Connection closed while reading header")
        header += chunk
    if header[:4] != MAINNET_MAGIC:
        raise IOError("Bad magic")
    command = header[4:16].rstrip(b"\x00")
    length = struct.unpack("<I", header[16:20])[0]
    checksum = header[20:24]
    payload = b""
    while len(payload) < length:
        chunk = sock.recv(min(4096, length - len(payload)))
        if not chunk:
            raise IOError("Connection closed while reading payload")
        payload += chunk
    if sha256d(payload)[:4] != checksum:
        raise IOError("Bad checksum")
    return command, payload

def do_handshake(sock, peer_host, peer_port):
    # Send version
    sock.sendall(msg(b"version", build_version_payload(peer_host, peer_port)))
    sent_verack = False
    got_verack = False
    peer_supports_addrv2 = False
    saw_version = False
    t0 = time.time()
    while time.time() - t0 < HANDSHAKE_TIMEOUT and not (sent_verack and got_verack):
        try:
            cmd, payload = recv_msg(sock, timeout=RECV_TIMEOUT)
        except Exception:
            break
        if cmd == b"version":
            saw_version = True
            sock.sendall(msg(b"verack"))
            sent_verack = True
        elif cmd == b"verack":
            got_verack = True
        elif cmd == b"sendaddrv2":
            peer_supports_addrv2 = True
    log(f"[~] Handshake: saw version={saw_version}, got verack={got_verack}, sendaddrv2={peer_supports_addrv2}")
    return peer_supports_addrv2

# ------------------------ ADDRv2 Message Builder ---------------------------

def build_addrv2_payload(onion_list, default_port=DEFAULT_PORT, ts=None):
    """
    onion_list: list[(host, port)] for *.onion (v3)
    """
    if ts is None:
        ts = int(time.time())
    entries = []
    for host, port in onion_list:
        pubkey32 = onion_v3_to_pubkey32(host)
        ent = (
            struct.pack("<I", ts) +   # time
            varint(0) +               # services
            struct.pack("B", NETWORK_ID_TORV3) +
            varint(len(pubkey32)) +
            pubkey32 +
            struct.pack(">H", port if port else default_port)
        )
        entries.append(ent)
    return varint(len(entries)) + b"".join(entries)

# ------------------------------ Verification -------------------------------

def send_getaddr(sock):
    sock.sendall(msg(b"getaddr"))

def read_varint(b, off):
    first = b[off]
    off += 1
    if first < 0xfd:
        return first, off
    elif first == 0xfd:
        val = struct.unpack_from("<H", b, off)[0]; off += 2; return val, off
    elif first == 0xfe:
        val = struct.unpack_from("<I", b, off)[0]; off += 4; return val, off
    else:
        val = struct.unpack_from("<Q", b, off)[0]; off += 8; return val, off

def _ipv6_bytes_to_text(addr_bytes):
    return ":".join(f"{addr_bytes[i]<<8 | addr_bytes[i+1]:x}" for i in range(0, 16, 2))

def parse_legacy_addr(payload):
    ipv4 = set()
    ipv6 = set()
    off = 0
    try:
        count, off = read_varint(payload, off)
    except Exception:
        return ipv4, ipv6
    for _ in range(count):
        if off + 4 + 8 + 16 + 2 > len(payload):
            break
        off += 4  # time
        off += 8  # services
        ip16 = payload[off:off+16]; off += 16
        off += 2  # port
        if ip16[:10] == b"\x00"*10 and ip16[10:12] == b"\xff\xff":
            ipv4.add(".".join(str(b) for b in ip16[12:]))
        else:
            ipv6.add(_ipv6_bytes_to_text(ip16))
    return ipv4, ipv6

def collect_addrs_any(sock, duration_s=3.0):
    """
    Collect both addrv2 and legacy addr replies for up to duration_s seconds.
    Returns:
      torv3_pubkeys: set(bytes)
      ipv4_addrs:    set(str)
      ipv6_addrs:    set(str)
      flags:         dict: saw_addrv2_reply, saw_legacy_addr_reply
    """
    deadline = time.time() + duration_s
    seen_torv3 = set()
    seen_ipv4 = set()
    seen_ipv6 = set()
    saw_addrv2_reply = False
    saw_legacy_addr_reply = False

    while time.time() < deadline:
        remaining = max(0.05, deadline - time.time())
        try:
            cmd, payload = recv_msg(sock, timeout=remaining)
        except Exception:
            break

        if cmd == b"addrv2":
            saw_addrv2_reply = True
            off = 0
            try:
                count, off = read_varint(payload, off)
            except Exception:
                continue
            for _ in range(count):
                if off + 4 > len(payload): break
                off += 4  # timestamp
                try:
                    _, off = read_varint(payload, off)
                except Exception:
                    break
                if off + 1 > len(payload): break
                netid = payload[off]; off += 1
                try:
                    addrlen, off = read_varint(payload, off)
                except Exception:
                    break
                if off + addrlen + 2 > len(payload): break
                addr_bytes = payload[off:off+addrlen]; off += addrlen
                off += 2  # port
                if netid == 0x04 and addrlen == 32:
                    seen_torv3.add(addr_bytes)
                elif netid == 0x01 and addrlen == 4:
                    seen_ipv4.add(".".join(str(b) for b in addr_bytes))
                elif netid == 0x02 and addrlen == 16:
                    seen_ipv6.add(_ipv6_bytes_to_text(addr_bytes))

        elif cmd == b"addr":
            saw_legacy_addr_reply = True
            ipv4_set, ipv6_set = parse_legacy_addr(payload)
            seen_ipv4 |= ipv4_set
            seen_ipv6 |= ipv6_set

    log(f"[=] Verification results:"
        f" Tor v3={len(seen_torv3)}, IPv4={len(seen_ipv4)}, IPv6={len(seen_ipv6)};"
        f" saw addrv2 reply={saw_addrv2_reply}, saw legacy addr reply={saw_legacy_addr_reply}")
    flags = {
        "saw_addrv2_reply": saw_addrv2_reply,
        "saw_legacy_addr_reply": saw_legacy_addr_reply,
    }
    return seen_torv3, seen_ipv4, seen_ipv6, flags

# ------------------------------ Connect helpers ----------------------------

def connect_peer(kind, host, port, socks_host, socks_port, timeout):
    """
    Returns a connected socket for the given peer kind.
    kind: 'onion' uses SOCKS5; 'ipv4' and 'ipv6' use direct connect.
    """
    if kind == "onion":
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        return socks5_connect(s, socks_host, socks_port, host, port, timeout=timeout)
    elif kind == "ipv4":
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(timeout)
        s.connect((host, port))
        return s
    elif kind == "ipv6":
        s = socket.socket(socket.AF_INET6, socket.SOCK_STREAM)
        s.settimeout(timeout)
        s.connect((host, port, 0, 0))
        return s
    else:
        raise ValueError(f"Unknown peer kind {kind}")

# ------------------------------ Worker -------------------------------------

def push_to_peer(peer, adv_list, socks_host, socks_port, timeout, default_port,
                 chunk_size, verify=False, verify_wait=0.0, verify_duration=3.0):
    kind, host, port = peer
    label = f"{host}:{port} ({kind})"
    try:
        s = connect_peer(kind, host, port, socks_host, socks_port, timeout)
        s.settimeout(RECV_TIMEOUT)

        supports_v2 = do_handshake(s, host, port)
        if not supports_v2:
            log(f"[-] {label} does not advertise sendaddrv2; skipping (peer may only serve legacy 'addr').")
            s.close()
            return (label, False, "no addrv2")

        total_sent = 0
        chunks = list(chunk_list(adv_list, chunk_size))
        for idx, chunk in enumerate(chunks, 1):
            payload = build_addrv2_payload(chunk, default_port=default_port, ts=int(time.time()))
            s.sendall(msg(b"addrv2", payload))
            total_sent += len(chunk)
            log(f"[+] {label}: sent chunk {idx}/{len(chunks)} ({len(chunk)} entries)")
            time.sleep(0.2)  # small pacing delay

        if verify:
            my_pubkeys = { onion_v3_to_pubkey32(h) for (h, _) in adv_list }
            if verify_wait > 0:
                time.sleep(verify_wait)
            try:
                send_getaddr(s)
                tor_hits, ipv4_hits, ipv6_hits, flags = collect_addrs_any(s, duration_s=verify_duration)
                matched = len(my_pubkeys.intersection(tor_hits))
                pct = (matched / len(my_pubkeys)) * 100 if my_pubkeys else 0.0
                log(f"[=] {label}: Tor v3 matched {matched}/{len(my_pubkeys)} ({pct:.1f}%). "
                    f"IPv4 count={len(ipv4_hits)}, IPv6 count={len(ipv6_hits)}. "
                    f"Replies: addrv2={flags['saw_addrv2_reply']}, legacy-addr={flags['saw_legacy_addr_reply']}")
                if not flags["saw_addrv2_reply"] and not flags["saw_legacy_addr_reply"]:
                    log(f"[!] {label}: No address replies observed after getaddr in {verify_duration}s window "
                        f"(peer may be block-relay-only, rate-limiting, or filtering addrs).")
            except Exception as e:
                log(f"[~] {label}: verification failed: {e}")

        try:
            s.settimeout(1.0)
            _ = s.recv(1)
        except Exception:
            pass
        s.close()
        return (label, True, f"sent {total_sent}")
    except Exception as e:
        log(f"[!] {label} failed: {e}")
        return (label, False, str(e))

# ------------------------------ Main ---------------------------------------

def main():
    ap = argparse.ArgumentParser(
        description="Push .onion addresses to Tor/IPv4/IPv6 peers via Bitcoin ADDRv2 (BIP155), with optional verification and legacy-addr parsing."
    )
    ap.add_argument("--addrs", required=True, help="File with .onion addresses to ADVERTISE (one per line, optional :port).")
    ap.add_argument("--peers", required=True, help="File with peers (.onion / IPv4 / IPv6, optional :port).")
    ap.add_argument("--socks", default=f"{SOCKS_DEFAULT[0]}:{SOCKS_DEFAULT[1]}", help="Tor SOCKS5 host:port for .onion peers (default 127.0.0.1:9050).")
    ap.add_argument("--concurrency", type=int, default=CONCURRENCY_DEFAULT, help="Parallel connections (default 20).")
    ap.add_argument("--timeout", type=float, default=HANDSHAKE_TIMEOUT, help="Per-connection connect/handshake timeout (sec).")
    ap.add_argument("--port-default", type=int, default=DEFAULT_PORT, help="Default advertised/listen port if not specified (default 8333).")
    ap.add_argument("--chunk-size", type=int, default=CHUNK_SIZE_DEFAULT, help="Max addresses per addrv2 message (default 1000).")
    ap.add_argument("--verify", action="store_true", help="After sending, issue getaddr and sample addrv2/addr replies.")
    ap.add_argument("--verify-wait", type=float, default=0.0, help="Seconds to wait before getaddr (lets peer file entries).")
    ap.add_argument("--verify-duration", type=float, default=3.0, help="Seconds to listen for address replies after getaddr.")
    args = ap.parse_args()

    socks_host, socks_port = args.socks.split(":")
    socks_port = int(socks_port)

    adv = load_onion_advertise_list(args.addrs, default_port=args.port_default)
    peers = load_peer_list(args.peers, default_port=args.port_default)

    if not adv:
        log("No .onion addresses to advertise. Exiting.")
        return
    if not peers:
        log("No peers to push to. Exiting.")
        return

    # Filter advertised list to Tor v3 onions only
    def is_onion_v3(host):
        return host.endswith(".onion") and len(host[:-6]) == 56

    adv = [(h, p) for (h, p) in adv if is_onion_v3(h)]

    # Summaries
    n_onion = sum(1 for k,_,_ in peers if k == "onion")
    n_v4    = sum(1 for k,_,_ in peers if k == "ipv4")
    n_v6    = sum(1 for k,_,_ in peers if k == "ipv6")

    log(f"Loaded {len(adv)} v3 onions to advertise; peers: onion={n_onion}, ipv4={n_v4}, ipv6={n_v6}.")
    log(f"Chunk size: {args.chunk_size} | Concurrency: {args.concurrency} | Verify: {args.verify} (wait={args.verify_wait}s, duration={args.verify_duration}s)")

    successes = 0
    with ThreadPoolExecutor(max_workers=max(1, args.concurrency)) as ex:
        futs = [
            ex.submit(
                push_to_peer,
                peer,
                adv,
                socks_host,
                socks_port,
                args.timeout,
                args.port_default,
                args.chunk_size,
                args.verify,
                args.verify_wait,
                args.verify_duration,
            )
            for peer in peers
        ]
        for fut in as_completed(futs):
            _, ok, _ = fut.result()
            if ok:
                successes += 1

    log(f"Done. Sent to {successes}/{len(peers)} peers.")

if __name__ == "__main__":
    main()
