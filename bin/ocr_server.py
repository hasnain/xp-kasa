#!/usr/bin/env python3
"""Reads receipt photos for the local page. Listens on a socket the web server can call."""

import os
import socket
import subprocess

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
UPLOADS = os.path.realpath(os.path.join(ROOT, "storage", "uploads"))
BINARY = os.path.join(ROOT, "bin", "ocr")
SOCKET_PATH = "/tmp/xpunch-ocr.sock"


def allowed(path):
    real = os.path.realpath(path)
    prefix = UPLOADS + os.sep
    return real.startswith(prefix) and os.path.isfile(real)


def main():
    if os.path.exists(SOCKET_PATH):
        os.remove(SOCKET_PATH)
    server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    server.bind(SOCKET_PATH)
    os.chmod(SOCKET_PATH, 0o666)
    server.listen(4)
    while True:
        conn, _ = server.accept()
        try:
            data = b""
            while b"\n" not in data and len(data) < 4096:
                chunk = conn.recv(1024)
                if not chunk:
                    break
                data += chunk
            image = data.split(b"\n", 1)[0].decode("utf-8", "replace").strip()
            if not allowed(image):
                conn.sendall(b"ERR path not allowed\n")
                continue
            result = subprocess.run(
                [BINARY, image],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=60,
            )
            if result.returncode != 0:
                err = result.stderr.decode("utf-8", "replace").strip() or "reader failed"
                conn.sendall(("ERR " + err + "\n").encode("utf-8"))
                continue
            conn.sendall(result.stdout)
        except Exception as exc:
            try:
                conn.sendall(("ERR " + str(exc) + "\n").encode("utf-8"))
            except Exception:
                pass
        finally:
            conn.close()


if __name__ == "__main__":
    main()
