#!/usr/bin/env python3
import argparse
import base64
import os
import socket
import struct
import threading
import time
import random
from concurrent.futures import ThreadPoolExecutor, as_completed

# ----------------------------- Config Defaults -----------------------------

MAINNET_MAGIC = b"\xf9\xbe\xb4\xd9"  # F9BEB4D9
PROTOCOL_VERSION = 70016              # Fine for modern Core; supports sendaddrv2 negotiation
SERVICE_NODE_NETWORK = 0              # we don't need to claim services here
USER_AGENT = b"/Satoshi:29.1.0/Knots:20250903/"
DEFAULT_PORT = 8333
NETWORK_ID_TORV3 = 0x04               # BIP155 network id for Tor v3
RECV_TIMEOUT = 8.0                    # seconds per socket recv
HANDSHAKE_TIMEOUT = 10.0              # total budget for handshake phase
SEND_TIMEOUT = 5.0
SOCKS_DEFAULT = ("127.0.0.1", 9050)
CONCURRENCY_DEFAULT = 20

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

def parse_host_port(line, default_port=DEFAULT_PORT):
    line = line.strip()
    if not line:
        return None
    if line.startswith("#"):
        return None
    if line.endswith(".onion"):
        return (line, default_port)
    if ".onion:" in line:
        host, port = line.rsplit(":", 1)
        return (host, int(port))
    # allow plain host:port too
    if ":" in line and not line.replace(":", "").endswith(".onion"):
        host, port = line.rsplit(":", 1)
        return (host, int(port))
    # fallback
    return (line, default_port)

def load_list(path, default_port=DEFAULT_PORT):
    out = []
    with open(path, "r", encoding="utf-8") as f:
        for line in f:
            p = parse_host_port(line, default_port)
            if p:
                out.append(p)
    return out

# ------------------ Tor v3 .onion -> BIP155 addr bytes ---------------------

def onion_v3_to_pubkey32(onion_host):
    """
    Convert 'abcdefghijklmnop...56chars.onion' to the 32-byte ed25519 pubkey
    required for BIP155 TORv3 addr bytes (drop checksum+version).
    """
    host = onion_host.strip().lower()
    if host.endswith(".onion"):
        host = host[:-6]
    # Tor v3: 56 base32 chars encode 35 bytes: [32-byte pubkey | 2-byte checksum | 1-byte version]
    if len(host) != 56:
        raise ValueError("Invalid v3 onion length (expected 56 base32 chars)")
    # base64.b32decode expects uppercase
    raw = base64.b32decode(host.upper())
    if len(raw) != 35:
        raise ValueError("Unexpected decoded length for onion address")
    pubkey32 = raw[:32]
    return pubkey32

# ----------------------------- SOCKS5 to Tor -------------------------------

def socks5_connect(sock, proxy_host, proxy_port, dest_host, dest_port, timeout=10.0):
    """
    Minimal SOCKS5 connect (no auth) through Tor. Tor accepts .onion hostnames.
    """
    sock.settimeout(timeout)
    sock.connect((proxy_host, proxy_port))
    # Greeting: ver=5, nmethods=1, method=0 (no auth)
    sock.sendall(b"\x05\x01\x00")
    resp = sock.recv(2)
    if len(resp) != 2 or resp[0] != 0x05 or resp[1] != 0x00:
        raise IOError("SOCKS5: no-auth not accepted")
    # CONNECT request
    # ver=5, cmd=1 (CONNECT), rsv=0, atyp=3 (domain)
    host_b = dest_host.encode("idna")
    if len(host_b) > 255:
        raise ValueError("Hostname too long for SOCKS5")
    req = b"\x05\x01\x00\x03" + bytes([len(host_b)]) + host_b + struct.pack(">H", dest_port)
    sock.sendall(req)
    # Reply: ver=5, rep=0 (succeeded), rsv=0, atyp, bnd.addr, bnd.port
    hdr = sock.recv(4)
    if len(hdr) != 4 or hdr[0] != 0x05 or hdr[1] != 0x00:
        rep = hdr[1] if len(hdr) >= 2 else None
        raise IOError(f"SOCKS5 CONNECT failed (rep={rep})")
    atyp = hdr[3]
    if atyp == 1:   # IPv4
        rest = sock.recv(4 + 2)
    elif atyp == 3: # domain
        ln = sock.recv(1)[0]
        rest = sock.recv(ln + 2)
    elif atyp == 4: # IPv6
        rest = sock.recv(16 + 2)
    else:
        raise IOError("SOCKS5: unknown ATYP in reply")
    return sock  # connected

# ------------------------ Bitcoin P2P Handshake ----------------------------

def build_version_payload(peer_host, peer_port):
    version = PROTOCOL_VERSION
    services = SERVICE_NODE_NETWORK
    timestamp = int(time.time())
    addr_recv_services = 0
    # For version message, addr fields are 16-byte IPs; use IPv6 :: with zeros.
    ip16 = b"\x00" * 16
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
    # read header 24 bytes
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
    # send version
    sock.sendall(msg(b"version", build_version_payload(peer_host, peer_port)))
    their_version = None
    sent_verack = False
    got_verack = False
    peer_supports_addrv2 = False
    t0 = time.time()
    # Loop until verack both ways or timeout
    while time.time() - t0 < HANDSHAKE_TIMEOUT and not (sent_verack and got_verack):
        try:
            cmd, payload = recv_msg(sock, timeout=RECV_TIMEOUT)
        except Exception:
            break
        if cmd == b"version":
            their_version = payload
            # respond with verack
            sock.sendall(msg(b"verack"))
            sent_verack = True
        elif cmd == b"verack":
            got_verack = True
        elif cmd == b"sendaddrv2":
            peer_supports_addrv2 = True
        # ignore other messages during handshake
    return peer_supports_addrv2

# ------------------------ ADDRv2 Message Builder ---------------------------

def build_addrv2_payload(onion_list, default_port=DEFAULT_PORT, ts=None):
    """
    onion_list: list of tuples (host, port) where host is *.onion
    """
    if ts is None:
        ts = int(time.time())
    entries = []
    for host, port in onion_list:
        pubkey32 = onion_v3_to_pubkey32(host)
        # time (u32 LE), services (varint), network id (u8), addr len (varint), addr bytes, port (u16 BE)
        ent = (
            struct.pack("<I", ts) +
            varint(0) +
            struct.pack("B", NETWORK_ID_TORV3) +
            varint(len(pubkey32)) +
            pubkey32 +
            struct.pack(">H", port if port else default_port)
        )
        entries.append(ent)
    payload = varint(len(entries)) + b"".join(entries)
    return payload

# ------------------------------ Helpers ------------------------------------

def chunk_list(lst, n):
    """Yield successive n-sized chunks from lst."""
    for i in range(0, len(lst), n):
        yield lst[i:i + n]

# ------------------------------ Worker -------------------------------------

def push_to_peer(peer, adv_list, socks_host, socks_port, timeout, default_port):
    host, port = peer
    label = f"{host}:{port}"
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        socks5_connect(s, socks_host, socks_port, host, port, timeout=timeout)
        s.settimeout(RECV_TIMEOUT)
        # handshake
        supports_v2 = do_handshake(s, host, port)
        if not supports_v2:
            log(f"[-] {label} does not advertise sendaddrv2; skipping")
            s.close()
            return (label, False, "no addrv2")

        total_sent = 0
        chunks = list(chunk_list(adv_list, 1000))
        for idx, chunk in enumerate(chunks, 1):
            payload = build_addrv2_payload(chunk, default_port=default_port, ts=int(time.time()))
            s.sendall(msg(b"addrv2", payload))
            total_sent += len(chunk)
            log(f"[+] Sent chunk {idx}/{len(chunks)} ({len(chunk)} entries) to {label}")
            time.sleep(0.2)  # small pacing delay

        # optional read
        try:
            s.settimeout(2.0)
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
    ap = argparse.ArgumentParser(description="Push onion addresses to peers via Bitcoin ADDRv2 (BIP155) over Tor SOCKS5.")
    ap.add_argument("--addrs", required=True, help="File with .onion addresses to ADVERTISE (one per line, optional :port).")
    ap.add_argument("--peers", required=True, help="File with .onion peers to CONNECT to and push the list (one per line, optional :port).")
    ap.add_argument("--socks", default=f"{SOCKS_DEFAULT[0]}:{SOCKS_DEFAULT[1]}", help="Tor SOCKS5 host:port (default 127.0.0.1:9050).")
    ap.add_argument("--concurrency", type=int, default=CONCURRENCY_DEFAULT, help="Parallel connections (default 20).")
    ap.add_argument("--timeout", type=float, default=HANDSHAKE_TIMEOUT, help="Per-connection connect/handshake timeout (sec).")
    ap.add_argument("--port-default", type=int, default=DEFAULT_PORT, help="Default port to advertise if not specified (default 8333).")
    args = ap.parse_args()

    socks_host, socks_port = args.socks.split(":")
    socks_port = int(socks_port)

    # Load lists
    adv = load_list(args.addrs, default_port=args.port_default)
    peers = load_list(args.peers, default_port=args.port_default)

    if not adv:
        log("No addresses to advertise. Exiting.")
        return
    if not peers:
        log("No peers to push to. Exiting.")
        return

    log(f"Loaded {len(adv)} addresses to advertise; {len(peers)} peers to contact.")

    # Filter/validate onion v3
    def is_onion(host):
        return host.endswith(".onion") and len(host.split(".onion")[0]) == 56
    adv = [(h, p) for (h, p) in adv if is_onion(h)]
    peers = [(h, p) for (h, p) in peers if h.endswith(".onion")]

    log(f"After filtering: {len(adv)} v3 onions to advertise; {len(peers)} onion peers to contact.")

    successes = 0
    with ThreadPoolExecutor(max_workers=max(1, args.concurrency)) as ex:
        futs = [
            ex.submit(push_to_peer, peer, adv, socks_host, socks_port, args.timeout, args.port_default)
            for peer in peers
        ]
        for fut in as_completed(futs):
            _, ok, _ = fut.result()
            if ok:
                successes += 1

    log(f"Done. Sent to {successes}/{len(peers)} peers.")

if __name__ == "__main__":
    main()

